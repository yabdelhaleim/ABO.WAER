<?php

namespace Tests\Feature;

use App\Enums\CampaignAudience;
use App\Enums\CampaignStatus;
use App\Enums\OrderStatus;
use App\Jobs\SendCampaignJob;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Admin\CampaignDeliveryService;
use App\Services\Admin\CampaignService;
use App\Services\CustomerSegmentationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Covers the delivery run: the queue handover, the status lifecycle, and
 * the chunked walk that keeps sent_count honest.
 *
 * The database effects of handle() are asserted by calling it directly
 * rather than through a fake queue, so the real segmentation query and the
 * real chunked loop are what gets exercised.
 */
class SendCampaignJobTest extends TestCase
{
    use RefreshDatabase;

    // ─── Queue handover ───────────────────────────────────────────────

    public function test_dispatch_campaign_pushes_the_job_and_marks_it_active(): void
    {
        Queue::fake();

        $campaign = Campaign::factory()->draft()->create();

        app(CampaignService::class)->dispatchCampaign($campaign);

        Queue::assertPushed(SendCampaignJob::class, function (SendCampaignJob $job) use ($campaign): bool {
            return $job->campaign->is($campaign);
        });

        $this->assertSame(CampaignStatus::Active, $campaign->fresh()->status);
    }

    public function test_dispatch_campaign_refuses_an_ended_campaign(): void
    {
        Queue::fake();

        $campaign = Campaign::factory()->ended()->create();

        $this->expectException(ValidationException::class);

        try {
            app(CampaignService::class)->dispatchCampaign($campaign);
        } finally {
            Queue::assertNothingPushed();
            $this->assertSame(CampaignStatus::Ended, $campaign->fresh()->status);
        }
    }

    public function test_dispatch_campaign_refuses_a_campaign_already_in_flight(): void
    {
        Queue::fake();

        $campaign = Campaign::factory()->active()->create();

        $this->expectException(ValidationException::class);

        try {
            app(CampaignService::class)->dispatchCampaign($campaign);
        } finally {
            Queue::assertNothingPushed();
        }
    }

    public function test_dispatch_campaign_is_not_repeatable(): void
    {
        Queue::fake();

        $campaign = Campaign::factory()->draft()->create();
        $service = app(CampaignService::class);

        $service->dispatchCampaign($campaign);

        // The first run has already flipped the campaign to active, so a
        // second click must be refused rather than stacking another run.
        $this->expectException(ValidationException::class);

        try {
            $service->dispatchCampaign($campaign);
        } finally {
            Queue::assertPushed(SendCampaignJob::class, 1);
        }
    }

    // ─── Status lifecycle ─────────────────────────────────────────────

    public function test_job_drive_a_draft_to_active_and_finally_ended(): void
    {
        Customer::factory()->count(3)->create();

        $campaign = Campaign::factory()->draft()->create();
        $this->runJob($campaign);

        $fresh = $campaign->fresh();

        $this->assertSame(CampaignStatus::Ended, $fresh->status);
        $this->assertNotNull($fresh->sent_at);
        $this->assertSame(3, $fresh->sent_count);
    }

    public function test_job_drive_a_scheduled_campaign_to_active_and_finally_ended(): void
    {
        Customer::factory()->count(2)->create();

        $campaign = Campaign::factory()->scheduled()->create();
        $this->runJob($campaign);

        $fresh = $campaign->fresh();

        $this->assertSame(CampaignStatus::Ended, $fresh->status);
        $this->assertNotNull($fresh->sent_at);
        $this->assertSame(2, $fresh->sent_count);
    }

    public function test_job_skips_campaigns_that_already_ended(): void
    {
        Customer::factory()->count(3)->create();

        $campaign = Campaign::factory()->ended()->create([
            'sent_count' => 99,
            'sent_at' => now()->subWeek(),
        ]);

        $this->fakeDeliveryService($deliveries);

        $this->runJob($campaign);

        $fresh = $campaign->fresh();

        $this->assertSame(CampaignStatus::Ended, $fresh->status);
        $this->assertSame(99, $fresh->sent_count, 'An ended campaign must not be re-sent.');
        $this->assertCount(0, $deliveries, 'No customer should be messaged for an ended campaign.');
    }

    // ─── Chunking and counters ────────────────────────────────────────

    public function test_job_walks_an_audience_larger_than_one_chunk_exactly_once(): void
    {
        // 150 customers against a chunk size of 100 forces two chunks and a
        // half, which is where off-by-one chunking shows up.
        Customer::factory()->count(150)->create();

        $campaign = Campaign::factory()->draft()->create([
            'audience' => CampaignAudience::All,
        ]);

        $this->fakeDeliveryService($deliveries);

        $this->runJob($campaign);

        $fresh = $campaign->fresh();

        $this->assertSame(150, $fresh->sent_count);
        $this->assertCount(150, $deliveries, 'Every customer should be delivered to exactly once.');
        $this->assertSame(
            150,
            count(array_unique(array_column($deliveries, 'customer_id'))),
            'chunkById must not deliver the same customer twice.'
        );
        $this->assertSame(CampaignStatus::Ended, $fresh->status);
    }

    public function test_job_only_counts_customers_inside_the_campaign_audience(): void
    {
        $target = Customer::factory()->create();
        $customerFactory = Customer::factory();

        // A VIP campaign must not reach the other two accounts.
        $customerFactory->create();
        $customerFactory->create();

        $campaign = Campaign::factory()->draft()->create([
            'audience' => CampaignAudience::Vip,
        ]);

        $this->placeDeliveredOrder($target, 2500.00);

        $this->fakeDeliveryService($deliveries);

        $this->runJob($campaign);

        $this->assertSame(1, $campaign->fresh()->sent_count);
        $this->assertSame([$target->id], array_column($deliveries, 'customer_id'));
    }

    public function test_job_leaves_the_count_at_zero_for_an_empty_audience(): void
    {
        $campaign = Campaign::factory()->draft()->create([
            'audience' => CampaignAudience::Vip,
        ]);

        $this->fakeDeliveryService($deliveries);

        $this->runJob($campaign);

        $fresh = $campaign->fresh();

        $this->assertSame(0, $fresh->sent_count);
        $this->assertCount(0, $deliveries);
        $this->assertSame(CampaignStatus::Ended, $fresh->status);
    }

    // ─── Retry configuration ──────────────────────────────────────────

    public function test_job_declares_retry_and_timeout_budgets(): void
    {
        $job = new SendCampaignJob(Campaign::factory()->make());

        $this->assertSame(3, $job->tries);
        $this->assertSame(600, $job->timeout);
        $this->assertSame([60, 300], $job->backoff);
        $this->assertSame(100, $job->chunkSize);
    }

    // ─── Helpers ──────────────────────────────────────────────────────

    private function runJob(Campaign $campaign): void
    {
        (new SendCampaignJob($campaign))->handle(
            app(CustomerSegmentationService::class),
            app(CampaignDeliveryService::class),
        );
    }

    /**
     * Swap in a recording delivery service.
     *
     * @param  array<int, array{campaign_id: int, customer_id: int}>  $deliveries
     */
    private function fakeDeliveryService(?array &$deliveries = null): void
    {
        $deliveries = [];

        $this->mock(CampaignDeliveryService::class, function (MockInterface $mock) use (&$deliveries): void {
            $mock->shouldReceive('deliverToCustomer')
                ->andReturnUsing(function (Campaign $campaign, Customer $customer) use (&$deliveries): void {
                    $deliveries[] = [
                        'campaign_id' => $campaign->id,
                        'customer_id' => $customer->id,
                    ];
                });
        });
    }

    private function placeDeliveredOrder(Customer $customer, float $total): void
    {
        Order::factory()->create([
            'customer_id' => $customer->id,
            'status' => OrderStatus::Delivered,
            'total' => $total,
        ]);
    }
}

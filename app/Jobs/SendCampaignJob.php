<?php

namespace App\Jobs;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Services\Admin\CampaignDeliveryService;
use App\Services\CustomerSegmentationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Delivers one campaign to its whole audience from the queue.
 *
 * Dispatched by Admin\CampaignService::dispatchCampaign(), which has already
 * flipped the campaign to 'active' — that status is what stops a second
 * send-now from stacking another run on top of this one.
 *
 * The audience is walked with chunkById rather than get() so a campaign
 * aimed at a large segment holds one chunk of customers in memory at a time
 * instead of the entire audience, and so a retry picks up from a primary key
 * rather than replaying from the top. sent_count is bumped once per chunk
 * with the number actually delivered, so a partial failure leaves the
 * counter reflecting real sends rather than an optimistic total.
 *
 * Chunking means the sent_count increment must not be per-customer: one
 * UPDATE per chunk keeps a 50,000-recipient campaign to 500 writes.
 */
class SendCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Transient blips (a dropped mail relay, a brief database lock) get two
     * more attempts before the campaign is flagged failed.
     */
    public int $tries = 3;

    /**
     * A segment large enough to need real time still has to beat the
     * worker's own --timeout, or the job would be killed mid-walk.
     */
    public int $timeout = 600;

    /**
     * Seconds to wait before the second and third attempts. The first retry
     * is quick enough for a blip; the second waits out a longer outage.
     *
     * @var array<int, int>
     */
    public array $backoff = [60, 300];

    /**
     * Customers held in memory per batch.
     */
    public int $chunkSize = 100;

    public function __construct(public Campaign $campaign) {}

    /**
     * Walk the audience in chunks, deliver, then close the campaign out.
     *
     * @throws \Throwable
     */
    public function handle(
        CustomerSegmentationService $segmentationService,
        CampaignDeliveryService $deliveryService,
    ): void {
        $campaign = $this->campaign->fresh();

        // Re-read rather than trust the serialized copy: if an admin deleted
        // or already closed the campaign between dispatch and pickup, there
        // is nothing to deliver and no status to write.
        if ($campaign === null || $campaign->status === CampaignStatus::Ended) {
            return;
        }

        $campaign->update([
            'status' => CampaignStatus::Active,
            'sent_at' => now(),
        ]);

        $segmentationService
            ->getAudienceQuery($campaign->audience)
            ->chunkById($this->chunkSize, function (Collection $customers) use ($campaign, $deliveryService): void {
                $delivered = 0;

                foreach ($customers as $customer) {
                    $deliveryService->deliverToCustomer($campaign, $customer);
                    $delivered++;
                }

                if ($delivered > 0) {
                    $campaign->increment('sent_count', $delivered);
                }
            });

        $campaign->update(['status' => CampaignStatus::Ended]);
    }
}

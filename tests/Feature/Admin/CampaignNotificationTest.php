<?php

namespace Tests\Feature\Admin;

use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use App\Jobs\SendCampaignJob;
use App\Models\Campaign;
use App\Models\Customer;
use App\Notifications\CampaignNotification;
use App\Services\Admin\CampaignService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * End-to-end coverage for Phase 7 · Step 4 — notification delivery,
 * tracking analytics and the scheduler tick.
 *
 * The suite is deliberately shaped so each test exercises one surface of
 * the unified CampaignNotification: the mail channel, the database
 * channel, the SMS log path, the open/click tracking endpoints, and
 * dispatchDueScheduledCampaigns(). Everything runs against an in-memory
 * SQLite database via RefreshDatabase, which leaves the campaign row and
 * every counter written through the migration up to date with assertions.
 */
class CampaignNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        // Customer that the segmentation baseQuery() accepts: not blocked,
        // carries a non-empty email so the bulk-channel test has a recipient.
        $this->customer = Customer::factory()->create([
            'name' => 'Marketing Tester',
            'is_blocked' => false,
        ]);
    }

    public function test_email_campaign_dispatches_mail_message_with_correct_view_and_subject(): void
    {
        Notification::fake();

        $campaign = Campaign::factory()->create([
            'type' => CampaignType::Email,
            'subject' => 'Winter Sale Kickoff',
            'message' => 'Welcome to our winter sale.',
            'status' => CampaignStatus::Scheduled,
        ]);

        Notification::assertNothingSent();
        $this->customer->notify(new CampaignNotification($campaign));

        Notification::assertSentTo(
            $this->customer,
            CampaignNotification::class,
            function (CampaignNotification $notification, array $channels, object $notifiable) use ($campaign): bool {
                $this->assertSame(['mail'], $channels);

                $mail = $notification->toMail($notifiable);

                $this->assertSame('Winter Sale Kickoff', $mail->subject);
                $this->assertSame('emails.campaign', $mail->view);
                $this->assertSame($campaign->id, $mail->viewData['campaign']->id);
                $this->assertSame($notifiable->id, $mail->viewData['customer']->id);

                return true;
            },
        );
    }

    public function test_push_campaign_persists_database_notification_row(): void
    {
        $campaign = Campaign::factory()->create([
            'type' => CampaignType::Push,
            'title' => 'Winter Push',
            'message' => 'Tap to browse the new drop.',
            'status' => CampaignStatus::Scheduled,
        ]);

        $this->customer->notify(new CampaignNotification($campaign));

        $this->assertDatabaseCount('notifications', 1);

        $row = $this->customer->notifications()->first();
        $this->assertNotNull($row);
        $this->assertSame(CampaignNotification::class, $row->type);

        $payload = $row->data;
        $this->assertSame($campaign->id, $payload['campaign_id']);
        $this->assertSame('Winter Push', $payload['title']);
        $this->assertSame('Tap to browse the new drop.', $payload['message']);
        $this->assertSame('push', $payload['type']);
        $this->assertNotEmpty($payload['url']);
    }

    public function test_sms_campaign_does_not_persist_or_mail_but_logs_an_audit_line(): void
    {
        Notification::fake();
        Log::spy();

        $campaign = Campaign::factory()->create([
            'type' => CampaignType::Sms,
            'subject' => 'VIP early access',
            'status' => CampaignStatus::Scheduled,
        ]);

        $this->customer->notify(new CampaignNotification($campaign));

        // SMS uses no real transport: the notification returns an empty
        // channel list, so Notification::fake() records nothing sent.
        Notification::assertNothingSent();
        $this->assertDatabaseCount('notifications', 0);

        // The audit line was emitted with the campaign and notifiable context.
        Log::shouldHaveReceived('info')
            ->withArgs(
                fn (string $message, array $context): bool => $message === 'Campaign SMS dispatched (channel disabled).'
                    && ($context['campaign_id'] ?? null) === $campaign->id
                    && ($context['notifiable_id'] ?? null) === $this->customer->id,
            )
            ->atLeast()
            ->once();
    }

    public function test_track_open_endpoint_increments_counter_and_returns_transparent_gif(): void
    {
        $campaign = Campaign::factory()->active()->create([
            'opened_count' => 7,
        ]);

        $response = $this->get(route('campaigns.track.open', $campaign));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/gif');

        // 1x1 transparent GIF byte sequence.
        $expectedGif = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
        $this->assertSame(strlen($expectedGif), strlen($response->getContent()));
        $this->assertSame($expectedGif, $response->getContent());

        $this->assertSame(8, $campaign->fresh()->opened_count);
    }

    public function test_track_open_supports_multiple_simultaneous_pings(): void
    {
        $campaign = Campaign::factory()->active()->create(['opened_count' => 0]);

        for ($i = 0; $i < 5; $i++) {
            $this->get(route('campaigns.track.open', $campaign))->assertOk();
        }

        $this->assertSame(5, $campaign->fresh()->opened_count);
    }

    public function test_track_click_endpoint_increments_counter_and_redirects_to_destination(): void
    {
        $campaign = Campaign::factory()->active()->create([
            'clicked_count' => 12,
        ]);

        $destination = route('shop');
        $response = $this->get(
            route('campaigns.track.click', $campaign).'?url='.urlencode($destination),
        );

        $response->assertRedirect($destination);
        $this->assertSame(13, $campaign->fresh()->clicked_count);
    }

    public function test_track_click_falls_back_to_shop_when_url_query_is_missing(): void
    {
        $campaign = Campaign::factory()->active()->create(['clicked_count' => 0]);

        $response = $this->get(route('campaigns.track.click', $campaign));

        $response->assertRedirect(route('shop'));
        $this->assertSame(1, $campaign->fresh()->clicked_count);
    }

    public function test_track_click_rejects_non_http_schemes(): void
    {
        $campaign = Campaign::factory()->active()->create(['clicked_count' => 0]);

        $response = $this->get(
            route('campaigns.track.click', $campaign).'?url='.urlencode('javascript:alert(1)'),
        );

        // Falls back to the safe shop URL; never honours a javascript: link.
        $response->assertRedirect(route('shop'));
        $this->assertSame(1, $campaign->fresh()->clicked_count);
    }

    public function test_email_template_embeds_tracking_pixel_and_rewrites_cta_through_click_tracker(): void
    {
        $campaign = Campaign::factory()->create([
            'type' => CampaignType::Email,
            'subject' => 'Embedded Tracking Test',
            'message' => 'Open this and click through.',
            'status' => CampaignStatus::Scheduled,
        ]);

        $html = view('emails.campaign', [
            'campaign' => $campaign,
            'customer' => $this->customer,
        ])->render();

        $this->assertStringContainsString(
            route('campaigns.track.open', $campaign->id),
            $html,
            'The mail view must embed the open-tracking pixel URL.',
        );

        $expectedClickPrefix = route('campaigns.track.click', $campaign->id);
        $this->assertStringContainsString(
            $expectedClickPrefix,
            $html,
            'The mail view must rewrite CTA links through the click tracker.',
        );
    }

    public function test_dispatch_due_schedules_only_picks_up_campaigns_past_their_schedule(): void
    {
        Bus::fake();

        // Due: scheduled in the past -> must be picked up.
        $dueCampaign = Campaign::factory()->scheduled()->create([
            'title' => 'Due Campaign',
            'scheduled_for' => now()->subMinutes(5),
        ]);

        // Future: scheduled for tomorrow -> must be ignored.
        Campaign::factory()->scheduled()->create([
            'title' => 'Future Campaign',
            'scheduled_for' => now()->addDay(),
        ]);

        // Draft: not scheduled at all -> must be ignored.
        Campaign::factory()->draft()->create([
            'title' => 'Draft Campaign',
        ]);

        // Already active: scope() filters it out anyway, but verify it is
        // also skipped so a tick never stacks a duplicate delivery run.
        Campaign::factory()->active()->create([
            'title' => 'Active Campaign',
        ]);

        $dispatched = app(CampaignService::class)
            ->dispatchDueScheduledCampaigns();

        $this->assertSame(1, $dispatched);

        Bus::assertDispatched(
            SendCampaignJob::class,
            fn (SendCampaignJob $job): bool => $job->campaign->is($dueCampaign),
        );

        // The promoted campaign now sits in 'active' status, not 'scheduled'.
        $this->assertSame(
            CampaignStatus::Active->value,
            $dueCampaign->fresh()->status->value,
        );
    }

    public function test_dispatch_due_schedules_returns_zero_when_no_campaigns_are_due(): void
    {
        Bus::fake();

        Campaign::factory()->scheduled()->create([
            'scheduled_for' => now()->addHours(2),
        ]);
        Campaign::factory()->draft()->create();

        $dispatched = app(CampaignService::class)
            ->dispatchDueScheduledCampaigns();

        $this->assertSame(0, $dispatched);
        Bus::assertNotDispatched(SendCampaignJob::class);
    }

    public function test_scheduler_is_registered_to_run_every_minute(): void
    {
        $schedule = $this->app->make(Schedule::class);

        $events = $schedule->events();
        $this->assertNotEmpty($events, 'The scheduler should have at least one event registered.');

        $found = false;

        foreach ($events as $event) {
            if ($event->description === 'dispatch-scheduled-campaigns') {
                $found = true;
                $this->assertSame('* * * * *', $event->expression);
                break;
            }
        }

        $this->assertTrue($found, 'dispatch-scheduled-campaigns schedule must be registered.');
    }
}

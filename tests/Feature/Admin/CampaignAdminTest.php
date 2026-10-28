<?php

namespace Tests\Feature\Admin;

use App\Enums\CampaignAudience;
use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use App\Enums\OrderStatus;
use App\Models\Admin;
use App\Models\Campaign;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Variant;
use App\Services\CustomerSegmentationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignAdminTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create([
            'name' => 'Campaigns Admin',
            'email' => 'campaigns_admin@store.com',
            'password' => 'password',
            'is_active' => true,
        ]);
    }

    private function actingAsAdmin(): static
    {
        return $this->actingAs($this->admin, 'admin');
    }

    public function test_admin_can_view_campaigns_index_with_payload(): void
    {
        Campaign::factory()->count(3)->create();

        $response = $this->actingAsAdmin()
            ->get(route('admin.campaigns.index'));

        $response->assertOk()
            ->assertViewIs('admin.campaigns.index')
            ->assertViewHas('campaignsJson')
            ->assertViewHas('coupons')
            ->assertViewHas('stats');

        $payload = json_decode($response->viewData('campaignsJson'), true);

        $this->assertIsArray($payload);
        $this->assertCount(3, $payload);
        $this->assertArrayHasKey('id', $payload[0]);
        $this->assertArrayHasKey('preview', $payload[0]);
        $this->assertArrayHasKey('type', $payload[0]);
        $this->assertArrayHasKey('audience', $payload[0]);
        $this->assertArrayHasKey('status', $payload[0]);
        $this->assertArrayHasKey('coupon', $payload[0]);
        $this->assertArrayHasKey('deeplink', $payload[0]);
    }

    public function test_admin_can_create_draft_campaign_when_scheduled_for_is_in_past(): void
    {
        $response = $this->actingAsAdmin()
            ->postJson(route('admin.campaigns.store'), [
                'title' => 'Past Date Draft',
                'subject' => 'Hello',
                'preview' => 'Quick preview',
                'message' => 'Body content',
                'type' => CampaignType::Push->value,
                'audience' => CampaignAudience::All->value,
                'scheduled_for' => now()->subDay()->toDateTimeString(),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('scheduled_for');

        // Even without scheduled_for, a brand new campaign is a draft.
        $this->actingAsAdmin()
            ->postJson(route('admin.campaigns.store'), [
                'title' => 'Plain Draft',
                'subject' => 'Subject',
                'preview' => 'Preview text',
                'message' => 'Body content',
                'type' => CampaignType::Push->value,
                'audience' => CampaignAudience::All->value,
            ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('campaign.status', CampaignStatus::Draft->value);

        $this->assertDatabaseHas('campaigns', [
            'title' => 'Plain Draft',
            'status' => CampaignStatus::Draft->value,
        ]);
    }

    public function test_admin_can_create_scheduled_campaign_when_scheduled_for_is_in_future(): void
    {
        $this->actingAsAdmin()
            ->postJson(route('admin.campaigns.store'), [
                'title' => 'Future Campaign',
                'subject' => 'Subject',
                'preview' => 'Preview text',
                'message' => 'Body content',
                'type' => CampaignType::Email->value,
                'audience' => CampaignAudience::Vip->value,
                'scheduled_for' => now()->addDays(3)->toDateTimeString(),
            ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('campaign.status', CampaignStatus::Scheduled->value)
            ->assertJsonPath('campaign.type', CampaignType::Email->value);

        $this->assertDatabaseHas('campaigns', [
            'title' => 'Future Campaign',
            'status' => CampaignStatus::Scheduled->value,
        ]);
    }

    public function test_admin_can_create_campaign_with_coupon_and_deeplink(): void
    {
        $this->actingAsAdmin()
            ->postJson(route('admin.campaigns.store'), [
                'title' => 'Coupon Campaign',
                'subject' => 'Special Promo',
                'preview' => 'Save big today',
                'message' => 'Use code PROMO20 for 20% off',
                'type' => CampaignType::Email->value,
                'audience' => CampaignAudience::All->value,
                'coupon' => 'PROMO20',
                'deeplink' => 'category:hoodies',
            ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('campaign.coupon', 'PROMO20')
            ->assertJsonPath('campaign.deeplink', 'category:hoodies');

        $this->assertDatabaseHas('campaigns', [
            'title' => 'Coupon Campaign',
            'coupon' => 'PROMO20',
            'deeplink' => 'category:hoodies',
        ]);
    }

    public function test_create_campaign_rejects_invalid_data(): void
    {
        $this->actingAsAdmin()
            ->postJson(route('admin.campaigns.store'), [
                'title' => '',
                'subject' => '',
                'message' => '',
                'type' => 'telepathy',
                'audience' => 'everyone-on-earth',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'subject', 'message', 'type', 'audience']);
    }

    public function test_admin_can_update_existing_campaign(): void
    {
        $campaign = Campaign::factory()->draft()->create([
            'title' => 'Old Title',
            'message' => 'Old message',
        ]);

        $this->actingAsAdmin()
            ->putJson(route('admin.campaigns.update', $campaign), [
                'title' => 'New Title',
                'subject' => 'New subject',
                'preview' => 'New preview',
                'message' => 'New message',
                'type' => CampaignType::Sms->value,
                'audience' => CampaignAudience::CartAbandoners->value,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('campaign.name', 'New Title')
            ->assertJsonPath('campaign.type', CampaignType::Sms->value);

        $this->assertDatabaseHas('campaigns', [
            'id' => $campaign->id,
            'title' => 'New Title',
            'type' => CampaignType::Sms->value,
        ]);
    }

    public function test_admin_cannot_update_ended_campaign(): void
    {
        $campaign = Campaign::factory()->ended()->create();

        $this->actingAsAdmin()
            ->putJson(route('admin.campaigns.update', $campaign), [
                'title' => 'New Title',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('campaign');

        $this->assertDatabaseHas('campaigns', [
            'id' => $campaign->id,
            'title' => $campaign->title,
        ]);
    }

    public function test_admin_can_delete_draft_campaign(): void
    {
        $campaign = Campaign::factory()->draft()->create();

        $this->actingAsAdmin()
            ->deleteJson(route('admin.campaigns.destroy', $campaign))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('campaigns', ['id' => $campaign->id]);
    }

    public function test_admin_cannot_delete_active_campaign(): void
    {
        $campaign = Campaign::factory()->active()->create();

        $this->actingAsAdmin()
            ->deleteJson(route('admin.campaigns.destroy', $campaign))
            ->assertStatus(422)
            ->assertJsonValidationErrors('campaign');

        $this->assertDatabaseHas('campaigns', ['id' => $campaign->id]);
    }

    public function test_admin_can_duplicate_campaign_as_draft(): void
    {
        $campaign = Campaign::factory()->active()->create([
            'title' => 'Original Campaign',
            'subject' => 'Original Subject',
            'preview' => 'Original preview',
            'message' => 'Original message body',
            'sent_count' => 5000,
            'opened_count' => 1500,
            'clicked_count' => 600,
        ]);

        $response = $this->actingAsAdmin()
            ->postJson(route('admin.campaigns.duplicate', $campaign));

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('campaign.status', CampaignStatus::Draft->value)
            ->assertJsonPath('campaign.name', 'Original Campaign (copy)');

        $this->assertDatabaseHas('campaigns', [
            'title' => 'Original Campaign (copy)',
            'status' => CampaignStatus::Draft->value,
            'sent_count' => 0,
            'opened_count' => 0,
            'clicked_count' => 0,
        ]);

        // Original is untouched.
        $this->assertDatabaseHas('campaigns', [
            'id' => $campaign->id,
            'sent_count' => 5000,
        ]);
    }

    public function test_admin_can_show_single_campaign(): void
    {
        $campaign = Campaign::factory()->active()->create();

        $this->actingAsAdmin()
            ->getJson(route('admin.campaigns.show', $campaign))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('campaign.id', 'c-'.str_pad((string) $campaign->id, 3, '0', STR_PAD_LEFT));
    }

    public function test_guest_cannot_access_campaigns_index(): void
    {
        $this->get(route('admin.campaigns.index'))
            ->assertRedirect(route('admin.login'));
    }

    public function test_guest_cannot_call_campaign_apis(): void
    {
        $campaign = Campaign::factory()->draft()->create();

        $this->postJson(route('admin.campaigns.store'), [])->assertRedirect();
        $this->putJson(route('admin.campaigns.update', $campaign), [])->assertRedirect();
        $this->deleteJson(route('admin.campaigns.destroy', $campaign))->assertRedirect();
        $this->postJson(route('admin.campaigns.duplicate', $campaign))->assertRedirect();
        $this->postJson(route('admin.campaigns.sendNow', $campaign))->assertRedirect();
        $this->getJson(route('admin.campaigns.show', $campaign))->assertRedirect();
        $this->getJson(route('admin.campaigns.audienceCount', CampaignAudience::All->value))->assertRedirect();
    }

    public function test_dashboard_stats_calculate_correctly(): void
    {
        Campaign::factory()->active()->create([
            'sent_count' => 1000,
            'opened_count' => 400,
            'clicked_count' => 100,
        ]);
        Campaign::factory()->active()->create([
            'sent_count' => 2000,
            'opened_count' => 600,
            'clicked_count' => 200,
        ]);
        Campaign::factory()->draft()->create();
        Campaign::factory()->scheduled()->create();

        $response = $this->actingAsAdmin()
            ->get(route('admin.campaigns.index'));

        $response->assertOk()
            ->assertViewHas('stats', function (array $stats): bool {
                return $stats['active_campaigns_count'] === 2
                    && $stats['scheduled_campaigns_count'] === 1
                    && $stats['total_sent_count'] === 3000
                    && abs($stats['avg_open_rate'] - 35.0) < 0.01
                    && abs($stats['avg_click_rate'] - 10.0) < 0.01;
            });
    }

    public function test_the_scheduled_kpi_card_shows_the_real_count(): void
    {
        Campaign::factory()->scheduled()->count(3)->create();
        Campaign::factory()->active()->create();

        $response = $this->actingAsAdmin()
            ->get(route('admin.campaigns.index'));

        // The card used to be hardcoded to '0' — a number that could
        // never be right no matter what the database held.
        $response->assertOk()
            ->assertSee('Scheduled')
            ->assertSee('>3<', false);
    }

    // ─── Send now ─────────────────────────────────────────────────────

    public function test_admin_can_send_a_campaign_now(): void
    {
        Customer::factory()->count(4)->create();

        $campaign = Campaign::factory()->draft()->create([
            'audience' => CampaignAudience::All,
            'sent_at' => null,
            'sent_count' => 0,
        ]);

        // phpunit.xml pins the queue to sync, so the dispatched job has
        // already run by the time the response is built.
        $this->actingAsAdmin()
            ->postJson(route('admin.campaigns.sendNow', $campaign))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Campaign dispatched successfully to background queue.');

        $fresh = $campaign->fresh();

        $this->assertSame(CampaignStatus::Ended, $fresh->status);
        $this->assertNotNull($fresh->sent_at);
        $this->assertSame(4, $fresh->sent_count);
    }

    public function test_admin_cannot_send_an_ended_campaign(): void
    {
        $campaign = Campaign::factory()->ended()->create();

        $this->actingAsAdmin()
            ->postJson(route('admin.campaigns.sendNow', $campaign))
            ->assertStatus(422)
            ->assertJsonValidationErrors('campaign');

        $this->assertSame(CampaignStatus::Ended, $campaign->fresh()->status);
    }

    public function test_admin_cannot_send_a_campaign_twice(): void
    {
        $campaign = Campaign::factory()->active()->create();

        $this->actingAsAdmin()
            ->postJson(route('admin.campaigns.sendNow', $campaign))
            ->assertStatus(422)
            ->assertJsonValidationErrors('campaign');
    }

    // ─── Audience reach preview ───────────────────────────────────────

    public function test_admin_can_preview_every_audience_count(): void
    {
        $vip = Customer::factory()->create(['created_at' => now()->subDays(90)]);
        Order::factory()->create([
            'customer_id' => $vip->id,
            'status' => OrderStatus::Delivered,
            'total' => 2500.00,
            'created_at' => now()->subDays(80),
        ]);

        $abandoner = Customer::factory()->create(['created_at' => now()->subDays(90)]);
        CartItem::factory()
            ->forCustomer($abandoner)
            ->forVariant(Variant::factory()->create())
            ->create(['updated_at' => now()->subHours(40)]);

        $dormant = Customer::factory()->create(['created_at' => now()->subDays(90)]);
        $blocked = Customer::factory()->blocked()->create();

        $expected = [
            CampaignAudience::All->value => 3,
            CampaignAudience::Vip->value => 1,
            CampaignAudience::CartAbandoners->value => 1,
            // VIP last ordered 80 days ago, so they are dormant too.
            CampaignAudience::Inactive30Days->value => 3,
        ];

        foreach ($expected as $audience => $count) {
            $this->actingAsAdmin()
                ->getJson(route('admin.campaigns.audienceCount', $audience))
                ->assertOk()
                ->assertJsonPath('success', true)
                ->assertJsonPath('audience', $audience)
                ->assertJsonPath('label', CampaignAudience::from($audience)->label())
                ->assertJsonPath('count', $count);
        }

        // 'all' reported 3, not 4: the blocked account is never reachable.
        $allAudienceIds = app(CustomerSegmentationService::class)
            ->getAudienceQuery(CampaignAudience::All)
            ->pluck('customers.id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $this->assertNotContains($blocked->id, $allAudienceIds);
    }

    public function test_audience_count_rejects_an_unknown_segment(): void
    {
        $this->actingAsAdmin()
            ->getJson(route('admin.campaigns.audienceCount', 'not-a-segment'))
            ->assertNotFound();
    }

    public function test_campaigns_index_exposes_live_audience_counts(): void
    {
        Customer::factory()->count(2)->create();

        $response = $this->actingAsAdmin()
            ->get(route('admin.campaigns.index'));

        $response->assertOk()
            ->assertViewHas('audienceCounts', fn (array $counts): bool => $counts === [
                'all' => 2,
                'vip' => 0,
                'cart_abandoners' => 0,
                'inactive_30_days' => 0,
            ]);
    }

    public function test_audience_cards_are_rendered_from_the_enum(): void
    {
        $response = $this->actingAsAdmin()
            ->get(route('admin.campaigns.index'));

        $response->assertOk();

        // Every card value must be a segment the segmentation service can
        // resolve, and every segment must be offered.
        foreach (CampaignAudience::cases() as $audience) {
            $response->assertSee('value="'.$audience->value.'"', false);
            $response->assertSee($audience->label());
        }

        // The old design-only options no longer exist on the server.
        foreach (['new', 'category', 'city'] as $obsolete) {
            $response->assertDontSee('value="'.$obsolete.'"', false);
        }
    }
}

<?php

namespace Tests\Feature;

use App\Enums\CampaignAudience;
use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use App\Models\Campaign;
use Database\Seeders\CampaignSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CampaignModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_can_be_created_with_factory_and_enums(): void
    {
        $campaign = Campaign::factory()->create([
            'title' => 'Winter Push',
            'type' => CampaignType::Push,
            'audience' => CampaignAudience::Vip,
            'status' => CampaignStatus::Scheduled,
        ]);

        $this->assertDatabaseHas('campaigns', ['title' => 'Winter Push']);
        $this->assertSame(CampaignType::Push, $campaign->type);
        $this->assertSame(CampaignAudience::Vip, $campaign->audience);
        $this->assertSame(CampaignStatus::Scheduled, $campaign->status);
    }

    public function test_enum_labels_and_persisted_strings(): void
    {
        $campaign = Campaign::factory()->create([
            'type' => CampaignType::Email,
            'audience' => CampaignAudience::All,
            'status' => CampaignStatus::Draft,
        ]);

        // التحقق من القيم النصية المخزنة في قاعدة البيانات
        $this->assertDatabaseHas('campaigns', [
            'id' => $campaign->id,
            'type' => 'email',
            'audience' => 'all',
            'status' => 'draft',
        ]);

        // التحقق من دوال label في الـ Enums
        $this->assertSame('Email', CampaignType::Email->label());
        $this->assertSame('Push Notification', CampaignType::Push->label());
        $this->assertSame('SMS', CampaignType::Sms->label());
        $this->assertSame('All customers', CampaignAudience::All->label());
        $this->assertSame('VIP customers', CampaignAudience::Vip->label());
        $this->assertSame('Cart abandoners', CampaignAudience::CartAbandoners->label());
        $this->assertSame('Inactive 30+ days', CampaignAudience::Inactive30Days->label());
        $this->assertSame('Draft', CampaignStatus::Draft->label());
        $this->assertSame('Scheduled', CampaignStatus::Scheduled->label());
        $this->assertSame('Active', CampaignStatus::Active->label());
        $this->assertSame('Ended', CampaignStatus::Ended->label());
    }

    public function test_datetime_and_integer_casts_work(): void
    {
        $campaign = Campaign::factory()->active()->create([
            'scheduled_for' => '2026-10-12 09:00:00',
            'sent_at' => '2026-10-12 09:00:00',
            'sent_count' => 5000,
            'opened_count' => 1500,
            'clicked_count' => 500,
        ]);

        $refreshed = Campaign::find($campaign->id);

        $this->assertNotNull($refreshed->scheduled_for);
        $this->assertInstanceOf(Carbon::class, $refreshed->scheduled_for);
        $this->assertSame('2026-10-12 09:00:00', $refreshed->scheduled_for->format('Y-m-d H:i:s'));

        $this->assertNotNull($refreshed->sent_at);
        $this->assertInstanceOf(Carbon::class, $refreshed->sent_at);

        $this->assertIsInt($refreshed->sent_count);
        $this->assertIsInt($refreshed->opened_count);
        $this->assertIsInt($refreshed->clicked_count);
        $this->assertSame(5000, $refreshed->sent_count);
    }

    public function test_open_rate_calculation_with_real_data(): void
    {
        $campaign = Campaign::factory()->create([
            'sent_count' => 1000,
            'opened_count' => 380, // 38%
            'clicked_count' => 120, // 12%
        ]);

        $this->assertSame(38.0, $campaign->openRate());
        $this->assertSame(12.0, $campaign->clickRate());
    }

    public function test_open_and_click_rates_return_zero_when_no_sends(): void
    {
        $campaign = Campaign::factory()->draft()->create();

        $this->assertSame(0.0, $campaign->openRate());
        $this->assertSame(0.0, $campaign->clickRate());
    }

    public function test_open_and_click_rates_avoid_division_by_zero(): void
    {
        // sent_count = 0 صراحةً مع محاولة فتح/نقر
        $campaign = Campaign::factory()->create([
            'sent_count' => 0,
            'opened_count' => 50,
            'clicked_count' => 25,
        ]);

        // بما أن sent_count = 0 يجب أن ترجع 0 بدلاً من رمي خطأ
        $this->assertSame(0.0, $campaign->openRate());
        $this->assertSame(0.0, $campaign->clickRate());
    }

    public function test_scope_scheduled_for_sending_returns_only_due_campaigns(): void
    {
        // حملة مجدولة في الماضي (مستحقة)
        $dueScheduled = Campaign::factory()->scheduled()->create([
            'scheduled_for' => now()->subMinutes(5),
        ]);

        // حملة مجدولة في المستقبل (غير مستحقة)
        $futureScheduled = Campaign::factory()->scheduled()->create([
            'scheduled_for' => now()->addDays(3),
        ]);

        // حملة فعّالة (لا يجب أن تظهر في الـ scope الخاص بالمجدولة)
        $activeCampaign = Campaign::factory()->active()->create();

        // حملة مسودة (لا يجب أن تظهر)
        $draftCampaign = Campaign::factory()->draft()->create();

        $due = Campaign::query()->scheduledForSending()->get();

        $this->assertCount(1, $due);
        $this->assertTrue($due->contains($dueScheduled));
        $this->assertFalse($due->contains($futureScheduled));
        $this->assertFalse($due->contains($activeCampaign));
        $this->assertFalse($due->contains($draftCampaign));
    }

    public function test_to_admin_payload_matches_admin_js_shape(): void
    {
        $campaign = Campaign::factory()->create([
            'id' => 7,
            'preview' => 'The hoodie you wanted is back in stock.',
            'type' => CampaignType::Email,
            'audience' => CampaignAudience::All,
            'status' => CampaignStatus::Scheduled,
            'scheduled_for' => '2026-11-25 09:00:00',
            'sent_count' => 0,
            'opened_count' => 0,
            'clicked_count' => 0,
        ]);

        $payload = $campaign->toAdminPayload();

        // معرّف بصيغة c-007 كما في لوحة الأدمن
        $this->assertSame('c-007', $payload['id']);
        $this->assertSame('The hoodie you wanted is back in stock.', $payload['preview']);
        $this->assertSame('email', $payload['type']);
        $this->assertSame('All customers', $payload['audience']);
        $this->assertSame('scheduled', $payload['status']);

        // مجدولة: schedule.date و schedule.time
        $this->assertSame(['date' => '2026-11-25', 'time' => '09:00'], $payload['schedule']);

        // لم ترسل بعد: القيم null
        $this->assertNull($payload['sent']);
        $this->assertNull($payload['opened']);
        $this->assertNull($payload['clicked']);
    }

    public function test_to_admin_payload_for_draft_has_null_schedule_and_metrics(): void
    {
        $campaign = Campaign::factory()->draft()->create([
            'preview' => 'Be the first to shop our new drop before anyone else.',
            'type' => CampaignType::Email,
            'audience' => CampaignAudience::Vip,
        ]);

        $payload = $campaign->toAdminPayload();

        $this->assertNull($payload['schedule']);
        $this->assertNull($payload['sent']);
        $this->assertNull($payload['opened']);
        $this->assertNull($payload['clicked']);
    }

    public function test_to_admin_payload_for_active_renders_rounded_percentages(): void
    {
        $campaign = Campaign::factory()->active()->create([
            'sent_count' => 5200,
            'opened_count' => 1976, // ≈ 38%
            'clicked_count' => 624, // ≈ 12%
        ]);

        $payload = $campaign->toAdminPayload();

        $this->assertSame(5200, $payload['sent']);
        $this->assertSame(38, $payload['opened']);
        $this->assertSame(12, $payload['clicked']);
        $this->assertIsArray($payload['schedule']);
    }

    public function test_campaign_seeder_works_and_inserts_all_realistic_rows(): void
    {
        $this->seed(CampaignSeeder::class);

        // كل العنوانات الفريدة المتوقعة
        $expectedTitles = [
            'Winter Season Kickoff',
            'Cart Recovery - Reminder',
            'Weekend Sale',
            'Win-back Campaign',
            'Flash Sale 24h',
            'VIP Early Access Teaser',
        ];

        foreach ($expectedTitles as $title) {
            $this->assertDatabaseHas('campaigns', ['title' => $title]);
        }

        // نتأكد من وجود أربع حالات مختلفة على الأقل
        $this->assertTrue(Campaign::where('status', CampaignStatus::Active->value)->exists());
        $this->assertTrue(Campaign::where('status', CampaignStatus::Scheduled->value)->exists());
        $this->assertTrue(Campaign::where('status', CampaignStatus::Ended->value)->exists());
        $this->assertTrue(Campaign::where('status', CampaignStatus::Draft->value)->exists());

        // نتأكد من تغطية كل القنوات المعمارية
        $this->assertTrue(Campaign::where('type', CampaignType::Email->value)->exists());
        $this->assertTrue(Campaign::where('type', CampaignType::Push->value)->exists());
        $this->assertTrue(Campaign::where('type', CampaignType::Sms->value)->exists());
    }
}

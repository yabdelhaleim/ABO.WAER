<?php

namespace Database\Seeders;

use App\Enums\CampaignAudience;
use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use App\Models\Campaign;
use Illuminate\Database\Seeder;

class CampaignSeeder extends Seeder
{
    public function run(): void
    {
        $campaigns = [
            [
                // حملة فعّالة من نوع Push موجّهة لجميع العملاء
                'title' => 'Winter Season Kickoff',
                'subject' => 'Get ready for cozy season',
                'preview' => 'Get ready for cozy season. Up to 30% off winter essentials.',
                'message' => 'احصل على خصم يصل إلى 30% على أساسيات الشتاء. العرض ساري لفترة محدودة.',
                'type' => CampaignType::Push,
                'audience' => CampaignAudience::All,
                'status' => CampaignStatus::Active,
                'scheduled_for' => '2026-10-12 09:00:00',
                'sent_at' => '2026-10-12 09:00:00',
                'sent_count' => 5200,
                'opened_count' => 1976, // 38% من 5200
                'clicked_count' => 624, // 12% من 5200
            ],
            [
                // حملة فعّالة من نوع Email موجّهة لمن تركوا السلة
                'title' => 'Cart Recovery - Reminder',
                'subject' => 'You left something in your cart',
                'preview' => 'You left something in your cart. Complete your order now.',
                'message' => 'لا تنسَ العناصر التي تركتها في سلتك. أكمل طلبك الآن قبل نفاد الكمية.',
                'type' => CampaignType::Email,
                'audience' => CampaignAudience::CartAbandoners,
                'status' => CampaignStatus::Active,
                'scheduled_for' => '2026-10-10 14:30:00',
                'sent_at' => '2026-10-10 14:30:00',
                'sent_count' => 1840,
                'opened_count' => 956, // 52% من 1840
                'clicked_count' => 404, // 22% من 1840 تقريباً
            ],
            [
                // حملة مجدولة من نوع Push لموسم التخفيضات
                'title' => 'Weekend Sale',
                'subject' => 'Weekend sale is here',
                'preview' => 'Weekend sale is here. 20% off everything, 48 hours only.',
                'message' => 'خصم 20% على كل شيء لمدة 48 ساعة فقط. لا تفوّت الفرصة!',
                'type' => CampaignType::Push,
                'audience' => CampaignAudience::All,
                'status' => CampaignStatus::Scheduled,
                'scheduled_for' => '2026-11-22 10:00:00',
                'sent_at' => null,
                'sent_count' => 0,
                'opened_count' => 0,
                'clicked_count' => 0,
            ],
            [
                // حملة منتهية من نوع Email لاستعادة العملاء الخاملين
                'title' => 'Win-back Campaign',
                'subject' => "It's been a while — here's 15% off",
                'preview' => "It's been a while — here's 15% off to welcome you back.",
                'message' => 'لقد اشتقنا إليك! إليك خصم 15% كترحيب بعودتك إلينا.',
                'type' => CampaignType::Email,
                'audience' => CampaignAudience::Inactive30Days,
                'status' => CampaignStatus::Active,
                'scheduled_for' => '2026-10-08 11:15:00',
                'sent_at' => '2026-10-08 11:15:00',
                'sent_count' => 3420,
                'opened_count' => 1402, // 41% من 3420
                'clicked_count' => 615, // 18% من 3420 تقريباً
            ],
            [
                // حملة منتهية من نوع Push لتصفية الـ Flash Sale
                'title' => 'Flash Sale 24h',
                'subject' => 'Flash sale — 24 hours only',
                'preview' => 'Flash sale — 24 hours only on selected pieces.',
                'message' => 'عرض الـ Flash Sale: 24 ساعة فقط على قطع مختارة.',
                'type' => CampaignType::Push,
                'audience' => CampaignAudience::All,
                'status' => CampaignStatus::Ended,
                'scheduled_for' => '2026-10-04 08:00:00',
                'sent_at' => '2026-10-04 08:00:00',
                'sent_count' => 8400,
                'opened_count' => 3948, // 47% من 8400
                'clicked_count' => 1260, // 15% من 8400
            ],
            [
                // حملة مسودة من نوع SMS للعملاء VIP (معمارياً جاهزة)
                'title' => 'VIP Early Access Teaser',
                'subject' => 'Something new is coming',
                'preview' => 'Something new is coming. Be the first to know.',
                'message' => 'قريباً شيء جديد. كن أول من يعرف.',
                'type' => CampaignType::Sms,
                'audience' => CampaignAudience::Vip,
                'status' => CampaignStatus::Draft,
                'scheduled_for' => null,
                'sent_at' => null,
                'sent_count' => 0,
                'opened_count' => 0,
                'clicked_count' => 0,
            ],
        ];

        foreach ($campaigns as $data) {
            Campaign::firstOrCreate(['title' => $data['title']], $data);
        }
    }
}

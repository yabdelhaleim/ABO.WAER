<?php

namespace Database\Factories;

use App\Enums\CampaignAudience;
use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use App\Models\Campaign;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->unique()->sentence(4),
            'subject' => fake()->sentence(6),
            'preview' => fake()->sentence(10),
            'message' => fake()->paragraph(),
            'type' => CampaignType::Email,
            'audience' => CampaignAudience::All,
            'status' => CampaignStatus::Draft,
            'scheduled_for' => null,
            'sent_at' => null,
            'sent_count' => 0,
            'opened_count' => 0,
            'clicked_count' => 0,
        ];
    }

    /**
     * حالة المسودة: لا توجد أي بيانات إرسال.
     */
    public function draft(): static
    {
        return $this->state(fn () => [
            'status' => CampaignStatus::Draft,
            'scheduled_for' => null,
            'sent_at' => null,
            'sent_count' => 0,
            'opened_count' => 0,
            'clicked_count' => 0,
        ]);
    }

    /**
     * حالة المجدولة: لها scheduled_for في المستقبل، بدون sent_at.
     */
    public function scheduled(): static
    {
        return $this->state(fn () => [
            'status' => CampaignStatus::Scheduled,
            'scheduled_for' => now()->addDays(7),
            'sent_at' => null,
            'sent_count' => 0,
            'opened_count' => 0,
            'clicked_count' => 0,
        ]);
    }

    /**
     * حالة الفعّالة: تم إرسالها بالفعل وبدأت تظهر تفاعلات.
     */
    public function active(): static
    {
        return $this->state(fn () => [
            'status' => CampaignStatus::Active,
            'scheduled_for' => now()->subDays(2),
            'sent_at' => now()->subDays(2),
            'sent_count' => fake()->numberBetween(1000, 9000),
            'opened_count' => fake()->numberBetween(300, 4500),
            'clicked_count' => fake()->numberBetween(50, 1500),
        ]);
    }

    /**
     * حالة المنتهية: أرسلت منذ فترة مع مؤشرات ثابتة.
     */
    public function ended(): static
    {
        return $this->state(fn () => [
            'status' => CampaignStatus::Ended,
            'scheduled_for' => now()->subWeeks(2),
            'sent_at' => now()->subWeeks(2),
            'sent_count' => fake()->numberBetween(5000, 9000),
            'opened_count' => fake()->numberBetween(2000, 4500),
            'clicked_count' => fake()->numberBetween(500, 1800),
        ]);
    }

    /**
     * قناة دفع (Push).
     */
    public function push(): static
    {
        return $this->state(fn () => [
            'type' => CampaignType::Push,
        ]);
    }

    /**
     * قناة SMS (معمارياً جاهزة).
     */
    public function sms(): static
    {
        return $this->state(fn () => [
            'type' => CampaignType::Sms,
        ]);
    }

    /**
     * شريحة الـ VIP.
     */
    public function vip(): static
    {
        return $this->state(fn () => [
            'audience' => CampaignAudience::Vip,
        ]);
    }
}

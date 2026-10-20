<?php

namespace Database\Factories;

use App\Enums\CouponType;
use App\Models\Coupon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Coupon>
 */
class CouponFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('ABO???')),
            'type' => CouponType::Percentage,
            'value' => 15.00,
            'max_discount' => 150.00,
            'min_order_total' => 200.00,
            'total_usage_limit' => 100,
            'usage_per_customer_limit' => 1,
            'used_count' => 0,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addMonth(),
            'is_active' => true,
        ];
    }
}

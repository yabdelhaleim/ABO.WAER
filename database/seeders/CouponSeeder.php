<?php

namespace Database\Seeders;

use App\Enums\CouponType;
use App\Models\Coupon;
use Illuminate\Database\Seeder;

class CouponSeeder extends Seeder
{
    public function run(): void
    {
        $coupons = [
            [
                'code' => 'WELCOME10',
                'type' => CouponType::Percentage,
                'value' => 10.00,
                'max_discount' => 100.00,
                'min_order_total' => 300.00,
                'total_usage_limit' => 500,
                'usage_per_customer_limit' => 1,
                'is_active' => true,
            ],
            [
                'code' => 'SUMMER20',
                'type' => CouponType::Percentage,
                'value' => 20.00,
                'max_discount' => 250.00,
                'min_order_total' => 800.00,
                'total_usage_limit' => 100,
                'usage_per_customer_limit' => 1,
                'is_active' => true,
            ],
            [
                'code' => 'ABO50',
                'type' => CouponType::Fixed,
                'value' => 50.00,
                'max_discount' => null,
                'min_order_total' => 400.00,
                'total_usage_limit' => null,
                'usage_per_customer_limit' => 2,
                'is_active' => true,
            ],
            [
                'code' => 'FREESHIP',
                'type' => CouponType::Shipping,
                'value' => 0.00,
                'max_discount' => null,
                'min_order_total' => 600.00,
                'total_usage_limit' => 200,
                'usage_per_customer_limit' => 1,
                'is_active' => true,
            ],
        ];

        foreach ($coupons as $data) {
            Coupon::firstOrCreate(['code' => $data['code']], $data);
        }
    }
}

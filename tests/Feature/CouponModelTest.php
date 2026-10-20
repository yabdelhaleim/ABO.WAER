<?php

namespace Tests\Feature;

use App\Enums\CouponType;
use App\Models\Coupon;
use Database\Seeders\CouponSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CouponModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_coupon_can_be_created_with_factory_and_enum(): void
    {
        $coupon = Coupon::factory()->create([
            'code' => 'SUPER10',
            'type' => CouponType::Percentage,
            'value' => 10.00,
        ]);

        $this->assertDatabaseHas('coupons', ['code' => 'SUPER10']);
        $this->assertEquals(CouponType::Percentage, $coupon->type);
    }

    public function test_percentage_discount_calculation_respects_cap(): void
    {
        // 20% خصم بحد أقصى 100 جنيه
        $coupon = Coupon::factory()->create([
            'type' => CouponType::Percentage,
            'value' => 20.00,
            'max_discount' => 100.00,
        ]);

        // طلب بـ 1,000 ج.م -> 20% منها 200 ج.م، لكن السقف 100 ج.م
        $discount = $coupon->calculateDiscount(1000.00);
        $this->assertEquals(100.00, $discount);

        // طلب بـ 300 ج.م -> 20% منها 60 ج.م (أقل من السقف)
        $discountSmall = $coupon->calculateDiscount(300.00);
        $this->assertEquals(60.00, $discountSmall);
    }

    public function test_fixed_discount_calculation(): void
    {
        $coupon = Coupon::factory()->create([
            'type' => CouponType::Fixed,
            'value' => 75.00,
        ]);

        $discount = $coupon->calculateDiscount(500.00);
        $this->assertEquals(75.00, $discount);

        // لا يتعدى إجمالي الطلب لو كان الطلب أصغر من الخصم
        $discountSmall = $coupon->calculateDiscount(50.00);
        $this->assertEquals(50.00, $discountSmall);
    }

    public function test_coupon_validity_checks_minimum_order_total(): void
    {
        $coupon = Coupon::factory()->create([
            'min_order_total' => 500.00,
            'is_active' => true,
        ]);

        $this->assertFalse($coupon->isValidForOrder(400.00));
        $this->assertTrue($coupon->isValidForOrder(600.00));
    }

    public function test_coupon_validity_checks_expiration_and_limits(): void
    {
        $expiredCoupon = Coupon::factory()->create([
            'expires_at' => now()->subDay(),
        ]);
        $this->assertFalse($expiredCoupon->isValidForOrder(500.00));

        $exhaustedCoupon = Coupon::factory()->create([
            'total_usage_limit' => 10,
            'used_count' => 10,
        ]);
        $this->assertFalse($exhaustedCoupon->isValidForOrder(500.00));
    }

    public function test_coupon_seeder_works_correctly(): void
    {
        $this->seed(CouponSeeder::class);

        $this->assertDatabaseHas('coupons', ['code' => 'WELCOME10']);
        $this->assertDatabaseHas('coupons', ['code' => 'FREESHIP']);
    }
}

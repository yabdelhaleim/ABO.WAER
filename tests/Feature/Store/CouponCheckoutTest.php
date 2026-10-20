<?php

namespace Tests\Feature\Store;

use App\Enums\CouponType;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Governorate;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShippingZone;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End-to-end coverage for the coupon code path of the checkout funnel.
 *
 * Three contracts are pinned here:
 *  1. The AJAX validate endpoint (checkout.coupon.validate) accepts a
 *     code + the live cart totals and reports the discount + new total
 *     before the customer commits to the order.
 *  2. Submitting the checkout form with a valid coupon_code actually
 *     reduces the persisted order total, stamps coupon_id / coupon_code
 *     on the row, and bumps the coupon's used_count.
 *  3. Validation failures (unknown code, minimum not met, etc.) come
 *     back as a normal 422 validation envelope, not a 500, so the
 *     client can keep the form in a useful state.
 */
class CouponCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private Governorate $governorate;

    protected function setUp(): void
    {
        parent::setUp();

        $zone = ShippingZone::factory()->create(['fee' => 50.00]);

        $this->governorate = Governorate::factory()->create([
            'shipping_zone_id' => $zone->id,
            'name' => 'Cairo',
            'fee' => 50.00,
        ]);
    }

    public function test_can_validate_active_coupon_via_ajax(): void
    {
        Coupon::factory()->create([
            'code' => 'SAVE20',
            'type' => CouponType::Percentage,
            'value' => 20.00,
            'min_order_total' => 300.00,
        ]);

        $response = $this->postJson(route('checkout.coupon.validate'), [
            'code' => 'save20',
            'subtotal' => 500.00,
            'shipping_fee' => 50.00,
        ]);

        // assertJson does loose type comparison, so JSON-decoded "100"
        // matches the float 100.00 we expect.
        $response->assertOk()
            ->assertJson([
                'success' => true,
                'code' => 'SAVE20',
                'discount' => 100.00,
                'new_total' => 450.00, // (500 + 50) - 100 = 450
            ]);
    }

    public function test_coupon_validation_fails_if_minimum_subtotal_not_met(): void
    {
        Coupon::factory()->create([
            'code' => 'BIGDEAL',
            'min_order_total' => 1000.00,
        ]);

        $response = $this->postJson(route('checkout.coupon.validate'), [
            'code' => 'BIGDEAL',
            'subtotal' => 400.00,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('coupon');
    }

    public function test_order_placement_applies_coupon_and_increments_used_count(): void
    {
        $customer = Customer::factory()->create([
            'name' => 'Mazen Tarek',
            'phone' => '01011223344',
        ]);

        $coupon = Coupon::factory()->create([
            'code' => 'DISCOUNT50',
            'type' => CouponType::Fixed,
            'value' => 50.00,
            'used_count' => 0,
        ]);

        $product = Product::factory()->create(['is_archived' => false]);
        $variant = Variant::factory()->create([
            'product_id' => $product->id,
            'quantity' => 10,
            'price' => 300.00,
        ]);

        CartItem::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 2, // Subtotal = 600
        ]);

        $response = $this->actingAs($customer, 'web')
            ->postJson(route('checkout.process'), [
                'name' => 'Mazen Tarek',
                'phone' => '01011223344',
                'governorate' => 'Cairo',
                'area' => 'Sheraton',
                'address' => 'Building 12',
                'payment_method' => 'cod',
                'coupon_code' => 'DISCOUNT50',
            ]);

        $response->assertCreated();

        // فحص زيادة عداد الاستخدام للكوبون
        $this->assertSame(1, $coupon->fresh()->used_count);

        // فحص حسابات الأوردر (Subtotal: 600 + Shipping: 50 - Discount: 50 = Total: 600)
        $this->assertDatabaseHas('orders', [
            'customer_name' => 'Mazen Tarek',
            'subtotal' => 600.00,
            'shipping_fee' => 50.00,
            'discount_amount' => 50.00,
            'total' => 600.00,
            'coupon_code' => 'DISCOUNT50',
            'coupon_id' => $coupon->id,
        ]);

        // لا يزال cart فيه عناصر؟ تم حذفها بعد الطلب
        $this->assertDatabaseMissing('cart_items', [
            'customer_id' => $customer->id,
        ]);
    }
}

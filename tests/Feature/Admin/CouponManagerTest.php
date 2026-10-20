<?php

namespace Tests\Feature\Admin;

use App\Enums\CouponType;
use App\Models\Admin;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Governorate;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CouponManagerTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create([
            'name' => 'Coupons Admin',
            'email' => 'coupons_admin@store.com',
            'password' => 'password',
            'is_active' => true,
        ]);
    }

    private function actingAsAdmin(): static
    {
        return $this->actingAs($this->admin, 'admin');
    }

    public function test_admin_can_view_coupons_index_with_payload(): void
    {
        Coupon::factory()->count(3)->create();

        $response = $this->actingAsAdmin()
            ->get(route('admin.coupons.index'));

        $response->assertOk()
            ->assertViewIs('admin.coupons.index')
            ->assertViewHas('couponsJson');
    }

    public function test_admin_can_create_percentage_coupon_with_cap(): void
    {
        $response = $this->actingAsAdmin()
            ->postJson(route('admin.coupons.store'), [
                'code' => 'promo25',
                'type' => 'percentage',
                'value' => 25.00,
                'max_discount' => 200.00,
                'min_order_total' => 500.00,
                'total_usage_limit' => 50,
                'usage_per_customer_limit' => 1,
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true);

        // The code is forced to upper-case before persistence.
        $this->assertDatabaseHas('coupons', [
            'code' => 'PROMO25',
            'type' => CouponType::Percentage->value,
            'value' => 25.00,
            'max_discount' => 200.00,
        ]);
    }

    public function test_admin_can_create_fixed_coupon(): void
    {
        $response = $this->actingAsAdmin()
            ->postJson(route('admin.coupons.store'), [
                'code' => 'FIXED100',
                'type' => 'fixed',
                'value' => 100.00,
                'min_order_total' => 1000.00,
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('coupons', [
            'code' => 'FIXED100',
            'type' => CouponType::Fixed->value,
        ]);
    }

    public function test_admin_can_create_free_shipping_coupon(): void
    {
        $response = $this->actingAsAdmin()
            ->postJson(route('admin.coupons.store'), [
                'code' => 'FREESHIP',
                'type' => 'shipping',
                'value' => 0,
                'min_order_total' => 800.00,
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('coupons', [
            'code' => 'FREESHIP',
            'type' => CouponType::Shipping->value,
        ]);
    }

    public function test_cannot_create_duplicate_coupon_code(): void
    {
        Coupon::factory()->create(['code' => 'DUPLICATE10']);

        $response = $this->actingAsAdmin()
            ->postJson(route('admin.coupons.store'), [
                'code' => 'duplicate10',
                'type' => 'fixed',
                'value' => 50.00,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_admin_can_update_coupon(): void
    {
        $coupon = Coupon::factory()->create(['code' => 'OLDCODE']);

        $response = $this->actingAsAdmin()
            ->putJson(route('admin.coupons.update', $coupon), [
                'code' => 'NEWCODE',
                'type' => 'fixed',
                'value' => 100.00,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('coupons', [
            'id' => $coupon->id,
            'code' => 'NEWCODE',
            'value' => 100.00,
        ]);
    }

    public function test_admin_can_update_same_coupon_without_unique_conflict(): void
    {
        $coupon = Coupon::factory()->create(['code' => 'KEEPME', 'value' => 10.00]);

        $response = $this->actingAsAdmin()
            ->putJson(route('admin.coupons.update', $coupon), [
                'code' => 'KEEPME',
                'type' => 'percentage',
                'value' => 15.00,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('coupons', [
            'id' => $coupon->id,
            'code' => 'KEEPME',
            'value' => 15.00,
        ]);
    }

    public function test_admin_can_toggle_coupon_active_status(): void
    {
        $coupon = Coupon::factory()->create(['is_active' => true]);

        $response = $this->actingAsAdmin()
            ->patchJson(route('admin.coupons.toggle', $coupon));

        $response->assertOk()
            ->assertJsonPath('is_active', false);

        $this->assertFalse($coupon->fresh()->is_active);
    }

    public function test_admin_can_delete_unused_coupon(): void
    {
        $coupon = Coupon::factory()->create(['used_count' => 0]);

        $response = $this->actingAsAdmin()
            ->deleteJson(route('admin.coupons.destroy', $coupon));

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('coupons', ['id' => $coupon->id]);
    }

    public function test_admin_cannot_delete_coupon_with_used_count(): void
    {
        $coupon = Coupon::factory()->create(['used_count' => 5]);

        $response = $this->actingAsAdmin()
            ->deleteJson(route('admin.coupons.destroy', $coupon));

        $response->assertStatus(422);

        $this->assertDatabaseHas('coupons', ['id' => $coupon->id]);
    }

    public function test_admin_cannot_delete_coupon_referenced_by_order(): void
    {
        $coupon = Coupon::factory()->create(['used_count' => 0]);

        Order::factory()->create([
            'coupon_id' => $coupon->id,
            'coupon_code' => $coupon->code,
        ]);

        $response = $this->actingAsAdmin()
            ->deleteJson(route('admin.coupons.destroy', $coupon));

        $response->assertStatus(422);

        $this->assertDatabaseHas('coupons', ['id' => $coupon->id]);
    }

    public function test_guest_cannot_access_coupons_index(): void
    {
        $this->get(route('admin.coupons.index'))
            ->assertRedirect();
    }

    public function test_payload_includes_discount_given_from_orders(): void
    {
        $coupon = Coupon::factory()->create(['used_count' => 0]);

        $customer = Customer::factory()->create();
        $governorate = Governorate::factory()->create();

        Order::factory()->count(2)->create([
            'coupon_id' => $coupon->id,
            'coupon_code' => $coupon->code,
            'customer_id' => $customer->id,
            'governorate_id' => $governorate->id,
            'discount_amount' => 75.00,
        ]);

        $response = $this->actingAsAdmin()
            ->get(route('admin.coupons.index'));

        $response->assertOk()
            ->assertViewHas('couponsJson', function (string $json): bool {
                $payload = json_decode($json, true);

                $coupon = $payload[0] ?? null;

                return is_array($coupon)
                    && array_key_exists('discountGiven', $coupon)
                    && (int) $coupon['discountGiven'] === 150;
            });
    }
}

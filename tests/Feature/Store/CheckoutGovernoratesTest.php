<?php

namespace Tests\Feature\Store;

use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Governorate;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShippingZone;
use App\Models\Variant;
use Database\Seeders\ShippingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The governorate drop-down is fed by the database, not by a static
 * list, so an edit in the admin reaches the checkout straight away.
 *
 * These tests pin the three things that has to hold: the table holds
 * the 27 Egyptian governorates, the drop-down shows what the database
 * says, and an order placed with one of them completes.
 */
class CheckoutGovernoratesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_seeder_puts_the_27_governorates_in_the_database(): void
    {
        $this->seed(ShippingSeeder::class);

        $this->assertSame(27, Governorate::query()->count());
        $this->assertSame(27, Governorate::query()->where('is_active', true)->count());

        $names = Governorate::query()->pluck('name')->all();

        $this->assertContains('Cairo', $names);
        $this->assertContains('Alexandria', $names);
        $this->assertContains('New Valley', $names);
        $this->assertContains('South Sinai', $names);

        // Every governorate carries an Arabic name and belongs to a
        // shipping zone — the zone is what carries the delivery fee.
        $this->assertSame(0, Governorate::query()->whereNull('name_ar')->count());
        $this->assertSame(0, Governorate::query()->whereNull('shipping_zone_id')->count());
    }

    public function test_the_seeder_is_idempotent(): void
    {
        $this->seed(ShippingSeeder::class);
        $this->seed(ShippingSeeder::class);
        $this->seed(ShippingSeeder::class);

        $this->assertSame(27, Governorate::query()->count());
        $this->assertSame(5, ShippingZone::query()->count());
    }

    public function test_the_drop_down_lists_the_governorates_from_the_database(): void
    {
        $zone = ShippingZone::factory()->create(['name' => 'Test Zone']);

        Governorate::factory()->create([
            'shipping_zone_id' => $zone->id,
            'name' => 'Test Governorate',
            'is_active' => true,
        ]);

        $response = $this->get(route('checkout.show'));

        $response->assertOk();
        $response->assertSee('value="Test Governorate"', false);
    }

    public function test_a_governorate_added_after_the_page_was_rendered_shows_up(): void
    {
        $zone = ShippingZone::factory()->create(['name' => 'Test Zone']);

        $response = $this->get(route('checkout.show'));
        $response->assertOk();
        $response->assertDontSee('value="Late Arrival"', false);

        Governorate::factory()->create([
            'shipping_zone_id' => $zone->id,
            'name' => 'Late Arrival',
            'is_active' => true,
        ]);

        $response = $this->get(route('checkout.show'));
        $response->assertOk();
        $response->assertSee('value="Late Arrival"', false);
    }

    public function test_an_inactive_governorate_is_not_offered(): void
    {
        $zone = ShippingZone::factory()->create(['name' => 'Test Zone']);

        Governorate::factory()->create([
            'shipping_zone_id' => $zone->id,
            'name' => 'Retired Governorate',
            'is_active' => false,
        ]);

        $response = $this->get(route('checkout.show'));

        $response->assertOk();
        $response->assertDontSee('value="Retired Governorate"', false);
    }

    public function test_an_order_can_be_placed_with_a_governorate_from_the_database(): void
    {
        $zone = ShippingZone::factory()->create(['name' => 'Test Zone', 'fee' => 55.00]);

        $governorate = Governorate::factory()->create([
            'shipping_zone_id' => $zone->id,
            'name' => 'Cairo',
            'fee' => 45.00,
        ]);

        $customer = Customer::factory()->create();

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
            'quantity' => 1,
        ]);

        $response = $this->actingAs($customer, 'web')->postJson(route('checkout.process'), [
            'name' => 'Omar Khalid',
            'phone' => '01012345678',
            'governorate' => 'Cairo',
            'area' => 'Nasr City',
            'address' => 'Abbas El Akkad St',
            'payment_method' => 'cod',
        ]);

        $response->assertCreated()->assertJsonPath('success', true);

        $order = Order::query()->first();

        $this->assertNotNull($order);
        $this->assertSame($governorate->id, $order->governorate_id);
        $this->assertSame('Cairo', $order->governorate_name);
        $this->assertSame(45.00, (float) $order->shipping_fee);
        $this->assertSame(345.00, (float) $order->total);
    }

    public function test_a_governorate_that_inherits_its_zone_fee_is_priced_from_the_zone(): void
    {
        $zone = ShippingZone::factory()->create(['name' => 'Test Zone', 'fee' => 70.00]);

        $governorate = Governorate::factory()->create([
            'shipping_zone_id' => $zone->id,
            'name' => 'Alexandria',
            'fee' => null,
        ]);

        $this->assertSame(70.00, $governorate->effective_fee);
    }
}

<?php

namespace Tests\Feature\Store;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Governorate;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ShippingZone;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HTTP surface of the checkout funnel.
 *
 * Three guarantees sit at the centre of this file:
 *  1. Every successful purchase decrements stock and persists an
 *     order + its items + an initial status-history row, atomically.
 *  2. Insufficient stock short-circuits the request without writing
 *     anything to the database.
 *  3. Guests can complete a purchase using the cart_json payload the
 *     browser maintains when no account is involved.
 *
 * Shipping fees default to the governorate's own fee (45 EGP for
 * Cairo in these fixtures). The free-shipping threshold is left at
 * its default of 0/disabled so the tests assert the fee path.
 */
class CheckoutTest extends TestCase
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
            'fee' => 45.00,
        ]);
    }

    public function test_checkout_page_loads_with_real_governorates(): void
    {
        $response = $this->get(route('checkout.show'));

        $response->assertOk();
        $response->assertViewIs('checkout');

        // The <select> is populated from the governorates table.
        $this->assertStringContainsString('Cairo', $response->getContent());
    }

    public function test_customer_can_successfully_place_order_and_stock_is_decremented(): void
    {
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
            'quantity' => 2,
        ]);

        $response = $this->actingAs($customer, 'web')
            ->postJson(route('checkout.process'), [
                'name' => 'Omar Khalid',
                'phone' => '01012345678',
                'governorate' => 'Cairo',
                'area' => 'Nasr City',
                'address' => 'Abbas El Akkad St',
                'payment_method' => 'cod',
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true);

        // Stock was decremented exactly by the line quantity.
        $this->assertSame(8, $variant->fresh()->quantity);

        // The cart was emptied for this customer.
        $this->assertDatabaseMissing('cart_items', ['customer_id' => $customer->id]);

        // The order, its items, and the initial status history were
        // all written in one transaction.
        $order = Order::query()->where('customer_id', $customer->id)->first();
        $this->assertNotNull($order);
        $this->assertSame('Omar Khalid', $order->customer_name);
        $this->assertSame(600.00, (float) $order->subtotal);
        $this->assertSame(45.00, (float) $order->shipping_fee);
        $this->assertSame(645.00, (float) $order->total);
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame(PaymentStatus::Pending, $order->payment_status);
        $this->assertSame('cod', $order->payment_method);
        $this->assertSame($this->governorate->id, $order->governorate_id);

        $this->assertCount(1, $order->items);
        $this->assertSame($variant->id, $order->items->first()->variant_id);
        $this->assertSame(2, $order->items->first()->quantity);
        $this->assertSame(600.00, (float) $order->items->first()->total_price);

        $this->assertCount(1, $order->statusHistory);
        $this->assertSame(OrderStatus::Pending, $order->statusHistory->first()->status);
    }

    public function test_order_fails_when_variant_stock_is_insufficient(): void
    {
        $customer = Customer::factory()->create();

        $product = Product::factory()->create(['is_archived' => false]);
        $variant = Variant::factory()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 400.00,
        ]);

        CartItem::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 3,
        ]);

        $response = $this->actingAs($customer, 'web')
            ->postJson(route('checkout.process'), [
                'name' => 'Ali Hassan',
                'phone' => '01087654321',
                'governorate' => 'Cairo',
                'area' => 'Maadi',
                'address' => 'Street 9',
                'payment_method' => 'cod',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('stock');

        // Nothing was written — the transaction rolled back.
        $this->assertSame(1, $variant->fresh()->quantity);
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, OrderItem::query()->count());

        // The cart line was NOT cleared: a rejection must leave the
        // shopper's cart intact so they can adjust the quantity.
        $this->assertDatabaseHas('cart_items', [
            'customer_id' => $customer->id,
            'variant_id' => $variant->id,
            'quantity' => 3,
        ]);
    }

    public function test_guest_can_place_order_using_cart_json(): void
    {
        $product = Product::factory()->create(['is_archived' => false]);
        $variant = Variant::factory()->create([
            'product_id' => $product->id,
            'quantity' => 5,
            'price' => 250.00,
        ]);

        $cartJson = json_encode([
            ['variant_id' => $variant->id, 'qty' => 1],
        ]);

        $response = $this->postJson(route('checkout.process'), [
            'name' => 'Guest Customer',
            'phone' => '01055554444',
            'governorate' => 'Cairo',
            'area' => 'Zamalek',
            'address' => '26th of July St',
            'payment_method' => 'cod',
            'cart_json' => $cartJson,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertSame(4, $variant->fresh()->quantity);

        $this->assertDatabaseHas('orders', [
            'customer_name' => 'Guest Customer',
            'customer_phone' => '01055554444',
            'total' => 295.00,
            'shipping_fee' => 45.00,
            'subtotal' => 250.00,
        ]);

        // Guests leave no customer_id behind.
        $order = Order::query()->where('customer_name', 'Guest Customer')->first();
        $this->assertNull($order->customer_id);
        $this->assertCount(1, $order->statusHistory);
        $this->assertSame('Guest', $order->statusHistory->first()->changed_by_type);
    }

    public function test_validation_rejects_an_invalid_phone(): void
    {
        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['is_archived' => false]);
        $variant = Variant::factory()->create([
            'product_id' => $product->id,
            'quantity' => 5,
        ]);

        CartItem::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 1,
        ]);

        $response = $this->actingAs($customer, 'web')
            ->postJson(route('checkout.process'), [
                'name' => 'Bad Phone',
                'phone' => '12345',
                'governorate' => 'Cairo',
                'area' => 'Heliopolis',
                'address' => 'Some St',
                'payment_method' => 'cod',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('phone');

        // No order, no stock decrement, no cart cleared.
        $this->assertSame(5, $variant->fresh()->quantity);
        $this->assertSame(0, Order::query()->count());
        $this->assertDatabaseHas('cart_items', ['variant_id' => $variant->id]);
    }

    public function test_validation_accepts_an_unknown_governorate_and_falls_back_to_default_fee(): void
    {
        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['is_archived' => false]);
        $variant = Variant::factory()->create([
            'product_id' => $product->id,
            'quantity' => 5,
            'price' => 100.00,
        ]);

        CartItem::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 1,
        ]);

        $response = $this->actingAs($customer, 'web')
            ->postJson(route('checkout.process'), [
                'name' => 'Bad Gov',
                'phone' => '01011112222',
                'governorate' => 'Atlantis',
                'area' => 'X',
                'address' => 'Y',
                'payment_method' => 'cod',
            ]);

        $response->assertCreated();

        // The order falls back to the global default fee when no
        // governorate matches. The chosen name is preserved on the
        // order so the shipping team can still see what the
        // customer typed.
        $order = Order::query()->first();
        $this->assertNotNull($order);
        $this->assertNull($order->governorate_id);
        $this->assertSame('Atlantis', $order->governorate_name);
        $this->assertSame(100.00, (float) $order->subtotal);
    }

    public function test_order_redirects_for_non_json_submissions(): void
    {
        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['is_archived' => false]);
        $variant = Variant::factory()->create([
            'product_id' => $product->id,
            'quantity' => 5,
            'price' => 100.00,
        ]);

        CartItem::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 1,
        ]);

        $response = $this->actingAs($customer, 'web')
            ->post(route('checkout.process'), [
                'name' => 'Form Poster',
                'phone' => '01000000000',
                'governorate' => 'Cairo',
                'area' => 'A',
                'address' => 'B',
                'payment_method' => 'cod',
            ]);

        $response->assertRedirect();

        $order = Order::query()->where('customer_name', 'Form Poster')->first();
        $this->assertNotNull($order);
        $this->assertStringStartsWith('ABO-', $order->order_number);
        $this->assertStringEndsWith($order->order_number, $response->headers->get('Location'));
    }
}

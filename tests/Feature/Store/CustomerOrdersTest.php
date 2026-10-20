<?php

namespace Tests\Feature\Store;

use App\Enums\OrderStatus;
use App\Models\CancellationReason;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HTTP surface of the customer's own orders: /orders (the page shell),
 * /orders/data (the JSON the page hydrates the stepper from), and
 * /orders/{order}/cancel.
 *
 * Three invariants run through all of it — a customer only ever sees their
 * own orders, they only ever act on their own orders, and the step index the
 * payload carries is the one resources/js/orders.js renders (Placed / Packed /
 * Shipped / Out for delivery / Delivered) rather than a count of statuses.
 */
class CustomerOrdersTest extends TestCase
{
    use RefreshDatabase;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = Customer::factory()->create();
    }

    // ─── The page ─────────────────────────────────────────────────

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('orders'))->assertRedirect(route('login'));
        $this->getJson(route('orders.data'))->assertUnauthorized();
    }

    public function test_customer_can_view_the_orders_page(): void
    {
        $this->actingAs($this->customer)
            ->get(route('orders'))
            ->assertOk()
            ->assertViewIs('my-orders')
            ->assertViewHas('announcement');
    }

    public function test_blocked_customer_is_logged_out_and_redirected(): void
    {
        $blocked = Customer::factory()->blocked()->create();

        $this->actingAs($blocked)
            ->get(route('orders'))
            ->assertRedirect(route('login'));
    }

    // ─── Reading the list ─────────────────────────────────────────

    public function test_customer_sees_only_their_own_orders(): void
    {
        $mine = $this->orderFor($this->customer);
        $theirs = $this->orderFor(Customer::factory()->create());

        $response = $this->actingAs($this->customer)->getJson(route('orders.data'));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'orders')
            ->assertJsonPath('orders.0.id', $mine->order_number);

        $this->assertNotContains($theirs->order_number, array_column($response->json('orders'), 'id'));
    }

    public function test_customer_with_no_orders_gets_an_empty_list(): void
    {
        $this->actingAs($this->customer)
            ->getJson(route('orders.data'))
            ->assertOk()
            ->assertJsonPath('orders', []);
    }

    public function test_payload_carries_the_fields_the_stepper_renders(): void
    {
        $order = $this->orderFor($this->customer, OrderStatus::Shipped, ['shipping_fee' => 60]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_name' => 'Classic Oxford Shirt',
            'color_name' => 'Black',
            'size_name' => 'L',
            'quantity' => 1,
            'unit_price' => 500,
        ]);

        $this->actingAs($this->customer)
            ->getJson(route('orders.data'))
            ->assertOk()
            ->assertJsonPath('orders.0.id', $order->order_number)
            ->assertJsonPath('orders.0.cancelled', false)
            ->assertJsonPath('orders.0.shipping', 60)
            ->assertJsonPath('orders.0.payment', 'COD')
            ->assertJsonStructure([
                'orders' => [[
                    'id', 'date', 'dateLabel', 'step', 'cancelled', 'etaText',
                    'shipping', 'payment', 'address', 'returnOpen',
                    'items' => [['name', 'variant', 'qty', 'price']],
                    'timeline' => [['title', 'time', 'status']],
                ]],
            ])
            ->assertJsonPath('orders.0.items.0.name', 'Classic Oxford Shirt')
            ->assertJsonPath('orders.0.items.0.variant', 'L · Black')
            ->assertJsonCount(6, 'orders.0.timeline');
    }

    /**
     * The step index keys the stepper labels and the status badge, so each
     * stored status has to land on the step the UI calls that name.
     */
    public function test_each_status_maps_to_the_stepper_step_the_ui_labels(): void
    {
        $expected = [
            OrderStatus::Pending->value => 0,       // "Placed"
            OrderStatus::Confirmed->value => 1,     // "Packed"
            OrderStatus::Processing->value => 1,    // "Packed"
            OrderStatus::Shipped->value => 2,       // "Shipped"
            OrderStatus::Delivered->value => 4,     // "Delivered"
        ];

        foreach ($expected as $status => $step) {
            $order = $this->orderFor($this->customer, OrderStatus::from($status));

            $this->actingAs($this->customer)
                ->getJson(route('orders.data'))
                ->assertJsonPath('orders.0.id', $order->order_number)
                ->assertJsonPath('orders.0.step', $step);
        }
    }

    public function test_cancelled_order_is_flagged_and_says_no_refund_yet(): void
    {
        $order = $this->orderFor($this->customer, OrderStatus::Cancelled, ['payment_status' => 'pending']);

        $this->actingAs($this->customer)
            ->getJson(route('orders.data'))
            ->assertJsonPath('orders.0.cancelled', true)
            ->assertJsonPath('orders.0.etaText', 'Cancelled by you · refund on delivery');
    }

    public function test_delivered_order_opens_the_return_window_for_fourteen_days(): void
    {
        $fresh = $this->orderFor($this->customer, OrderStatus::Delivered, [
            'created_at' => now()->subDays(3),
            'updated_at' => now()->subDays(3),
        ]);
        $stale = $this->orderFor($this->customer, OrderStatus::Delivered, [
            'created_at' => now()->subDays(40),
            'updated_at' => now()->subDays(40),
        ]);

        $response = $this->actingAs($this->customer)->getJson(route('orders.data'));

        $byId = collect($response->json('orders'))->keyBy('id');

        $this->assertTrue($byId[$fresh->order_number]['returnOpen']);
        $this->assertFalse($byId[$stale->order_number]['returnOpen']);
    }

    public function test_timeline_timestamps_come_from_the_status_history(): void
    {
        $order = $this->orderFor($this->customer, OrderStatus::Shipped);
        $this->recordStatus($order, OrderStatus::Pending, now()->subDays(4));
        $this->recordStatus($order, OrderStatus::Shipped, now()->subDays(1));

        $timeline = collect(
            $this->actingAs($this->customer)->getJson(route('orders.data'))->json('orders.0.timeline')
        )->keyBy('title');

        $this->assertSame('done', $timeline['Order placed']['status']);
        $this->assertSame(now()->subDays(4)->format('M d, Y · H:i'), $timeline['Order placed']['time']);
        $this->assertSame(now()->subDays(1)->format('M d, Y · H:i'), $timeline['Shipped']['time']);

        // Not reached yet, so the row is pending with no time at all.
        $this->assertSame('pending', $timeline['Delivered']['status']);
        $this->assertSame('—', $timeline['Delivered']['time']);
    }

    // ─── Cancelling ───────────────────────────────────────────────

    public function test_customer_can_cancel_a_pending_order_and_stock_comes_back(): void
    {
        $variant = $this->variantWithStock(4);
        $order = $this->orderFor($this->customer, OrderStatus::Pending);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'variant_id' => $variant->id,
            'quantity' => 2,
        ]);

        $this->actingAs($this->customer)
            ->postJson(route('orders.cancel', $order))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(6, $variant->fresh()->quantity);
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
    }

    public function test_cancelling_records_the_customer_in_the_audit_trail(): void
    {
        $reason = CancellationReason::factory()->create(['reason' => 'Changed my mind']);
        $order = $this->orderFor($this->customer, OrderStatus::Pending);

        $this->actingAs($this->customer)
            ->postJson(route('orders.cancel', $order), [
                'cancellation_reason_id' => $reason->id,
                'cancellation_note' => 'Ordered the wrong size',
            ])
            ->assertOk();

        $order->refresh();

        $this->assertSame($reason->id, $order->cancellation_reason_id);
        $this->assertSame('Ordered the wrong size', $order->cancellation_note);

        $entry = $order->statusHistory()->where('status', OrderStatus::Cancelled)->firstOrFail();

        $this->assertSame('Customer', $entry->changed_by_type);
        $this->assertSame($this->customer->id, $entry->changed_by_id);
        $this->assertStringContainsString('Changed my mind', $entry->comment);
    }

    public function test_customer_cannot_cancel_an_order_that_has_moved_on(): void
    {
        $variant = $this->variantWithStock(4);
        $order = $this->orderFor($this->customer, OrderStatus::Shipped);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'variant_id' => $variant->id,
            'quantity' => 2,
        ]);

        $this->actingAs($this->customer)
            ->postJson(route('orders.cancel', $order))
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(OrderStatus::Shipped, $order->fresh()->status);
        $this->assertSame(4, $variant->fresh()->quantity, 'Stock must not move for a shipped order.');
    }

    public function test_a_delivered_order_cannot_be_cancelled(): void
    {
        $order = $this->orderFor($this->customer, OrderStatus::Delivered);

        $this->actingAs($this->customer)
            ->postJson(route('orders.cancel', $order))
            ->assertStatus(422);

        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
    }

    public function test_a_second_cancel_does_not_return_the_stock_twice(): void
    {
        $variant = $this->variantWithStock(4);
        $order = $this->orderFor($this->customer, OrderStatus::Pending);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'variant_id' => $variant->id,
            'quantity' => 2,
        ]);

        $this->actingAs($this->customer)->postJson(route('orders.cancel', $order))->assertOk();
        $this->actingAs($this->customer)->postJson(route('orders.cancel', $order))->assertStatus(422);

        $this->assertSame(6, $variant->fresh()->quantity);
    }

    public function test_customer_cannot_cancel_another_customers_order(): void
    {
        $order = $this->orderFor(Customer::factory()->create(), OrderStatus::Pending);

        $this->actingAs($this->customer)
            ->postJson(route('orders.cancel', $order))
            ->assertNotFound();
    }

    public function test_cancel_rejects_a_reason_that_does_not_exist(): void
    {
        $order = $this->orderFor($this->customer, OrderStatus::Pending);

        $this->actingAs($this->customer)
            ->postJson(route('orders.cancel', $order), ['cancellation_reason_id' => 9999])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cancellation_reason_id');

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    // ─── Helpers ──────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function orderFor(Customer $customer, OrderStatus $status = OrderStatus::Pending, array $attributes = []): Order
    {
        return Order::factory()->create([
            'customer_id' => $customer->id,
            'status' => $status,
            ...$attributes,
        ]);
    }

    protected function variantWithStock(int $quantity): Variant
    {
        return Variant::factory()->create([
            'product_id' => Product::factory()->create()->id,
            'quantity' => $quantity,
        ]);
    }

    protected function recordStatus(Order $order, OrderStatus $status, \DateTimeInterface $at): void
    {
        $order->statusHistory()->create([
            'status' => $status,
            'comment' => 'Test transition to '.$status->value,
            'changed_by_type' => 'Admin',
            'changed_by_id' => null,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }
}

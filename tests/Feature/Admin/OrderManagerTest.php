<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Admin;
use App\Models\CancellationReason;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderManagerTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create([
            'name' => 'Orders Admin',
            'email' => 'orders_admin@store.com',
            'password' => 'password',
            'is_active' => true,
        ]);
    }

    private function actingAsAdmin(): static
    {
        return $this->actingAs($this->admin, 'admin');
    }

    public function test_admin_can_view_orders_index_page(): void
    {
        Order::factory()->count(2)->create();

        $response = $this->actingAsAdmin()->get(route('admin.orders.index'));

        $response->assertOk()
            ->assertViewIs('admin.orders.index')
            ->assertViewHas('ordersJson')
            ->assertViewHas('governorates');
    }

    /**
     * public/admin/js/orders.js reads the payload without guarding most
     * of it, so a renamed field shows up as the literal text
     * "undefined" or "EGP NaN" rather than an error. This locks the
     * contract the front-end depends on.
     */
    public function test_payload_uses_the_field_names_orders_js_reads(): void
    {
        $order = Order::factory()->create([
            'status' => OrderStatus::Pending,
            'payment_status' => PaymentStatus::Paid,
            'payment_method' => 'cod',
            'subtotal' => 300.00,
            'shipping_fee' => 50.00,
            'discount_amount' => 0.00,
            'total' => 350.00,
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_name' => 'Boxy T-Shirt',
            'color_name' => 'Black',
            'size_name' => 'L',
            'quantity' => 3,
            'unit_price' => 100.00,
            'total_price' => 300.00,
        ]);

        $order->statusHistory()->create([
            'status' => OrderStatus::Pending,
            'comment' => 'Order placed successfully by customer.',
            'changed_by_type' => 'Customer',
        ]);

        $payload = $this->firstOrderFromIndex();

        // orders.js matches by strict === against a DOM attribute, so
        // the id has to arrive as a string.
        $this->assertSame($order->order_number, $payload['id']);

        // Compared as a raw string against `dateFrom + 'T00:00:00'`,
        // so no timezone suffix and no fractional seconds.
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/',
            $payload['createdAt'],
        );

        // Money is read straight off the order and formatted with
        // Intl.NumberFormat — flat, numeric, and not nested.
        foreach (['subtotal', 'shipping', 'discount', 'total'] as $key) {
            $this->assertArrayHasKey($key, $payload);
            $this->assertIsNumeric($payload[$key]);
        }
        // json_encode drops the ".0" from a whole float, so this arrives
        // as the JSON number 350 — which is what the formatter and the
        // Total sort both need.
        $this->assertEquals(350, $payload['total']);
        $this->assertArrayNotHasKey('totals', $payload);

        $this->assertIsString($payload['customer']['phone']);
        $this->assertArrayHasKey('address', $payload['customer']);
        $this->assertIsString($payload['deliveryMethod']);
        $this->assertIsInt($payload['itemsCount']);

        $this->assertSame('paid', $payload['payment']['status']);
        $this->assertSame('new', $payload['status']);

        $this->assertCount(1, $payload['items']);
        $this->assertSame('Boxy T-Shirt', $payload['items'][0]['name']);
        $this->assertSame(3, $payload['items'][0]['qty']);
        $this->assertIsNumeric($payload['items'][0]['unitPrice']);
        $this->assertIsNumeric($payload['items'][0]['lineTotal']);

        $this->assertCount(1, $payload['timeline']);
        $this->assertSame('New', $payload['timeline'][0]['label']);
        $this->assertIsArray($payload['notes']);
    }

    /**
     * `pending` and `confirmed` have no tab, badge or drawer option in
     * orders.js, so they are translated on the way out.
     */
    public function test_stored_statuses_are_translated_into_frontend_buckets(): void
    {
        $pending = Order::factory()->create(['status' => OrderStatus::Pending]);
        $confirmed = Order::factory()->create(['status' => OrderStatus::Confirmed]);
        $delivered = Order::factory()->create(['status' => OrderStatus::Delivered]);

        $buckets = collect($this->ordersFromIndex())->keyBy('id');

        $this->assertSame('new', $buckets[$pending->order_number]['status']);
        $this->assertSame('processing', $buckets[$confirmed->order_number]['status']);
        $this->assertSame('delivered', $buckets[$delivered->order_number]['status']);
    }

    public function test_admin_can_update_order_status(): void
    {
        $order = Order::factory()->create(['status' => OrderStatus::Pending]);

        $response = $this->actingAsAdmin()->patchJson(route('admin.orders.status', $order), [
            'status' => 'processing',
            'comment' => 'Preparing items in warehouse',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'processing');

        $this->assertSame(OrderStatus::Processing, $order->fresh()->status);

        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'status' => 'processing',
            'comment' => 'Preparing items in warehouse',
            'changed_by_type' => 'Admin',
            'changed_by_id' => $this->admin->id,
        ]);
    }

    public function test_updating_status_without_a_comment_writes_a_default_one(): void
    {
        $order = Order::factory()->create(['status' => OrderStatus::Pending]);

        $this->actingAsAdmin()
            ->patchJson(route('admin.orders.status', $order), ['status' => 'shipped'])
            ->assertOk();

        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'status' => 'shipped',
            'comment' => 'Status changed to Shipped by admin.',
        ]);
    }

    /**
     * The browser addresses orders by their order number, because that
     * string is the payload's `id`.
     */
    public function test_orders_can_be_addressed_by_order_number(): void
    {
        $order = Order::factory()->create(['status' => OrderStatus::Pending]);

        $this->actingAsAdmin()
            ->patchJson(route('admin.orders.status', ['order' => $order->order_number]), [
                'status' => 'shipped',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'shipped');

        $this->assertSame(OrderStatus::Shipped, $order->fresh()->status);
    }

    public function test_unknown_status_is_rejected(): void
    {
        $order = Order::factory()->create();

        $this->actingAsAdmin()
            ->patchJson(route('admin.orders.status', $order), ['status' => 'teleported'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    public function test_cancelling_order_returns_quantities_back_to_variant_stock(): void
    {
        [$order, $variant] = $this->orderWithStock(5, 3);

        $reason = CancellationReason::factory()->create(['reason' => 'Customer requested cancel']);

        $this->actingAsAdmin()
            ->postJson(route('admin.orders.cancel', $order), [
                'cancellation_reason_id' => $reason->id,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(8, $variant->fresh()->quantity);

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame($reason->id, $order->cancellation_reason_id);

        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'status' => 'cancelled',
            'changed_by_type' => 'Admin',
        ]);
    }

    /**
     * Cancelling twice must not hand the same stock back twice.
     */
    public function test_cancelling_an_already_cancelled_order_does_not_restock(): void
    {
        [$order, $variant] = $this->orderWithStock(5, 3);

        $this->actingAsAdmin()->postJson(route('admin.orders.cancel', $order))->assertOk();
        $this->actingAsAdmin()
            ->postJson(route('admin.orders.cancel', $order))
            ->assertOk()
            ->assertJsonPath('message', 'Order was already cancelled.');

        $this->assertSame(8, $variant->fresh()->quantity);
    }

    /**
     * The drawer offers "Cancelled" in its status dropdown as well as
     * through the cancel button, and both have to put stock back.
     */
    public function test_setting_the_status_to_cancelled_also_returns_stock(): void
    {
        [$order, $variant] = $this->orderWithStock(4, 2);

        $this->actingAsAdmin()
            ->patchJson(route('admin.orders.status', $order), ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');

        $this->assertSame(6, $variant->fresh()->quantity);
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
    }

    public function test_cancelling_records_a_free_text_reason(): void
    {
        [$order] = $this->orderWithStock(1, 1);

        $this->actingAsAdmin()
            ->postJson(route('admin.orders.cancel', $order), [
                'cancellation_note' => 'Found a better price elsewhere',
            ])
            ->assertOk();

        $order->refresh();
        $this->assertSame('Found a better price elsewhere', $order->cancellation_note);

        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'status' => 'cancelled',
            'comment' => 'Order cancelled by admin. Reason: Found a better price elsewhere',
        ]);
    }

    public function test_admin_can_update_tracking_number(): void
    {
        $order = Order::factory()->create(['tracking_number' => null]);

        $this->actingAsAdmin()
            ->patchJson(route('admin.orders.tracking', $order), [
                'tracking_number' => 'BSTA-987654321',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('BSTA-987654321', $order->fresh()->tracking_number);
    }

    public function test_tracking_number_can_be_cleared(): void
    {
        $order = Order::factory()->create(['tracking_number' => 'BSTA-1']);

        $this->actingAsAdmin()
            ->patchJson(route('admin.orders.tracking', $order), ['tracking_number' => null])
            ->assertOk();

        $this->assertNull($order->fresh()->tracking_number);
    }

    public function test_guest_cannot_access_admin_orders(): void
    {
        $this->get(route('admin.orders.index'))->assertRedirect();
    }

    public function test_guest_cannot_mutate_orders(): void
    {
        $order = Order::factory()->create(['status' => OrderStatus::Pending]);

        // The admin group redirects an unauthenticated caller to the
        // admin login rather than answering 401.
        $this->patchJson(route('admin.orders.status', $order), ['status' => 'delivered'])
            ->assertRedirect(route('admin.login'));
        $this->postJson(route('admin.orders.cancel', $order))->assertRedirect();
        $this->patchJson(route('admin.orders.tracking', $order), ['tracking_number' => 'X'])
            ->assertRedirect();

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertNull($order->fresh()->tracking_number);
    }

    /**
     * A pending order with one line item against a known stock level.
     *
     * @return array{0: Order, 1: Variant}
     */
    private function orderWithStock(int $stock, int $quantity): array
    {
        $product = Product::factory()->create(['is_archived' => false]);

        $variant = Variant::factory()->create([
            'product_id' => $product->id,
            'quantity' => $stock,
        ]);

        $order = Order::factory()->create(['status' => OrderStatus::Pending]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'variant_id' => $variant->id,
            'quantity' => $quantity,
        ]);

        return [$order, $variant];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function ordersFromIndex(): array
    {
        $response = $this->actingAsAdmin()->get(route('admin.orders.index'));

        $response->assertOk();

        return json_decode($response->viewData('ordersJson'), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function firstOrderFromIndex(): array
    {
        return $this->ordersFromIndex()[0];
    }
}

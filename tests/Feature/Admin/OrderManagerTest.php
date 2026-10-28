<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Admin;
use App\Models\CancellationReason;
use App\Models\Customer;
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

    // ─── Exporting the orders ────────────────────────────────────────────

    /**
     * The export is a file the admin opens in a spreadsheet, so it
     * carries the order's money, its items and the coupon it was
     * placed with.
     */
    public function test_the_export_writes_the_orders_as_csv(): void
    {
        $order = Order::factory()->create([
            'customer_name' => 'Layla Hassan',
            'governorate_name' => 'Cairo',
            'subtotal' => 300.00,
            'shipping_fee' => 50.00,
            'discount_amount' => 50.00,
            'total' => 300.00,
            'coupon_code' => 'SUMMER20',
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

        $response = $this->actingAsAdmin()->get(route('admin.orders.export'));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));

        $csv = $response->streamedContent();

        $this->assertStringContainsString('Order number', $csv);
        $this->assertStringContainsString($order->order_number, $csv);
        $this->assertStringContainsString('Layla Hassan', $csv);
        $this->assertStringContainsString('Cairo', $csv);
        // The line items travel with the order.
        $this->assertStringContainsString('Boxy T-Shirt (Black / L) x3', $csv);
        // The coupon the customer used is on the row.
        $this->assertStringContainsString('SUMMER20', $csv);
    }

    /**
     * The export answers the filters the screen is showing, so the file
     * holds the rows the admin is looking at.
     */
    public function test_the_export_carries_the_filters_the_screen_is_showing(): void
    {
        $delivered = Order::factory()->create(['status' => OrderStatus::Delivered]);
        $pending = Order::factory()->create(['status' => OrderStatus::Pending]);

        $csv = $this->actingAsAdmin()
            ->get(route('admin.orders.export', ['status' => 'delivered']))
            ->streamedContent();

        $this->assertStringContainsString($delivered->order_number, $csv);
        $this->assertStringNotContainsString($pending->order_number, $csv);
    }

    /**
     * "Processing" is one tab on the screen and two statuses in the
     * database; the export has to carry both.
     */
    public function test_the_processing_bucket_exports_the_confirmed_orders_too(): void
    {
        $confirmed = Order::factory()->create(['status' => OrderStatus::Confirmed]);
        $shipped = Order::factory()->create(['status' => OrderStatus::Shipped]);

        $csv = $this->actingAsAdmin()
            ->get(route('admin.orders.export', ['status' => 'processing']))
            ->streamedContent();

        $this->assertStringContainsString($confirmed->order_number, $csv);
        $this->assertStringNotContainsString($shipped->order_number, $csv);
    }

    /**
     * The search box on the screen looks at the order number, the
     * customer name and the phone number.
     */
    public function test_the_export_searches_the_order_number_and_the_customer(): void
    {
        $mine = Order::factory()->create([
            'order_number' => 'ABO-2026-4242',
            'customer_name' => 'Layla Hassan',
            'customer_phone' => '01000000001',
        ]);
        $other = Order::factory()->create([
            'order_number' => 'ABO-2026-7777',
            'customer_name' => 'Omar Nabil',
            'customer_phone' => '01000000002',
        ]);

        $csv = $this->actingAsAdmin()
            ->get(route('admin.orders.export', ['q' => 'Layla']))
            ->streamedContent();

        $this->assertStringContainsString($mine->order_number, $csv);
        $this->assertStringNotContainsString($other->order_number, $csv);
    }

    /**
     * A customer whose name begins with "=" would be run as a formula
     * by the spreadsheet that opens the file.
     */
    public function test_the_export_escapes_a_cell_a_spreadsheet_would_run(): void
    {
        Order::factory()->create(['customer_name' => '=cmd|calc']);

        $csv = $this->actingAsAdmin()
            ->get(route('admin.orders.export'))
            ->streamedContent();

        $this->assertStringContainsString("'=cmd|calc", $csv);
    }

    public function test_guest_cannot_export_the_orders(): void
    {
        $this->get(route('admin.orders.export'))->assertRedirect(route('admin.login'));
    }

    /**
     * The drawer prints the coupon beside the discount, so the code the
     * customer used has to travel with the order.
     */
    public function test_the_payload_carries_the_coupon_code(): void
    {
        Order::factory()->create([
            'coupon_code' => 'SUMMER20',
            'discount_amount' => 50.00,
        ]);

        $this->assertSame('SUMMER20', $this->firstOrderFromIndex()['coupon']);
    }

    /**
     * An order without a coupon carries an empty string, so the drawer
     * can ask `o.coupon !== ''` without guarding for null.
     */
    public function test_an_order_without_a_coupon_carries_an_empty_string(): void
    {
        Order::factory()->create(['coupon_code' => null]);

        $this->assertSame('', $this->firstOrderFromIndex()['coupon']);
    }

    // ─── Narrowing the screen to one customer ────────────────────────────

    /**
     * The customers screen sends you here with ?customer=<id> — and the
     * screen answers with that customer's orders and no others.
     */
    public function test_the_screen_can_be_narrowed_to_one_customer(): void
    {
        $customer = Customer::factory()->create(['name' => 'Layla Hassan']);
        $other = Customer::factory()->create(['name' => 'Omar Nabil']);

        $mine = Order::factory()->create(['customer_id' => $customer->id]);
        $theirs = Order::factory()->create(['customer_id' => $other->id]);

        $response = $this->actingAsAdmin()
            ->get(route('admin.orders.index', ['customer' => $customer->id]));

        $response->assertOk();

        $payload = json_decode($response->viewData('ordersJson'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertCount(1, $payload);
        $this->assertSame($mine->order_number, $payload[0]['id']);
        $this->assertNotContains($theirs->order_number, array_column($payload, 'id'));

        // The chip says whose orders these are.
        $this->assertSame(
            ['id' => (string) $customer->id, 'name' => 'Layla Hassan'],
            $response->viewData('customerFilter'),
        );
    }

    /**
     * The payload carries the customer id, so the browser can narrow
     * the list it already has.
     */
    public function test_the_payload_carries_the_customer_id(): void
    {
        $customer = Customer::factory()->create();
        Order::factory()->create(['customer_id' => $customer->id]);

        $this->assertSame(
            (string) $customer->id,
            $this->firstOrderFromIndex()['customer']['id'],
        );
    }

    /**
     * An id that belongs to no customer is ignored: a stale link shows
     * the whole list rather than an error.
     */
    public function test_an_unknown_customer_filter_is_ignored(): void
    {
        Order::factory()->create();

        $response = $this->actingAsAdmin()
            ->get(route('admin.orders.index', ['customer' => 999999]));

        $response->assertOk();

        $this->assertNull($response->viewData('customerFilter'));
        $this->assertCount(1, json_decode($response->viewData('ordersJson'), true));
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

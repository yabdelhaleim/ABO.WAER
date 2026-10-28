<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The printable documents the orders screen produces.
 *
 * The screen used to answer "Invoice ready (demo)" and "Labels ready
 * (demo)" — two toasts and no document. The invoice and the labels
 * sheet are server-rendered pages now, and they are shaped by the
 * receipt settings the store saved: the paper, the logo, the header
 * and footer wording, and which rows the invoice carries at all.
 */
class OrderPrintTest extends TestCase
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

    // ─── The invoice ───────────────────────────────────────────────────────

    /**
     * The invoice carries the order's own rows: the customer, the items
     * and the money. Nothing on it is invented.
     */
    public function test_the_invoice_carries_the_order_and_its_items(): void
    {
        $order = Order::factory()->create([
            'customer_name' => 'Layla Hassan',
            'customer_phone' => '01000000001',
            'governorate_name' => 'Cairo',
            'area' => 'Nasr City',
            'address' => '10 Mohamed Farid',
            'subtotal' => 300.00,
            'shipping_fee' => 50.00,
            'discount_amount' => 50.00,
            'total' => 300.00,
            'payment_method' => 'cod',
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

        $response = $this->actingAsAdmin()->get(route('admin.orders.invoice', $order));

        $response->assertOk()->assertViewIs('admin.orders.print.invoice');

        $response->assertSee('Layla Hassan');
        $response->assertSee('01000000001');
        $response->assertSee('Boxy T-Shirt');
        $response->assertSee('Black / L');
        $response->assertSee('300.00 EGP');
        $response->assertSee('Cash on delivery');
    }

    /**
     * The browser addresses orders by their order number, so the print
     * URL has to accept it as well as the id.
     */
    public function test_the_invoice_can_be_addressed_by_order_number(): void
    {
        $order = Order::factory()->create();

        $this->actingAsAdmin()
            ->get(route('admin.orders.invoice', ['order' => $order->order_number]))
            ->assertOk()
            ->assertSee($order->order_number);
    }

    /**
     * The receipt settings are not a picture of a preference — they are
     * the preference. A store that turned the address off gets an
     * invoice without the address on it.
     */
    public function test_the_receipt_settings_decide_which_rows_the_invoice_carries(): void
    {
        $order = Order::factory()->create([
            'customer_phone' => '01000000001',
            'address' => '10 Mohamed Farid',
        ]);

        StoreSetting::set('receipts.fields.address', false);
        StoreSetting::set('receipts.fields.customerPhone', false);
        StoreSetting::set('receipts.fields.orderNumber', false);
        StoreSetting::set('receipts.fields.date', false);

        $response = $this->actingAsAdmin()->get(route('admin.orders.invoice', $order));

        $response->assertOk();
        $response->assertDontSee('01000000001');
        $response->assertDontSee('10 Mohamed Farid');
        $response->assertDontSee($order->order_number);
    }

    /**
     * The paper the settings ask for is the paper the document is laid
     * out for.
     */
    public function test_the_receipt_settings_decide_the_paper(): void
    {
        $order = Order::factory()->create();

        $this->actingAsAdmin()
            ->get(route('admin.orders.invoice', $order))
            ->assertOk()
            ->assertSee('size: A4', escape: false);

        StoreSetting::set('receipts.paper', 'thermal');

        $this->actingAsAdmin()
            ->get(route('admin.orders.invoice', $order))
            ->assertOk()
            ->assertSee('size: 80mm auto', escape: false);
    }

    /**
     * The header wording, the footer note and the shipping row all
     * come from the settings.
     */
    public function test_the_invoice_carries_the_header_and_footer_the_store_wrote(): void
    {
        $order = Order::factory()->create(['shipping_fee' => 50.00]);

        StoreSetting::set('receipts.headerText', 'Thanks for shopping with us');
        StoreSetting::set('receipts.footerNote', 'Exchange within 14 days');

        $response = $this->actingAsAdmin()->get(route('admin.orders.invoice', $order));

        $response->assertOk();
        $response->assertSee('Thanks for shopping with us');
        $response->assertSee('Exchange within 14 days');
        $response->assertSee('Shipping');
    }

    /**
     * A store that turned the shipping row off does not print it.
     */
    public function test_the_shipping_row_can_be_turned_off(): void
    {
        $order = Order::factory()->create(['shipping_fee' => 50.00]);

        StoreSetting::set('receipts.fields.shippingFee', false);

        $this->actingAsAdmin()
            ->get(route('admin.orders.invoice', $order))
            ->assertOk()
            ->assertDontSee('Shipping');
    }

    /**
     * The receipt is handed to a customer: it carries the store's own
     * details, and not the back office's chrome.
     */
    public function test_the_invoice_carries_the_store_details(): void
    {
        StoreSetting::set('store.name', 'ABO Streetwear');
        StoreSetting::set('store.phone', '01000000009');

        $order = Order::factory()->create();

        $response = $this->actingAsAdmin()->get(route('admin.orders.invoice', $order));

        $response->assertOk();
        $response->assertSee('ABO Streetwear');
        $response->assertSee('01000000009');
        // No admin chrome on a document that goes to a customer.
        $response->assertDontSee('abo-admin-topbar');
        $response->assertDontSee('abo-admin-sidebar');
    }

    // ─── The labels sheet ─────────────────────────────────────────────────

    /**
     * One label per selected order, each one carrying that order's own
     * rows.
     */
    public function test_the_labels_sheet_carries_one_label_per_order(): void
    {
        $first = Order::factory()->create([
            'customer_name' => 'Layla Hassan',
            'customer_phone' => '01000000001',
            'governorate_name' => 'Cairo',
            'area' => 'Nasr City',
            'address' => '10 Mohamed Farid',
            'payment_method' => 'cod',
            'total' => 350.00,
        ]);

        $second = Order::factory()->create([
            'customer_name' => 'Omar Nabil',
            'customer_phone' => '01000000002',
            'governorate_name' => 'Giza',
            'area' => 'Dokki',
            'address' => '5 Tahrir',
            'payment_method' => 'cod',
            'total' => 450.00,
        ]);

        $response = $this->actingAsAdmin()->get(route('admin.orders.labels', [
            'orders' => $first->order_number.','.$second->order_number,
        ]));

        $response->assertOk()->assertViewIs('admin.orders.print.labels');

        $response->assertSee('Layla Hassan');
        $response->assertSee('01000000001');
        $response->assertSee('10 Mohamed Farid');
        $response->assertSee('Omar Nabil');
        $response->assertSee('5 Tahrir');
    }

    /**
     * A card order was paid for before it shipped, so the courier is
     * not asked to collect the money a second time.
     */
    public function test_a_paid_order_asks_the_courier_to_collect_nothing(): void
    {
        $cod = Order::factory()->create(['payment_method' => 'cod', 'total' => 350.00]);
        $card = Order::factory()->create(['payment_method' => 'card', 'total' => 450.00]);

        $response = $this->actingAsAdmin()->get(route('admin.orders.labels', [
            'orders' => $cod->order_number.','.$card->order_number,
        ]));

        $response->assertOk();
        $response->assertSee('Collect');
        $response->assertSee('Paid');
        $response->assertSee('350.00 EGP');
        // The card order's total is not on the sheet as a collection.
        $response->assertDontSee('450.00 EGP');
    }

    /**
     * A number that belongs to no order is skipped rather than refused:
     * one stale id in a selection should not cost the whole sheet.
     */
    public function test_an_unknown_order_number_is_skipped(): void
    {
        $order = Order::factory()->create(['customer_name' => 'Layla Hassan']);

        $response = $this->actingAsAdmin()->get(route('admin.orders.labels', [
            'orders' => 'ABO-2026-9999,'.$order->order_number,
        ]));

        $response->assertOk();
        $response->assertSee('Layla Hassan');
        $response->assertDontSee('ABO-2026-9999');
    }

    /**
     * An empty selection is an empty sheet, not an error.
     */
    public function test_the_labels_sheet_without_a_selection_is_empty(): void
    {
        $this->actingAsAdmin()
            ->get(route('admin.orders.labels'))
            ->assertOk()
            ->assertSee('No orders were selected.');
    }

    // ─── The routes the screen uses ───────────────────────────────────────

    /**
     * The orders screen publishes the two print URLs, because the
     * browser addresses orders by their order number and the labels
     * sheet takes the whole selection at once.
     */
    public function test_the_orders_screen_publishes_the_print_urls(): void
    {
        $response = $this->actingAsAdmin()->get(route('admin.orders.index'));

        $response->assertOk()->assertViewHas('routes');

        $routes = $response->viewData('routes');

        $this->assertArrayHasKey('invoice', $routes);
        $this->assertArrayHasKey('labels', $routes);
        $this->assertStringContainsString('__ORDER__', $routes['invoice']['url']);
        $this->assertStringContainsString('/admin/orders/labels', $routes['labels']['url']);
    }

    // ─── The guard ────────────────────────────────────────────────────────

    public function test_a_guest_cannot_print_an_invoice(): void
    {
        $order = Order::factory()->create();

        $this->get(route('admin.orders.invoice', $order))->assertRedirect(route('admin.login'));
    }

    public function test_a_guest_cannot_print_the_labels(): void
    {
        $this->get(route('admin.orders.labels'))->assertRedirect(route('admin.login'));
    }
}

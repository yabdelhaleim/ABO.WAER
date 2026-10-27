<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Enums\OrderStatus;
use App\Models\Admin;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Governorate;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShippingZone;
use App\Models\Variant;
use App\Services\Admin\CustomerService;
use App\Services\CustomerMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The customers screen, and the two things an operator may change
 * about a customer from it.
 *
 * The screen used to be fed by a generator: forty invented customers
 * with invented order counts and a segment rule that lived in the
 * browser's JavaScript. These tests pin the screen to the database —
 * the list is the list, the segment is the one the service decides,
 * and a block is a block that the store front obeys.
 *
 * @see CustomerService
 * @see CustomerMetricsService
 */
class CustomerAdminTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create([
            'name' => 'Customers Admin',
            'email' => 'customers_admin@store.com',
            'password' => 'password',
            'role' => AdminRole::Owner->value,
            'is_active' => true,
        ]);
    }

    private function actingAsAdmin(): static
    {
        return $this->actingAs($this->admin, 'admin');
    }

    /**
     * Place one order for a customer.
     */
    private function placeOrder(
        Customer $customer,
        OrderStatus $status,
        float $total,
    ): Order {
        return Order::factory()->create([
            'customer_id' => $customer->id,
            'status' => $status,
            'total' => $total,
        ]);
    }

    // ─── The list ──────────────────────────────────────────────────────────

    /**
     * The screen shows the customers that are really registered — and
     * only those.
     */
    public function test_the_list_is_the_customers_in_the_database(): void
    {
        Customer::factory()->count(3)->create();

        $response = $this->actingAsAdmin()->get(route('admin.customers.index'));

        $response->assertOk()->assertViewIs('admin.customers.index');

        $this->assertIsArray($response->viewData('customers'));
        $this->assertCount(3, $response->viewData('customers'));
    }

    /**
     * Every customer carries the figures the screen draws: the orders,
     * the delivered spend, and the segment the service decides.
     */
    public function test_a_customer_carries_its_orders_spend_and_segment(): void
    {
        $customer = Customer::factory()->create(['name' => 'Layla Hassan']);
        $this->placeOrder($customer, OrderStatus::Delivered, 2500.00);
        $this->placeOrder($customer, OrderStatus::Cancelled, 900.00);

        $response = $this->actingAsAdmin()->get(route('admin.customers.index'));

        $rows = collect($response->viewData('customers'))
            ->keyBy('id');

        $row = $rows->get((string) $customer->id);

        $this->assertNotNull($row, 'The customer is missing from the payload.');
        $this->assertSame('Layla Hassan', $row['name']);
        $this->assertSame(2, $row['ordersCount']);
        $this->assertSame(1, $row['deliveredCount']);
        // Only the delivered order counts as spend.
        $this->assertSame(2500.0, $row['totalSpent']);
        $this->assertSame(CustomerMetricsService::SEGMENT_VIP, $row['segment']);
    }

    /**
     * The segment comes from the service, not from a rule in the
     * browser: a customer who never ordered is in the no-orders bucket.
     */
    public function test_the_segment_is_the_one_the_metrics_service_decides(): void
    {
        $never = Customer::factory()->create();
        $once = Customer::factory()->create();
        $this->placeOrder($once, OrderStatus::Delivered, 100.00);

        $response = $this->actingAsAdmin()->get(route('admin.customers.index'));

        $segments = collect($response->viewData('customers'))
            ->mapWithKeys(fn (array $row): array => [$row['id'] => $row['segment']]);

        $this->assertSame(
            CustomerMetricsService::SEGMENT_NO_ORDERS,
            $segments->get((string) $never->id),
        );
        $this->assertSame(
            CustomerMetricsService::SEGMENT_NEW,
            $segments->get((string) $once->id),
        );
    }

    /**
     * A blocked customer is shown as blocked, whatever else they are.
     */
    public function test_a_blocked_customer_is_in_the_blocked_segment(): void
    {
        $customer = Customer::factory()->blocked('Chargeback')->create();
        $this->placeOrder($customer, OrderStatus::Delivered, 5000.00);

        $response = $this->actingAsAdmin()->get(route('admin.customers.index'));

        $row = collect($response->viewData('customers'))
            ->firstWhere('id', (string) $customer->id);

        $this->assertSame(CustomerMetricsService::SEGMENT_BLOCKED, $row['segment']);
        $this->assertTrue($row['blocked']);
    }

    /**
     * The address book the drawer shows is the one that is stored.
     */
    public function test_the_addresses_are_the_ones_that_are_stored(): void
    {
        $customer = Customer::factory()->create();
        $governorate = Governorate::factory()->create(['name' => 'Giza']);

        CustomerAddress::factory()->for($customer)->create([
            'governorate_id' => $governorate->id,
            'name' => 'Layla Hassan',
            'phone' => '01000000001',
            'area' => 'Dokki',
            'address' => '12 Talaat Harb Street',
            'is_default' => true,
        ]);

        $response = $this->actingAsAdmin()->get(route('admin.customers.index'));

        $row = collect($response->viewData('customers'))
            ->firstWhere('id', (string) $customer->id);

        $this->assertCount(1, $row['addresses']);
        $this->assertSame('Dokki', $row['addresses'][0]['area']);
        $this->assertSame('12 Talaat Harb Street', $row['addresses'][0]['address']);
        $this->assertSame('Giza', $row['addresses'][0]['governorate']);
        $this->assertSame('Giza', $row['governorate']);
    }

    /**
     * The governorate filter offers the governorates that are really
     * being shipped to.
     */
    public function test_the_governorate_filter_comes_from_the_shipping_catalogue(): void
    {
        Governorate::factory()->create(['name' => 'Alexandria']);

        $response = $this->actingAsAdmin()->get(route('admin.customers.index'));

        $this->assertContains('Alexandria', $response->viewData('governorates'));
    }

    // ─── Tags and note ────────────────────────────────────────────────────

    /**
     * The tags and the internal note are saved.
     */
    public function test_the_tags_and_the_note_are_saved(): void
    {
        $customer = Customer::factory()->create();

        $response = $this->actingAsAdmin()->putJson(route('admin.customers.update', $customer), [
            'tags' => ['Wholesale', 'VIP'],
            'note' => 'Asks for a discount on bulk orders.',
        ]);

        $response->assertOk()->assertJson(['success' => true]);

        $customer->refresh();

        $this->assertSame(['Wholesale', 'VIP'], $customer->tags);
        $this->assertSame('Asks for a discount on bulk orders.', $customer->note);
    }

    /**
     * The answer carries back what was stored, so the browser shows the
     * server's copy and not the one it hoped for.
     */
    public function test_the_answer_carries_the_saved_tags_and_note(): void
    {
        $customer = Customer::factory()->create();

        $response = $this->actingAsAdmin()->putJson(route('admin.customers.update', $customer), [
            'tags' => ['  Wholesale  ', ''],
            'note' => 'Called twice.',
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'tags' => ['Wholesale'],
                'note' => 'Called twice.',
            ]);
    }

    /**
     * A note longer than the column is refused, not silently cut.
     */
    public function test_an_over_long_note_is_refused(): void
    {
        $customer = Customer::factory()->create();

        $response = $this->actingAsAdmin()->putJson(route('admin.customers.update', $customer), [
            'tags' => [],
            'note' => str_repeat('a', 2001),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('note');

        $this->assertNull($customer->fresh()->note);
    }

    // ─── Blocking ─────────────────────────────────────────────────────────

    /**
     * Blocking writes the flag and the reason.
     */
    public function test_blocking_writes_is_blocked_and_the_reason(): void
    {
        $customer = Customer::factory()->create();

        $response = $this->actingAsAdmin()->patchJson(route('admin.customers.block', $customer), [
            'blocked' => true,
            'reason' => 'Fraudulent orders',
        ]);

        $response->assertOk()->assertJson([
            'success' => true,
            'blocked' => true,
        ]);

        $customer->refresh();

        $this->assertTrue((bool) $customer->is_blocked);
        $this->assertSame('Fraudulent orders', $customer->block_reason);
    }

    /**
     * A block without a reason is refused: the back office has to be
     * able to say why later.
     */
    public function test_blocking_without_a_reason_is_refused(): void
    {
        $customer = Customer::factory()->create();

        $response = $this->actingAsAdmin()->patchJson(route('admin.customers.block', $customer), [
            'blocked' => true,
            'reason' => '   ',
        ]);

        $response->assertStatus(422)->assertJson(['success' => false]);

        $this->assertFalse((bool) $customer->fresh()->is_blocked);
    }

    /**
     * Unblocking clears the reason with the flag.
     */
    public function test_unblocking_clears_the_reason(): void
    {
        $customer = Customer::factory()->blocked('Fraudulent orders')->create();

        $response = $this->actingAsAdmin()->patchJson(route('admin.customers.block', $customer), [
            'blocked' => false,
        ]);

        $response->assertOk()->assertJson(['success' => true, 'blocked' => false]);

        $customer->refresh();

        $this->assertFalse((bool) $customer->is_blocked);
        $this->assertNull($customer->block_reason);
    }

    /**
     * A blocked customer cannot sign in.
     */
    public function test_a_blocked_customer_cannot_log_in(): void
    {
        Customer::factory()->blocked()->create([
            'email' => 'blocked@store.com',
            'password' => 'password123',
        ]);

        $response = $this->post(route('login'), [
            'identifier' => 'blocked@store.com',
            'password' => 'password123',
        ]);

        $response->assertSessionHasErrors('identifier');
        $this->assertGuest();
    }

    /**
     * A blocked customer cannot place an order, even with a live
     * session — the block is checked where the order is placed, not
     * only at sign-in.
     */
    public function test_a_blocked_customer_cannot_place_an_order(): void
    {
        $customer = Customer::factory()->blocked()->create();

        $this->cartReadyForCheckout($customer);

        $response = $this->actingAs($customer, 'web')
            ->postJson(route('checkout.process'), [
                'name' => 'Blocked Customer',
                'phone' => '01012345678',
                'governorate' => 'Cairo',
                'area' => 'Nasr City',
                'address' => 'Tahrir St',
                'payment_method' => 'cod',
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('orders', 0);
    }

    /**
     * Put one line in the cart, so the checkout has something to buy.
     */
    private function cartReadyForCheckout(Customer $customer): void
    {
        $zone = ShippingZone::factory()->create(['fee' => 50.00]);
        Governorate::factory()->create([
            'shipping_zone_id' => $zone->id,
            'name' => 'Cairo',
            'fee' => 45.00,
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
            'quantity' => 2,
        ]);
    }

    // ─── The export ───────────────────────────────────────────────────────

    /**
     * The export is a CSV of the same customers the screen shows.
     */
    public function test_the_export_is_a_csv_of_the_customers(): void
    {
        Customer::factory()->create(['name' => 'Layla Hassan']);

        $response = $this->actingAsAdmin()->get(route('admin.customers.export'));

        $response->assertOk();

        $csv = $response->streamedContent();

        $this->assertStringContainsString('Name', $csv);
        $this->assertStringContainsString('Layla Hassan', $csv);
    }

    /**
     * A customer whose name begins with a formula character is written
     * with a leading apostrophe, so the spreadsheet that opens the file
     * runs a name and not a formula.
     */
    public function test_the_export_escapes_a_formula_in_a_customer_name(): void
    {
        Customer::factory()->create(['name' => '=cmd|calc']);

        $response = $this->actingAsAdmin()->get(route('admin.customers.export'));

        $csv = $response->streamedContent();

        $this->assertStringContainsString("'=cmd|calc", $csv);
    }

    // ─── Who may do it ────────────────────────────────────────────────────

    /**
     * A viewer may read the customers screen — that is what a viewer
     * is for — but may not change anything on it.
     */
    public function test_a_viewer_may_read_the_customers_screen_but_not_write_to_it(): void
    {
        $viewer = Admin::create([
            'name' => 'Viewer',
            'email' => 'viewer@store.com',
            'password' => 'password',
            'role' => AdminRole::Viewer->value,
            'is_active' => true,
        ]);

        $customer = Customer::factory()->create();

        $this->actingAs($viewer, 'admin')
            ->get(route('admin.customers.index'))
            ->assertOk();

        $this->actingAs($viewer, 'admin')
            ->putJson(route('admin.customers.update', $customer), [
                'tags' => ['Sneaky'],
                'note' => '',
            ])
            ->assertForbidden();

        $this->actingAs($viewer, 'admin')
            ->patchJson(route('admin.customers.block', $customer), [
                'blocked' => true,
                'reason' => 'Not my call',
            ])
            ->assertForbidden();

        $this->assertFalse((bool) $customer->fresh()->is_blocked);
    }

    /**
     * A content editor has no business in the customers screen.
     */
    public function test_a_content_editor_is_refused_the_customers_screen(): void
    {
        $editor = Admin::create([
            'name' => 'Editor',
            'email' => 'editor@store.com',
            'password' => 'password',
            'role' => AdminRole::ContentEditor->value,
            'is_active' => true,
        ]);

        $this->actingAs($editor, 'admin')
            ->get(route('admin.customers.index'))
            ->assertForbidden();
    }

    /**
     * A guest is sent to the login page.
     */
    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $this->get(route('admin.customers.index'))->assertRedirect(route('admin.login'));
    }
}

<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Admin;
use App\Models\Color;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Size;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * @covers \App\Http\Controllers\Admin\DashboardController
 * @covers \App\Services\Admin\DashboardService
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Frozen clock so KPI windows ("today" / "yesterday") line up
     * with the seeds in every test. The reference moment has to be
     * some wall-clock time that matches a non-leap second slot, since
     * `Order::generateOrderNumber()` writes the current year onto
     * the row and order status history uses the same instant.
     */
    private const FROZEN_NOW = '2026-06-15 10:30:00';

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::FROZEN_NOW);

        $this->admin = Admin::create([
            'name' => 'Dashboard Admin',
            'email' => 'dashboard_admin@store.com',
            'password' => 'password',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function actingAsAdmin(): static
    {
        return $this->actingAs($this->admin, 'admin');
    }

    // ─── Auth ────────────────────────────────────────────────────────────

    public function test_guest_is_redirected_to_admin_login(): void
    {
        // The admin group uses EnsureAdmin, which redirects an
        // unauthenticated caller to admin.login rather than 401.
        $this->get(route('admin.dashboard'))
            ->assertRedirect(route('admin.login'));
    }

    public function test_authenticated_customer_is_redirected_to_admin_login(): void
    {
        // The admin guard has its own provider. A logged-in customer
        // does not authenticate the admin guard and must therefore be
        // sent to the admin login screen.
        $customer = Customer::factory()->create();

        $this->actingAs($customer, 'web')
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('admin.login'));
    }

    public function test_admin_can_open_the_dashboard(): void
    {
        // One order so the Latest Orders table renders its rows rather
        // than the empty state — `ordersEmpty` is derived from the
        // latest-orders query, so it can only be false when the table
        // has something to show.
        Order::factory()->create();

        $this->actingAsAdmin()
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertViewIs('admin.dashboard')
            ->assertViewHas('attention')
            ->assertViewHas('today')
            ->assertViewHas('latestOrders')
            ->assertViewHas('lowStock')
            ->assertViewHas('ordersEmpty', false)
            ->assertViewHas('stockEmpty', true);
    }

    // ─── Attention strip ────────────────────────────────────────────────

    public function test_attention_strip_counts_pending_and_processing_orders(): void
    {
        // Two pending orders + one processing should be counted.
        Order::factory()->count(2)->create(['status' => OrderStatus::Pending]);
        Order::factory()->create(['status' => OrderStatus::Processing]);

        // Orders in terminal states must not be counted.
        Order::factory()->create(['status' => OrderStatus::Shipped]);
        Order::factory()->create(['status' => OrderStatus::Delivered]);
        Order::factory()->create(['status' => OrderStatus::Cancelled]);

        $first = $this->attentionByLabel('orders waiting');

        $this->assertSame(3, $first['count']);
        $this->assertSame('lime', $first['tone']);
        $this->assertSame(route('admin.orders.index'), $first['href']);
    }

    public function test_attention_strip_counts_low_stock_variants(): void
    {
        // Three variants at-or-below the threshold and one above it.
        Variant::factory()->count(3)->create(['quantity' => 3]);
        Variant::factory()->create(['quantity' => 9]);

        $tile = $this->attentionByLabel('items low stock');

        $this->assertSame(3, $tile['count']);
        $this->assertSame('coral', $tile['tone']);
        $this->assertSame(route('admin.products.index'), $tile['href']);
    }

    public function test_attention_strip_counts_shipped_cod_orders(): void
    {
        Order::factory()->count(4)->create(['status' => OrderStatus::Shipped]);
        Order::factory()->create(['status' => OrderStatus::Pending]);
        Order::factory()->create(['status' => OrderStatus::Delivered]);

        $tile = $this->attentionByLabel('COD to collect');

        $this->assertSame(4, $tile['count']);
        $this->assertSame('gray', $tile['tone']);
        $this->assertSame(route('admin.orders.index'), $tile['href']);
    }

    public function test_attention_strip_is_empty_when_nothing_needs_attention(): void
    {
        // No pending/processing/shipped orders, no low-stock variants.
        $tiles = $this->attentionViewData();

        $this->assertSame(0, $tiles[0]['count']);
        $this->assertSame(0, $tiles[1]['count']);
        $this->assertSame(0, $tiles[2]['count']);
    }

    // ─── KPI cards ───────────────────────────────────────────────────────

    public function test_kpis_aggregate_today_sales_orders_and_returns(): void
    {
        // Today — two completed orders (totalling 1,100 EGP) and one
        // cancelled order (550 EGP).
        $this->makeOrderToday(OrderStatus::Pending, total: 500.00);
        $this->makeOrderToday(OrderStatus::Delivered, total: 600.00);
        $this->makeOrderToday(OrderStatus::Cancelled, total: 550.00);

        // Yesterday — same totals so the deltas line up at 0%.
        $this->makeOrderYesterday(OrderStatus::Pending, total: 500.00);
        $this->makeOrderYesterday(OrderStatus::Delivered, total: 600.00);
        $this->makeOrderYesterday(OrderStatus::Cancelled, total: 550.00);

        $kpis = $this->kpisViewData();

        $this->assertSame('1,100 EGP', $kpis['sales']);
        $this->assertSame(2, $kpis['orders']);
        $this->assertSame('550 EGP', $kpis['avgOrder']); // 1,100 / 2
        $this->assertSame(1, $kpis['returns']);
        $this->assertSame('550 EGP', $kpis['returnsWaste']);
    }

    public function test_kpi_sales_delta_is_zero_when_yesterday_matches_today(): void
    {
        $this->makeOrderToday(OrderStatus::Delivered, total: 750.00);
        $this->makeOrderYesterday(OrderStatus::Delivered, total: 750.00);

        $this->assertSame('+0%', $this->kpisViewData()['salesDelta']);
    }

    public function test_kpi_sales_delta_is_positive_when_today_outperforms_yesterday(): void
    {
        $this->makeOrderToday(OrderStatus::Delivered, total: 1500.00);
        $this->makeOrderYesterday(OrderStatus::Delivered, total: 1000.00);

        // (1500 - 1000) / 1000 = 0.50 → +50%
        $this->assertSame('+50%', $this->kpisViewData()['salesDelta']);
    }

    public function test_kpi_sales_delta_is_negative_when_today_underperforms(): void
    {
        $this->makeOrderToday(OrderStatus::Delivered, total: 500.00);
        $this->makeOrderYesterday(OrderStatus::Delivered, total: 1000.00);

        // (500 - 1000) / |1000| = -0.50 → -50%
        $this->assertSame('-50%', $this->kpisViewData()['salesDelta']);
    }

    public function test_kpi_sales_delta_is_zero_when_both_periods_are_empty(): void
    {
        $this->assertSame('+0%', $this->kpisViewData()['salesDelta']);
    }

    public function test_kpi_profit_subtracts_cost_of_goods_from_sales(): void
    {
        // One unit sold today for 1,000 EGP with a cost of 400 EGP.
        $product = Product::factory()->create();
        $variant = Variant::factory()->create([
            'product_id' => $product->id,
            'cost' => 400.00,
        ]);

        $order = Order::factory()->create([
            'status' => OrderStatus::Delivered,
            'total' => 1000.00,
            'created_at' => Carbon::parse(self::FROZEN_NOW),
            'updated_at' => Carbon::parse(self::FROZEN_NOW),
            'payment_status' => PaymentStatus::Paid,
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 1,
            'unit_price' => 1000.00,
            'total_price' => 1000.00,
        ]);

        $kpis = $this->kpisViewData();

        $this->assertSame('1,000 EGP', $kpis['sales']);
        $this->assertSame('600 EGP', $kpis['profit']);
    }

    // ─── Latest orders ───────────────────────────────────────────────────

    public function test_latest_orders_shows_the_most_recent_five(): void
    {
        Order::factory()->count(8)->create();

        $rows = $this->latestOrdersViewData();

        $this->assertCount(5, $rows);

        // Each row carries the keys the view consumes.
        $this->assertSame(
            ['id', 'customer', 'total', 'status', 'url'],
            array_keys($rows[0]),
        );
    }

    public function test_latest_orders_are_sorted_newest_first(): void
    {
        $first = Order::factory()->create([
            'customer_name' => 'Older',
            'total' => 100.00,
            'created_at' => Carbon::parse(self::FROZEN_NOW)->subHours(2),
            'updated_at' => Carbon::parse(self::FROZEN_NOW)->subHours(2),
        ]);

        $second = Order::factory()->create([
            'customer_name' => 'Newer',
            'total' => 200.00,
            'created_at' => Carbon::parse(self::FROZEN_NOW),
            'updated_at' => Carbon::parse(self::FROZEN_NOW),
        ]);

        $rows = $this->latestOrdersViewData();

        $this->assertSame($second->order_number, $rows[0]['id']);
        $this->assertSame($first->order_number, $rows[1]['id']);
    }

    public function test_latest_order_row_uses_linked_customer_name_when_present(): void
    {
        $customer = Customer::factory()->create(['name' => 'Karim Hassan']);

        Order::factory()->create([
            'customer_id' => $customer->id,
            'customer_name' => 'Snapshot Name',
            'total' => 999.00,
        ]);

        $rows = $this->latestOrdersViewData();

        $this->assertSame('Karim Hassan', $rows[0]['customer']);
        $this->assertSame('999 EGP', $rows[0]['total']);
        $this->assertStringContainsString(OrderStatus::Pending->label(), $rows[0]['status']);
        $this->assertSame(route('admin.orders.index'), $rows[0]['url']);
    }

    public function test_latest_order_row_falls_back_to_snapshot_customer_name(): void
    {
        // Guest checkout — no linked customer.
        Order::factory()->create([
            'customer_id' => null,
            'customer_name' => 'Nada Said',
            'total' => 250.00,
        ]);

        $rows = $this->latestOrdersViewData();

        $this->assertSame('Nada Said', $rows[0]['customer']);
    }

    public function test_latest_orders_table_renders_empty_state_when_no_orders_exist(): void
    {
        $response = $this->actingAsAdmin()->get(route('admin.dashboard'));

        $response->assertOk()
            ->assertViewHas('ordersEmpty', true)
            ->assertSee('No orders yet.');
    }

    // ─── Low stock ───────────────────────────────────────────────────────

    public function test_low_stock_lists_the_four_emptiest_variants(): void
    {
        // The threshold is 5; only rows at-or-below qualify, and the
        // four emptiest should be returned in ascending order.
        Variant::factory()->create(['quantity' => 10]); // ignored — above threshold
        Variant::factory()->create(['quantity' => 5]);  // boundary
        Variant::factory()->create(['quantity' => 4]);
        Variant::factory()->create(['quantity' => 3]);
        Variant::factory()->create(['quantity' => 2]);
        Variant::factory()->create(['quantity' => 1]);
        Variant::factory()->create(['quantity' => 0]);

        $rows = $this->lowStockViewData();

        $this->assertCount(4, $rows);

        // Order is ascending by stock — emptiest first.
        $this->assertSame(0, $rows[0]['stock']);
        $this->assertSame(1, $rows[1]['stock']);
        $this->assertSame(2, $rows[2]['stock']);
        $this->assertSame(3, $rows[3]['stock']);
    }

    public function test_low_stock_row_carries_product_color_and_size_labels(): void
    {
        $product = Product::factory()->create(['name' => 'Oversize hoodie']);
        $variant = Variant::factory()->create([
            'product_id' => $product->id,
            'quantity' => 2,
            'color_id' => Color::factory()->create(['name' => 'Black'])->id,
            'size_id' => Size::factory()->create(['name' => 'L'])->id,
        ]);

        $rows = $this->lowStockViewData();

        $this->assertSame('Oversize hoodie', $rows[0]['product']);
        $this->assertSame('Black / L', $rows[0]['variant']);
        $this->assertSame(2, $rows[0]['stock']);
        $this->assertSame(100, $rows[0]['max']);
    }

    public function test_low_stock_section_renders_empty_state_when_nothing_is_low(): void
    {
        // Only fully stocked variants.
        Variant::factory()->create(['quantity' => 50]);
        Variant::factory()->create(['quantity' => 25]);

        $response = $this->actingAsAdmin()->get(route('admin.dashboard'));

        $response->assertOk()
            ->assertViewHas('stockEmpty', true)
            ->assertSee('Stock levels look good.');
    }

    // ─── Export ──────────────────────────────────────────────────────────

    public function test_the_export_button_points_at_the_dashboard_export(): void
    {
        // The button used to be a <button> with no handler — clicking
        // it did nothing at all. It is a link now, and the link carries
        // the range the screen is showing.
        $response = $this->actingAsAdmin()->get(route('admin.dashboard', ['range' => '7d']));

        $response->assertOk()
            ->assertSee('data-abo-admin-export', false)
            ->assertSee(route('admin.dashboard.export', ['range' => '7d']), false);
    }

    public function test_the_dashboard_exports_the_numbers_it_shows(): void
    {
        $this->makeOrderToday(OrderStatus::Delivered, 1500);

        $response = $this->actingAsAdmin()
            ->get(route('admin.dashboard.export', ['range' => 'today']));

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();

        $this->assertStringContainsString('SUMMARY — Today', $csv);
        $this->assertStringContainsString('Sales (EGP)', $csv);
        $this->assertStringContainsString('1500', $csv);
        $this->assertStringContainsString('SALES VS PROFIT', $csv);
        $this->assertStringContainsString('ORDERS BY STATUS', $csv);
    }

    public function test_the_dashboard_export_rejects_an_unknown_range(): void
    {
        $this->actingAsAdmin()
            ->get(route('admin.dashboard.export', ['range' => 'last-year']))
            ->assertInvalid();
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    /**
     * @return array<int, array{count: int, label: string, href: string, tone: string}>
     */
    private function attentionByLabel(string $label): array
    {
        return collect($this->attentionViewData())
            ->first(fn (array $tile): bool => $tile['label'] === $label);
    }

    /**
     * @return array<int, array{count: int, label: string, href: string, tone: string}>
     */
    private function attentionViewData(): array
    {
        return $this->actingAsAdmin()
            ->get(route('admin.dashboard'))
            ->viewData('attention');
    }

    /**
     * @return array<string, mixed>
     */
    private function kpisViewData(): array
    {
        return $this->actingAsAdmin()
            ->get(route('admin.dashboard'))
            ->viewData('today');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function latestOrdersViewData(): array
    {
        return $this->actingAsAdmin()
            ->get(route('admin.dashboard'))
            ->viewData('latestOrders')
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function lowStockViewData(): array
    {
        return $this->actingAsAdmin()
            ->get(route('admin.dashboard'))
            ->viewData('lowStock')
            ->all();
    }

    private function makeOrderToday(OrderStatus $status, float $total): Order
    {
        return Order::factory()->create([
            'status' => $status,
            'total' => $total,
            'created_at' => Carbon::parse(self::FROZEN_NOW),
            'updated_at' => Carbon::parse(self::FROZEN_NOW),
        ]);
    }

    private function makeOrderYesterday(OrderStatus $status, float $total): Order
    {
        $yesterday = Carbon::parse(self::FROZEN_NOW)->subDay();

        return Order::factory()->create([
            'status' => $status,
            'total' => $total,
            'created_at' => $yesterday,
            'updated_at' => $yesterday,
        ]);
    }
}

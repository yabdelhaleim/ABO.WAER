<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Enums\OrderStatus;
use App\Models\Admin;
use App\Models\CancellationReason;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The reports screen.
 *
 * The screen used to draw a static object that lived in
 * public/admin/js/reports.js: every number on it was invented. These
 * tests pin the screen to the database — the numbers on it are the
 * numbers the orders hold.
 */
class ReportTest extends TestCase
{
    use RefreshDatabase;

    protected Admin $admin;

    protected Category $category;

    protected Product $product;

    protected Variant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create([
            'name' => 'Reports Admin',
            'email' => 'reports@store.com',
            'password' => 'password',
            'is_active' => true,
        ]);

        $this->category = Category::create([
            'name' => 'Hoodies',
            'slug' => 'hoodies',
        ]);

        $this->product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Oversize Hoodie',
            'slug' => 'oversize-hoodie',
        ]);

        $this->variant = Variant::factory()->create([
            'product_id' => $this->product->id,
            'price' => 500,
            'cost' => 200,
            'quantity' => 10,
        ]);
    }

    /**
     * One order with one line of one unit.
     */
    protected function makeOrder(array $attributes = [], array $itemAttributes = []): Order
    {
        $order = Order::create(array_merge([
            'order_number' => 'ABO-'.fake()->unique()->numerify('####'),
            'customer_name' => 'Test Customer',
            'customer_phone' => '01000000000',
            'customer_email' => 'customer@example.com',
            'governorate_id' => null,
            'governorate_name' => 'Cairo',
            'area' => 'Maadi',
            'address' => '1 Street',
            'subtotal' => 500,
            'shipping_fee' => 50,
            'discount_amount' => 0,
            'total' => 550,
            'payment_method' => 'cod',
            'status' => OrderStatus::Delivered,
        ], $attributes));

        OrderItem::create(array_merge([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'variant_id' => $this->variant->id,
            'product_name' => $this->product->name,
            'color_name' => 'Black',
            'size_name' => 'L',
            'sku' => 'HOODIE-BL-L',
            'unit_price' => 500,
            'quantity' => 1,
            'total_price' => 500,
        ], $itemAttributes));

        return $order;
    }

    /**
     * The payload the page hands to the browser.
     */
    protected function payload(array $query = []): array
    {
        $response = $this->actingAs($this->admin, 'admin')
            ->get('/admin/reports'.($query ? '?'.http_build_query($query) : ''));

        $response->assertOk();

        preg_match(
            '/data-abo-admin-reports-payload>(.*?)<\/script>/s',
            $response->getContent(),
            $matches,
        );

        return json_decode($matches[1] ?? '{}', true) ?: [];
    }

    public function test_guest_cannot_view_the_reports_page(): void
    {
        $this->get('/admin/reports')
            ->assertRedirect(route('admin.login'));
    }

    public function test_an_admin_without_the_permission_cannot_view_the_reports_page(): void
    {
        // A content editor does not reach the reports module at all.
        $editor = Admin::create([
            'name' => 'Editor',
            'email' => 'editor@store.com',
            'password' => 'password',
            'is_active' => true,
            'role' => AdminRole::ContentEditor->value,
        ]);

        $this->actingAs($editor, 'admin')->get('/admin/reports')->assertForbidden();
    }

    public function test_the_page_carries_the_report_the_server_measured(): void
    {
        $this->makeOrder();

        $response = $this->actingAs($this->admin, 'admin')->get('/admin/reports');

        $response->assertOk();
        $response->assertSee('data-abo-admin-reports-payload', false);
        $response->assertDontSee('Sample data', false);
    }

    public function test_the_revenue_is_the_sum_of_the_orders(): void
    {
        $this->makeOrder(['total' => 550]);
        $this->makeOrder(['total' => 300, 'shipping_fee' => 20, 'subtotal' => 280]);

        $payload = $this->payload();

        $this->assertEquals(850.0, $payload['totals']['revenue']);
        $this->assertEquals(2, $payload['totals']['orders']);
        $this->assertEquals(2, $payload['totals']['items']);
        $this->assertEquals(425.0, $payload['totals']['aov']);
    }

    public function test_a_cancelled_order_is_neither_revenue_nor_a_sale(): void
    {
        $this->makeOrder(['total' => 550]);
        $this->makeOrder(['total' => 900, 'status' => OrderStatus::Cancelled]);

        $payload = $this->payload();

        $this->assertEquals(550.0, $payload['totals']['revenue']);
        $this->assertEquals(1, $payload['totals']['orders']);
        $this->assertEquals(1, $payload['totals']['returns']);
        $this->assertEquals(900.0, $payload['totals']['waste']);
    }

    public function test_a_returned_order_counts_as_waste(): void
    {
        $this->makeOrder(['total' => 550]);
        $this->makeOrder(['total' => 700, 'status' => OrderStatus::Returned]);

        $payload = $this->payload();

        $this->assertEquals(550.0, $payload['totals']['revenue']);
        $this->assertEquals(1, $payload['totals']['returns']);
        $this->assertEquals(700.0, $payload['totals']['waste']);
    }

    public function test_the_profit_is_the_revenue_minus_the_cost_of_goods(): void
    {
        $this->makeOrder(['total' => 550], ['quantity' => 2, 'unit_price' => 500, 'total_price' => 1000]);

        $payload = $this->payload();

        // Two units at a cost of 200 each.
        $this->assertEquals(400.0, $payload['totals']['cog']);
        $this->assertEquals(550.0 - 400.0, $payload['totals']['profit']);
        $this->assertEquals(2, $payload['totals']['items']);
    }

    public function test_the_shipping_is_reported_on_its_own(): void
    {
        $this->makeOrder(['shipping_fee' => 50]);

        $payload = $this->payload();

        $this->assertEquals(50.0, $payload['totals']['ship']);
    }

    public function test_the_governorates_carry_their_share_of_the_revenue(): void
    {
        $this->makeOrder(['governorate_name' => 'Cairo', 'total' => 750]);
        $this->makeOrder(['governorate_name' => 'Giza', 'total' => 250]);

        $payload = $this->payload();

        $governorates = $payload['governorates'];
        $this->assertCount(2, $governorates);
        $this->assertEquals('Cairo', $governorates[0]['name']);
        $this->assertEquals(75.0, $governorates[0]['share']);
        $this->assertEquals('Giza', $governorates[1]['name']);
        $this->assertEquals(25.0, $governorates[1]['share']);
    }

    public function test_the_status_segments_count_the_orders(): void
    {
        $this->makeOrder(['status' => OrderStatus::Pending]);
        $this->makeOrder(['status' => OrderStatus::Delivered]);
        $this->makeOrder(['status' => OrderStatus::Delivered]);

        $payload = $this->payload();

        $segments = [];
        foreach ($payload['statusSegments'] as $segment) {
            $segments[$segment['key']] = $segment['count'];
        }

        $this->assertEquals(1, $segments['new']);
        $this->assertEquals(2, $segments['delivered']);
    }

    public function test_the_top_products_carry_their_units_and_revenue(): void
    {
        $this->makeOrder(['total' => 550], ['quantity' => 3, 'unit_price' => 500, 'total_price' => 1500]);

        $payload = $this->payload();

        $this->assertCount(1, $payload['topProducts']);
        $this->assertEquals('Oversize Hoodie', $payload['topProducts'][0]['name']);
        $this->assertEquals('Hoodies', $payload['topProducts'][0]['cat']);
        $this->assertEquals(3, $payload['topProducts'][0]['units']);
        $this->assertEquals(1500.0, $payload['topProducts'][0]['rev']);
        $this->assertEquals(900.0, $payload['topProducts'][0]['profit']);
    }

    public function test_the_return_rate_of_a_product_counts_what_came_back(): void
    {
        $this->makeOrder();
        $this->makeOrder(['status' => OrderStatus::Returned], ['quantity' => 1, 'total_price' => 500]);

        $payload = $this->payload();

        $this->assertEquals(50.0, $payload['topProducts'][0]['ret']);
    }

    public function test_the_return_reasons_come_from_the_catalogue_of_reasons(): void
    {
        $reason = CancellationReason::create([
            'reason' => 'Wrong size',
            'reason_ar' => 'مقاس غلط',
            'is_active' => true,
        ]);

        $this->makeOrder(['status' => OrderStatus::Cancelled, 'cancellation_reason_id' => $reason->id]);
        $this->makeOrder(['status' => OrderStatus::Returned, 'cancellation_reason_id' => $reason->id]);

        $payload = $this->payload();

        $this->assertCount(1, $payload['reasons']);
        $this->assertEquals('Wrong size', $payload['reasons'][0]['label']);
        $this->assertEquals('Wrong size', $payload['topReturnReason']['name']);
        $this->assertEquals(100.0, $payload['topReturnReason']['share']);
    }

    public function test_the_comparison_is_the_period_before_the_one_on_screen(): void
    {
        // Today: one order of 550. Yesterday: one order of 1000.
        $this->makeOrder(['total' => 550, 'created_at' => now()]);
        $this->makeOrder(['total' => 1000, 'created_at' => now()->subDay()]);

        $payload = $this->payload(['range' => 'today']);

        $this->assertEquals(550.0, $payload['totals']['revenue']);
        $this->assertEquals(1000.0, $payload['previous']['totals']['revenue']);
    }

    public function test_the_range_endpoint_answers_the_report_of_that_range(): void
    {
        $this->makeOrder(['total' => 550]);

        $response = $this->actingAs($this->admin, 'admin')->getJson('/admin/reports/range/today');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertEquals(550.0, $response->json('report.totals.revenue'));
        $this->assertEquals('today', $response->json('report.range.key'));
    }

    public function test_the_range_endpoint_answers_the_custom_range(): void
    {
        $this->makeOrder(['total' => 550, 'created_at' => now()->subDays(10)]);
        $this->makeOrder(['total' => 700]);

        $response = $this->actingAs($this->admin, 'admin')->getJson(
            '/admin/reports/range/custom?from='.now()->subDay()->toDateString().'&to='.now()->toDateString(),
        );

        $response->assertOk();
        $this->assertEquals(700.0, $response->json('report.totals.revenue'));
    }

    public function test_an_unknown_range_falls_back_to_the_default(): void
    {
        $response = $this->actingAs($this->admin, 'admin')->getJson('/admin/reports/range/nonsense');

        $response->assertOk();
        $this->assertEquals('7d', $response->json('report.range.key'));
    }

    public function test_the_export_streams_the_report_as_a_csv(): void
    {
        $this->makeOrder(['total' => 550]);

        $response = $this->actingAs($this->admin, 'admin')->get('/admin/reports/export');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();

        $this->assertStringContainsString('SUMMARY', $csv);
        $this->assertStringContainsString('Revenue', $csv);
        $this->assertStringContainsString('BY DAY', $csv);
        $this->assertStringContainsString('TOP PRODUCTS', $csv);
        $this->assertStringContainsString('Oversize Hoodie', $csv);
    }

    public function test_the_export_escapes_a_cell_that_a_spreadsheet_would_read_as_a_formula(): void
    {
        $this->product->update(['name' => '=cmd|calc']);

        $this->makeOrder();

        $response = $this->actingAs($this->admin, 'admin')->get('/admin/reports/export');

        $csv = $response->streamedContent();

        $this->assertStringContainsString("'=cmd|calc", $csv);
    }

    public function test_a_guest_cannot_export_the_report(): void
    {
        $this->get('/admin/reports/export')->assertRedirect(route('admin.login'));
    }
}

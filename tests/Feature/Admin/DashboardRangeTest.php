<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Models\Admin;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Variant;
use App\Services\Admin\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The dashboard's range switching: the chart maths in
 * DashboardService, and the JSON contract admin.js consumes.
 *
 * public/admin/js/admin.js re-draws the KPI cards, the Sales vs
 * Profit bars, the sparklines and the status bar straight from this
 * payload without re-creating markup, so a renamed key shows up as
 * blank text rather than an error. These tests pin the shape the
 * browser walks.
 *
 * @covers \App\Http\Controllers\Admin\DashboardController
 * @covers \App\Services\Admin\DashboardService
 */
class DashboardRangeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Frozen clock so the range windows ("today" / "7d" / "30d") line
     * up with the seeds. It is a Monday at 10:30, which keeps the
     * weekday labels off the Saturday→Friday boundary and makes the
     * hours-of-day buckets a predictable set.
     */
    private const FROZEN_NOW = '2026-06-15 10:30:00';

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::FROZEN_NOW);

        $this->admin = Admin::create([
            'name' => 'Range Admin',
            'email' => 'range_admin@store.com',
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

    // ─── Endpoint contract ───────────────────────────────────────────────

    public function test_guest_cannot_read_the_range_endpoint(): void
    {
        // The admin group redirects rather than answering 401.
        $this->getJson(route('admin.dashboard.range', ['range' => '7d']))
            ->assertRedirect(route('admin.login'));
    }

    /**
     * The segmented control renders exactly the three ranges the
     * endpoint accepts, so each one has to come back 200 with the
     * full payload.
     */
    public function test_every_offered_range_returns_a_full_payload(): void
    {
        foreach (['today', '7d', '30d'] as $range) {
            $response = $this->actingAsAdmin()
                ->getJson(route('admin.dashboard.range', ['range' => $range]));

            $response->assertOk()
                ->assertJsonPath('range', $range)
                ->assertJsonStructure([
                    'range',
                    'label',
                    'ordersTotal',
                    'kpis' => [
                        'sales' => ['key', 'label', 'value', 'delta', 'sub', 'tone'],
                        'profit' => ['key', 'label', 'value', 'delta', 'sub', 'tone'],
                        'orders' => ['key', 'label', 'value', 'delta', 'sub', 'tone'],
                        'returns' => ['key', 'label', 'value', 'delta', 'sub', 'tone'],
                    ],
                    'sparklines' => ['sales', 'profit', 'orders', 'returns'],
                ]);

            $this->assertIsArray($response->json('salesProfit'));
            $this->assertIsArray($response->json('ordersByStatus'));
        }
    }

    /**
     * The range is a path segment, so an unrecognised value has to be
     * refused with a validation error rather than silently falling back
     * to the default — otherwise a typo in the control would paint
     * today's numbers under a "30 days" label.
     */
    public function test_unknown_range_is_rejected(): void
    {
        $this->actingAsAdmin()
            ->getJson(route('admin.dashboard.range', ['range' => '90d']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('range');
    }

    /**
     * A blank range never reaches the controller: an empty path segment
     * does not match `{range}`, so the router answers 404 before any
     * validation runs. The `required` rule on the controller is the
     * defensive backstop for that contract rather than a path a real
     * request can take.
     */
    public function test_blank_range_does_not_match_the_route(): void
    {
        $this->actingAsAdmin()
            ->getJson('/admin/dashboard/range/')
            ->assertNotFound();
    }

    /**
     * The page's first paint has to be the same numbers the endpoint
     * would return for that range, or switching away and back would
     * visibly change the dashboard.
     */
    public function test_page_and_endpoint_agree_for_the_selected_range(): void
    {
        $this->seedSalesDay(Carbon::parse(self::FROZEN_NOW)->subDays(2), total: 900.00);

        $payload = $this->actingAsAdmin()
            ->getJson(route('admin.dashboard.range', ['range' => '7d']))
            ->json();

        $response = $this->actingAsAdmin()->get(route('admin.dashboard', ['range' => '7d']));

        $response->assertOk()
            ->assertViewHas('range', '7d')
            ->assertViewHas('salesProfit', $payload['salesProfit'])
            ->assertViewHas('statusSegments', $payload['ordersByStatus'])
            ->assertViewHas('sparklines', $payload['sparklines']);
    }

    // ─── Sales vs Profit chart ───────────────────────────────────────────

    public function test_week_chart_has_one_point_per_day_ending_today(): void
    {
        $chart = $this->actingAsAdmin()
            ->getJson(route('admin.dashboard.range', ['range' => '7d']))
            ->json('salesProfit');

        $this->assertCount(7, $chart);

        // Chronological, and only the final point is flagged current.
        $this->assertSame([false, false, false, false, false, false, true], array_column($chart, 'current'));

        // Labels are real calendar weekdays, so the last one is today.
        $this->assertSame('Mon', end($chart)['day']);
    }

    public function test_month_chart_has_one_point_per_day(): void
    {
        $chart = $this->actingAsAdmin()
            ->getJson(route('admin.dashboard.range', ['range' => '30d']))
            ->json('salesProfit');

        $this->assertCount(30, $chart);
        $this->assertTrue(end($chart)['current']);
    }

    /**
     * "Today" breaks the day into hours, and stops at the current hour
     * rather than drawing hours that have not happened yet.
     */
    public function test_today_chart_is_bucketed_by_hour_up_to_now(): void
    {
        $chart = $this->actingAsAdmin()
            ->getJson(route('admin.dashboard.range', ['range' => 'today']))
            ->json('salesProfit');

        // Frozen at 10:30, so 00:00 through 10:00 inclusive.
        $this->assertCount(11, $chart);
        $this->assertSame('00', $chart[0]['day']);
        $this->assertSame('10', end($chart)['day']);
        $this->assertTrue(end($chart)['current']);
    }

    /**
     * The hourly bucket key is built in PHP while the grouped reads key
     * off SUBSTR(created_at, 12, 2), so the two have to agree exactly —
     * or every lookup misses and the whole day renders as zeros while
     * the KPI card reports real revenue.
     */
    public function test_today_chart_places_revenue_in_the_hour_it_happened(): void
    {
        $this->makeOrder(Carbon::parse(self::FROZEN_NOW)->setTime(8, 15), OrderStatus::Delivered, total: 3000.00);
        $this->makeOrder(Carbon::parse(self::FROZEN_NOW)->setTime(8, 55), OrderStatus::Delivered, total: 2000.00);
        $this->makeOrder(Carbon::parse(self::FROZEN_NOW)->setTime(9, 30), OrderStatus::Delivered, total: 1000.00);

        $response = $this->actingAsAdmin()
            ->getJson(route('admin.dashboard.range', ['range' => 'today']));

        $byHour = collect($response->json('salesProfit'))->keyBy('day');

        $this->assertEqualsWithDelta(5.0, $byHour['08']['sales'], 0.001);
        $this->assertEqualsWithDelta(1.0, $byHour['09']['sales'], 0.001);
        $this->assertEqualsWithDelta(0.0, $byHour['07']['sales'], 0.001);

        // And the chart agrees with the KPI card for the same day.
        $this->assertSame('6,000 EGP', $response->json('kpis.sales.value'));
    }

    /**
     * The window closes at `now()`, so an order timestamped later
     * today is absent from both the card and the chart. Counting it in
     * one and not the other would make a single range report two
     * different totals side by side.
     */
    public function test_a_later_timestamp_today_is_counted_in_neither_the_card_nor_the_chart(): void
    {
        $this->makeOrder(Carbon::parse(self::FROZEN_NOW)->setTime(8, 0), OrderStatus::Delivered, total: 1000.00);
        // 14:00 is after the frozen 10:30 "now".
        $this->makeOrder(Carbon::parse(self::FROZEN_NOW)->setTime(14, 0), OrderStatus::Delivered, total: 4000.00);

        $response = $this->actingAsAdmin()
            ->getJson(route('admin.dashboard.range', ['range' => 'today']));

        $this->assertSame('1,000 EGP', $response->json('kpis.sales.value'));

        $byHour = collect($response->json('salesProfit'))->keyBy('day');
        $this->assertEqualsWithDelta(1.0, $byHour['08']['sales'], 0.001);

        // The bar for the future hour is not even drawn.
        $this->assertArrayNotHasKey('14', $byHour->all());
    }

    public function test_chart_aggregates_daily_sales_and_profit_in_thousands(): void
    {
        // 14,200 EGP of sales at 400 EGP unit cost on 1 unit.
        $this->seedSalesDay(Carbon::parse(self::FROZEN_NOW)->subDay(), total: 14200.00, cost: 400.00);

        $chart = $this->actingAsAdmin()
            ->getJson(route('admin.dashboard.range', ['range' => '7d']))
            ->json('salesProfit');

        $yesterday = $chart[5];

        // K EGP, one decimal, exactly as the "K EGP" axis expects.
        // json_encode drops the ".0" from a whole float, so these
        // arrive as the JSON numbers 14.2 and 13.8.
        $this->assertEqualsWithDelta(14.2, $yesterday['sales'], 0.001);
        $this->assertEqualsWithDelta(13.8, $yesterday['profit'], 0.001); // 14.2 - 0.4
    }

    /**
     * A cancelled order is not revenue, so it must not add a bar — and
     * it must not subtract cost of goods either.
     */
    public function test_cancelled_orders_are_excluded_from_the_chart(): void
    {
        $when = Carbon::parse(self::FROZEN_NOW)->subDay();

        $this->seedSalesDay($when, total: 5000.00, cost: 1000.00);
        $this->makeOrder($when, OrderStatus::Cancelled, total: 9000.00);

        $chart = $this->actingAsAdmin()
            ->getJson(route('admin.dashboard.range', ['range' => '7d']))
            ->json('salesProfit');

        $this->assertEqualsWithDelta(5.0, $chart[5]['sales'], 0.001);
        $this->assertEqualsWithDelta(4.0, $chart[5]['profit'], 0.001);
    }

    public function test_empty_store_returns_a_flat_chart_not_an_empty_one(): void
    {
        // The x-axis must keep its shape so switching to a range with
        // no data does not collapse the chart to nothing.
        foreach (['today', '7d', '30d'] as $range) {
            $chart = $this->actingAsAdmin()
                ->getJson(route('admin.dashboard.range', ['range' => $range]))
                ->json('salesProfit');

            $this->assertNotEmpty($chart);

            foreach ($chart as $point) {
                $this->assertEqualsWithDelta(0.0, $point['sales'], 0.001);
                $this->assertEqualsWithDelta(0.0, $point['profit'], 0.001);
            }
        }
    }

    // ─── Orders by status ────────────────────────────────────────────────

    public function test_status_bar_splits_orders_across_the_five_buckets(): void
    {
        Order::factory()->create(['status' => OrderStatus::Pending]);
        Order::factory()->create(['status' => OrderStatus::Processing]);
        Order::factory()->create(['status' => OrderStatus::Shipped]);
        Order::factory()->create(['status' => OrderStatus::Delivered]);
        Order::factory()->create(['status' => OrderStatus::Cancelled]);

        $segments = $this->actingAsAdmin()
            ->getJson(route('admin.dashboard.range', ['range' => '7d']))
            ->json('ordersByStatus');

        $this->assertSame(
            ['New', 'Processing', 'Shipped', 'Delivered', 'Cancelled'],
            array_column($segments, 'label'),
        );
        $this->assertSame([1, 1, 1, 1, 1], array_column($segments, 'count'));
        $this->assertSame(
            ['lime', 'amber', 'sky', 'olive', 'gray'],
            array_column($segments, 'hue'),
        );
    }

    /**
     * The enum has seven cases and the bar draws five segments, so
     * `confirmed` folds into New and `returned` folds into Cancelled.
     * This pins that mapping — it is the only way the two agree.
     */
    public function test_confirmed_and_returned_fold_into_neighbouring_buckets(): void
    {
        Order::factory()->create(['status' => OrderStatus::Pending]);
        Order::factory()->create(['status' => OrderStatus::Confirmed]);
        Order::factory()->create(['status' => OrderStatus::Returned]);

        $byLabel = collect($this->actingAsAdmin()
            ->getJson(route('admin.dashboard.range', ['range' => '7d']))
            ->json('ordersByStatus'))
            ->keyBy('label');

        $this->assertSame(2, $byLabel['New']['count']);
        $this->assertSame(1, $byLabel['Cancelled']['count']);
    }

    /**
     * `--seg` drives the width of each segment, so the five shares have
     * to add up to the whole bar.
     */
    public function test_status_percentages_total_one_hundred(): void
    {
        Order::factory()->count(7)->create(['status' => OrderStatus::Pending]);
        Order::factory()->count(3)->create(['status' => OrderStatus::Delivered]);

        $segments = $this->actingAsAdmin()
            ->getJson(route('admin.dashboard.range', ['range' => '7d']))
            ->json('ordersByStatus');

        $percentTotal = array_sum(array_column($segments, 'percent'));
        $this->assertEqualsWithDelta(100.0, $percentTotal, 0.01);

        $this->assertEqualsWithDelta(70.0, collect($segments)->firstWhere('label', 'New')['percent'], 0.01);
        $this->assertEqualsWithDelta(30.0, collect($segments)->firstWhere('label', 'Delivered')['percent'], 0.01);
    }

    public function test_status_bar_on_an_empty_store_reports_zero_not_a_division_error(): void
    {
        $segments = $this->actingAsAdmin()
            ->getJson(route('admin.dashboard.range', ['range' => '7d']))
            ->json('ordersByStatus');

        $this->assertSame(0, array_sum(array_column($segments, 'count')));

        foreach ($segments as $segment) {
            $this->assertEqualsWithDelta(0.0, $segment['percent'], 0.001);
        }
    }

    public function test_orders_total_matches_the_sum_of_the_buckets(): void
    {
        Order::factory()->count(4)->create(['status' => OrderStatus::Shipped]);
        Order::factory()->count(6)->create(['status' => OrderStatus::Delivered]);

        $response = $this->actingAsAdmin()
            ->getJson(route('admin.dashboard.range', ['range' => '7d']));

        $this->assertSame(10, $response->json('ordersTotal'));
    }

    // ─── KPI cards ───────────────────────────────────────────────────────

    /**
     * The KPI cards are described by the payload, so switching from
     * "today" to a longer range has to retitle the sales card — the
     * label is what stops a weekly figure reading as a daily one.
     */
    public function test_kpi_cards_follow_the_selected_range(): void
    {
        $this->seedSalesDay(Carbon::parse(self::FROZEN_NOW), total: 1500.00);

        $today = $this->actingAsAdmin()
            ->getJson(route('admin.dashboard.range', ['range' => 'today']))
            ->json('kpis');

        $this->assertSame('SALES TODAY', $today['sales']['label']);

        $week = $this->actingAsAdmin()
            ->getJson(route('admin.dashboard.range', ['range' => '7d']))
            ->json('kpis');

        $this->assertSame('SALES', $week['sales']['label']);
    }

    /**
     * Money is printed with Intl-style grouping by the service, and
     * admin.js drops it into the card as text — so it has to arrive as
     * a string, never a raw float.
     */
    public function test_kpi_money_arrives_pre_formatted(): void
    {
        $this->seedSalesDay(Carbon::parse(self::FROZEN_NOW), total: 12345.00);

        $kpis = $this->actingAsAdmin()
            ->getJson(route('admin.dashboard.range', ['range' => 'today']))
            ->json('kpis');

        $this->assertSame('12,345 EGP', $kpis['sales']['value']);
        $this->assertSame(1, $kpis['orders']['value']);
        $this->assertSame('Avg 12,345 EGP', $kpis['orders']['sub']);
    }

    /**
     * Orders and returns show a sub-line rather than a delta, which is
     * how the view decides whether to draw the ▲ arrow.
     */
    public function test_count_kpis_carry_a_sub_line_and_no_delta(): void
    {
        $kpis = $this->actingAsAdmin()
            ->getJson(route('admin.dashboard.range', ['range' => '7d']))
            ->json('kpis');

        $this->assertNull($kpis['orders']['delta']);
        $this->assertNull($kpis['returns']['delta']);
        $this->assertStringStartsWith('Avg ', $kpis['orders']['sub']);
        $this->assertStringStartsWith('waste ', $kpis['returns']['sub']);
    }

    public function test_returns_count_and_waste_are_reported(): void
    {
        $this->makeOrder(Carbon::parse(self::FROZEN_NOW), OrderStatus::Cancelled, total: 750.00);

        $kpis = $this->actingAsAdmin()
            ->getJson(route('admin.dashboard.range', ['range' => '7d']))
            ->json('kpis');

        $this->assertSame(1, $kpis['returns']['value']);
        $this->assertSame('waste 750 EGP', $kpis['returns']['sub']);
    }

    // ─── Sparklines ──────────────────────────────────────────────────────

    /**
     * renderSpark() maps every point through `28 - v * 24 - 2`, so the
     * payload has to be pre-normalised into 0..1 or the polyline
     * escapes the 28-unit viewBox.
     */
    public function test_sparklines_are_normalised_between_zero_and_one(): void
    {
        $this->seedSalesDay(Carbon::parse(self::FROZEN_NOW), total: 900.00);
        $this->seedSalesDay(Carbon::parse(self::FROZEN_NOW)->subDay(), total: 300.00);
        $this->seedSalesDay(Carbon::parse(self::FROZEN_NOW)->subDays(3), total: 600.00);

        $sparklines = $this->actingAsAdmin()
            ->getJson(route('admin.dashboard.range', ['range' => '7d']))
            ->json('sparklines');

        $this->assertCount(7, $sparklines['sales']);

        foreach (['sales', 'profit', 'orders', 'returns'] as $key) {
            foreach ($sparklines[$key] as $value) {
                $this->assertGreaterThanOrEqual(0.0, $value);
                $this->assertLessThanOrEqual(1.0, $value);
            }
        }
    }

    /**
     * A flat series has no meaningful low or high; pinning it to the
     * middle draws a level line instead of one collapsed onto the
     * baseline of every card.
     */
    public function test_flat_sparkline_sits_at_the_middle_of_the_card(): void
    {
        $sparklines = $this->actingAsAdmin()
            ->getJson(route('admin.dashboard.range', ['range' => '7d']))
            ->json('sparklines');

        foreach (['sales', 'profit', 'orders', 'returns'] as $key) {
            $this->assertSame([0.5, 0.5, 0.5, 0.5, 0.5, 0.5, 0.5], $sparklines[$key]);
        }
    }

    // ─── Range normalisation ─────────────────────────────────────────────

    public function test_service_falls_back_to_the_default_range(): void
    {
        $service = app(DashboardService::class);

        $this->assertSame('today', $service->normalizeRange(null));
        $this->assertSame('today', $service->normalizeRange('nonsense'));
        $this->assertSame('7d', $service->normalizeRange('7d'));
        $this->assertSame('30d', $service->normalizeRange('30d'));
    }

    /**
     * An unknown ?range= on the page must not error, and must not
     * silently claim to be a range the admin did not pick.
     */
    public function test_page_ignores_an_unknown_range_query_string(): void
    {
        $this->actingAsAdmin()
            ->get(route('admin.dashboard', ['range' => 'yesterday']))
            ->assertOk()
            ->assertViewHas('range', 'today');
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    /**
     * An order plus one line item, so profit has a cost of goods to
     * subtract.
     */
    private function seedSalesDay(Carbon $when, float $total, float $cost = 0.0): Order
    {
        $order = $this->makeOrder($when, OrderStatus::Delivered, $total);

        if ($cost <= 0.0) {
            return $order;
        }

        $product = Product::factory()->create();
        $variant = Variant::factory()->create([
            'product_id' => $product->id,
            'cost' => $cost,
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 1,
            'unit_price' => $total,
            'total_price' => $total,
        ]);

        return $order;
    }

    private function makeOrder(Carbon $when, OrderStatus $status, float $total): Order
    {
        return Order::factory()->create([
            'status' => $status,
            'total' => $total,
            'created_at' => $when,
            'updated_at' => $when,
        ]);
    }
}

<?php

namespace App\Services\Admin;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Variant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Admin dashboard numbers.
 *
 * Each public method returns exactly the shape the dashboard Blade
 * template (`resources/views/admin/dashboard.blade.php`) renders, or
 * exactly the shape the JSON range endpoint serialises, so the view
 * and public/admin/js/admin.js never reach back into the database on
 * their own.
 *
 * Time-period math is centralised in `rangeBounds()` and
 * `seriesFor()`, so the page render and the range endpoint always
 * agree on what "today" / "7d" / "30d" mean — including the "current"
 * bucket and the delta's comparison window.
 *
 * Chart maths lives in `seriesFor()`, which produces one bucket per
 * point (a day, or an hour for the `today` range) carrying sales,
 * profit, order count and return count. `getSalesProfitChart()` and
 * `getSparklines()` are two different projections of that one dataset,
 * so the bars and the KPI sparklines can never drift apart.
 */
class DashboardService
{
    /**
     * Default threshold (in units) at which a variant is surfaced in
     * the Low Stock strip and the "items low stock" attention card.
     */
    private const LOW_STOCK_THRESHOLD = 5;

    /**
     * Virtual full-shelf baseline used to render the stock progress
     * bar on the dashboard. The actual inventory does not need to
     * know this value; it is purely a presentation hint.
     */
    private const LOW_STOCK_MAX = 100;

    /**
     * The ranges the dashboard segmented control offers. This is the
     * single source of truth: the controller validates against it, the
     * service normalises against it, and the endpoint echoes it back.
     *
     * @var array<int, string>
     */
    public const RANGES = ['today', '7d', '30d'];

    /**
     * The range the dashboard opens on.
     *
     * This is the fallback for a caller that passes nothing or an
     * unrecognised value, and it is what `/admin` renders before the
     * segmented control is touched. It is `today` rather than `7d`
     * because the KPI cards are headed "SALES TODAY" on first paint —
     * the page and its numbers have to agree before the admin clicks
     * anything.
     */
    public const DEFAULT_RANGE = 'today';

    /**
     * How many calendar days each range spans. `today` is one day;
     * the rolling ranges end on today and count backwards.
     *
     * @var array<string, int>
     */
    private const RANGE_DAYS = [
        'today' => 1,
        '7d' => 7,
        '30d' => 30,
    ];

    /**
     * Human label for each range, shown in the range payload so the
     * browser can retitle the page without hard-coding the wording.
     *
     * @var array<string, string>
     */
    private const RANGE_LABELS = [
        'today' => 'Today',
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
    ];

    /**
     * The five buckets the "Orders by Status" bar draws.
     *
     * The enum has seven cases but the bar ships five segments, so two
     * stored statuses fold into a neighbouring bucket: `confirmed` has
     * no segment of its own and reads as "New" (an order the admin has
     * accepted but not yet started preparing), and `returned` is money
     * that came back rather than revenue that stuck, so it shares the
     * greyed-out "Cancelled" segment. The bar therefore stays
     * exhaustive — the five percentages always sum to 100.
     *
     * Returns are still called out on their own by the RETURNS KPI
     * card, which reports the count and the EGP written off.
     *
     * @var array<int, array{label: string, hue: string, statuses: array<int, string>}>
     */
    private const STATUS_BUCKETS = [
        ['label' => 'New', 'hue' => 'lime', 'statuses' => [OrderStatus::Pending->value, OrderStatus::Confirmed->value]],
        ['label' => 'Processing', 'hue' => 'amber', 'statuses' => [OrderStatus::Processing->value]],
        ['label' => 'Shipped', 'hue' => 'sky', 'statuses' => [OrderStatus::Shipped->value]],
        ['label' => 'Delivered', 'hue' => 'olive', 'statuses' => [OrderStatus::Delivered->value]],
        ['label' => 'Cancelled', 'hue' => 'gray', 'statuses' => [OrderStatus::Cancelled->value, OrderStatus::Returned->value]],
    ];

    /**
     * The three tiles that sit at the top of the dashboard.
     *
     * Each tile is rendered with a 2px coloured left border in the
     * view, matching one of the tokens in public/admin/css/admin.css:
     * `--abo-lime`, `--abo-coral`, `--abo-gray`. Tones that do not
     * map to a CSS class would render uncoloured, so every entry
     * here uses one of the three supported tones.
     *
     * @return array<int, array{count: int, label: string, href: string, tone: string}>
     */
    /**
     * Cache TTL constants for dashboard metrics (seconds).
     *
     * Attention tiles refresh every 2 minutes — short enough that a
     * new order shows up quickly, long enough to avoid hitting the
     * database on every admin page load.  KPI / chart data is slightly
     * staler (5 min) because it spans a range window and changes
     * gradually.  Static filter data (categories, colors, sizes) can
     * live in cache for 10 minutes.
     */
    private const CACHE_TTL_ATTENTION = 120;

    private const CACHE_TTL_KPI = 300;

    private const CACHE_TTL_ORDERS = 60;

    public function getAttentionMetrics(): array
    {
        return Cache::remember('dashboard.attention', self::CACHE_TTL_ATTENTION, function (): array {
            return [
                [
                    'count' => Order::query()
                        ->whereIn('status', [OrderStatus::Pending, OrderStatus::Processing])
                        ->count(),
                    'label' => 'orders waiting',
                    'href' => route('admin.orders.index'),
                    'tone' => 'lime',
                ],
                [
                    'count' => Variant::query()
                        ->where('quantity', '<=', self::LOW_STOCK_THRESHOLD)
                        ->count(),
                    'label' => 'items low stock',
                    'href' => route('admin.products.index'),
                    'tone' => 'coral',
                ],
                [
                    'count' => Order::query()
                        ->where('status', OrderStatus::Shipped)
                        ->count(),
                    'label' => 'COD to collect',
                    'href' => route('admin.orders.index'),
                    'tone' => 'gray',
                ],
            ];
        });
    }

    /**
     * Four KPI cards: sales / profit / orders / returns.
     *
     * Numbers come in pre-formatted with thousands separators and an
     * `EGP` suffix (or as plain integers for `orders` / `returns`).
     * Deltas include a leading sign so the view can drop them straight
     * into a `<span>` without further branching.
     *
     * `$range` selects the window: `today` compares against yesterday,
     * and the rolling ranges compare against the equally long window
     * immediately before them.
     *
     * @return array{
     *     sales: string,
     *     salesDelta: string,
     *     profit: string,
     *     profitDelta: string,
     *     orders: int,
     *     avgOrder: string,
     *     returns: int,
     *     returnsWaste: string
     * }
     */
    public function getKpiMetrics(string $range = 'today'): array
    {
        $range = $this->normalizeRange($range);

        return Cache::remember("dashboard.kpi.{$range}", self::CACHE_TTL_KPI, function () use ($range): array {
            [$from, $to] = $this->rangeBounds($range);
            [$prevFrom, $prevTo] = $this->rangeBounds($range, previous: true);

            $current = $this->totalsBetween($from, $to);
            $previous = $this->totalsBetween($prevFrom, $prevTo);

            $sales = (float) $current['sales'];
            $profit = $sales - (float) $current['cogs'];
            $prevSales = (float) $previous['sales'];
            $prevProfit = $prevSales - (float) $previous['cogs'];

            $ordersCount = (int) $current['orders_count'];
            $avgOrder = $ordersCount > 0 ? $sales / $ordersCount : 0.0;

            $returns = (int) $current['returns_count'];
            $returnsWaste = (float) $current['returns_total'];

            return [
                'sales' => $this->formatMoney($sales),
                'salesDelta' => $this->formatDelta($sales, $prevSales),
                'profit' => $this->formatMoney($profit),
                'profitDelta' => $this->formatDelta($profit, $prevProfit),
                'orders' => $ordersCount,
                'avgOrder' => $this->formatMoney($avgOrder),
                'returns' => $returns,
                'returnsWaste' => $this->formatMoney($returnsWaste),
            ];
        });
    }

    /**
     * The same four KPIs, keyed and shaped for the JSON endpoint.
     *
     * The Blade view builds its cards from an inline array; the
     * endpoint has to hand the browser one self-describing object per
     * card so `admin.js` can rewrite the label, the value and the
     * delta line without re-creating markup. `delta` is null for the
     * two cards that show a sub-line instead (`Avg …` / `waste …`),
     * matching how the view branches on `!empty($k['delta'])`.
     *
     * @return array<string, array{
     *     key: string,
     *     label: string,
     *     value: string|int,
     *     delta: string|null,
     *     sub: string|null,
     *     tone: string
     * }>
     */
    public function getKpiPayload(string $range = 'today'): array
    {
        $range = $this->normalizeRange($range);
        $metrics = $this->getKpiMetrics($range);

        // "Sales today" only reads correctly on the single-day range;
        // over a week or a month the card is plain "Sales".
        $salesLabel = $range === 'today' ? 'SALES TODAY' : 'SALES';

        return [
            'sales' => [
                'key' => 'sales',
                'label' => $salesLabel,
                'value' => $metrics['sales'],
                'delta' => $metrics['salesDelta'],
                'sub' => null,
                'tone' => 'lime',
            ],
            'profit' => [
                'key' => 'profit',
                'label' => 'NET PROFIT',
                'value' => $metrics['profit'],
                'delta' => $metrics['profitDelta'],
                'sub' => null,
                'tone' => 'lime',
            ],
            'orders' => [
                'key' => 'orders',
                'label' => 'ORDERS',
                'value' => $metrics['orders'],
                'delta' => null,
                'sub' => 'Avg '.$metrics['avgOrder'],
                'tone' => 'olive',
            ],
            'returns' => [
                'key' => 'returns',
                'label' => 'RETURNS',
                'value' => $metrics['returns'],
                'delta' => null,
                'sub' => 'waste '.$metrics['returnsWaste'],
                'tone' => 'coral',
            ],
        ];
    }

    /**
     * The Sales vs Profit bars for one range.
     *
     * Each point is one bucket: a calendar day for `7d` / `30d`, and
     * an hour for `today` (so the single-day range shows the trading
     * day's shape and its peak hours instead of one flat column).
     *
     * The rolling ranges end on today and count backwards, so the
     * `current` flag always lands on the last point. Labels are real
     * calendar values — the three-letter weekday for `7d`, the day of
     * the month for `30d`, the hour for `today` — rather than a fixed
     * Sat→Fri sequence, because a hard-coded week would mislabel the
     * data on any day that is not a Friday.
     *
     * Money is reported in thousands of EGP (the chart's "K EGP"
     * axis), rounded to one decimal.
     *
     * @return array<int, array{day: string, sales: float, profit: float, current: bool}>
     */
    public function getSalesProfitChart(string $range = '7d'): array
    {
        return array_map(fn (array $bucket): array => [
            'day' => $bucket['label'],
            'sales' => round($bucket['sales'] / 1000, 1),
            'profit' => round($bucket['profit'] / 1000, 1),
            'current' => $bucket['current'],
        ], $this->seriesFor($range));
    }

    /**
     * Order counts per status bucket, with each bucket's share of the
     * total as a percentage.
     *
     * `percent` is what the segmented bar renders as `--seg`, and the
     * five values always add up to 100 — including when the table is
     * empty, where every bucket reads 0.
     *
     * @return array<int, array{label: string, count: int, percent: float, hue: string}>
     */
    public function getOrdersByStatus(): array
    {
        return Cache::remember('dashboard.orders_by_status', self::CACHE_TTL_ATTENTION, function (): array {
            $counts = DB::table('orders')
                ->selectRaw('status, COUNT(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status');

            $total = 0;
            foreach (self::STATUS_BUCKETS as $bucket) {
                foreach ($bucket['statuses'] as $status) {
                    $total += (int) ($counts[$status] ?? 0);
                }
            }

            return array_map(function (array $bucket) use ($counts, $total): array {
                $count = 0;
                foreach ($bucket['statuses'] as $status) {
                    $count += (int) ($counts[$status] ?? 0);
                }

                return [
                    'label' => $bucket['label'],
                    'count' => $count,
                    'percent' => $total > 0 ? round($count / $total * 100, 2) : 0.0,
                    'hue' => $bucket['hue'],
                ];
            }, self::STATUS_BUCKETS);
        });
    }

    /**
     * One normalised point series per KPI card, ready for the
     * sparkline `<polyline>`.
     *
     * Values are rescaled to 0..1 within their own series, which is
     * the contract `renderSpark()` in public/admin/js/admin.js draws
     * against (`y = 28 - v * 24 - 2`). A flat series — no orders at
     * all, the common case on a fresh install — resolves to 0.5
     * everywhere, so the line draws flat down the middle of the card
     * instead of collapsing onto the baseline.
     *
     * @return array<string, array<int, float>>
     */
    public function getSparklines(string $range = self::DEFAULT_RANGE): array
    {
        $series = $this->seriesFor($range);

        return [
            'sales' => $this->normalize(array_column($series, 'sales')),
            'profit' => $this->normalize(array_column($series, 'profit')),
            'orders' => $this->normalize(array_column($series, 'orders')),
            'returns' => $this->normalize(array_column($series, 'returns')),
        ];
    }

    /**
     * Everything the range endpoint returns for one selection.
     *
     * Assembled from the same methods the page render uses, so a range
     * switch can never show bars that disagree with the KPI cards.
     *
     * @return array{
     *     range: string,
     *     label: string,
     *     kpis: array<string, array<string, mixed>>,
     *     salesProfit: array<int, array<string, mixed>>,
     *     ordersByStatus: array<int, array<string, mixed>>,
     *     ordersTotal: int,
     *     sparklines: array<string, array<int, float>>
     * }
     */
    public function getRangePayload(string $range = self::DEFAULT_RANGE): array
    {
        $range = $this->normalizeRange($range);
        $ordersByStatus = $this->getOrdersByStatus();

        return [
            'range' => $range,
            'label' => self::RANGE_LABELS[$range],
            'kpis' => $this->getKpiPayload($range),
            'salesProfit' => $this->getSalesProfitChart($range),
            'ordersByStatus' => $ordersByStatus,
            'ordersTotal' => array_sum(array_column($ordersByStatus, 'count')),
            'sparklines' => $this->getSparklines($range),
        ];
    }

    /**
     * Coerce a caller-supplied range onto one of `RANGES`.
     *
     * The controller rejects unknown values with a 422 before calling
     * in here; this is the defensive second line that keeps an
     * unvalidated caller from reaching a branch that does not exist.
     */
    public function normalizeRange(?string $range): string
    {
        return is_string($range) && in_array($range, self::RANGES, true)
            ? $range
            : self::DEFAULT_RANGE;
    }

    /**
     * The most recent orders for the Latest Orders card.
     *
     * `customer` is eager-loaded because the card uses either the
     * linked Customer's name (registered buyer) or the order's
     * captured `customer_name` (guest checkout) — both reads happen
     * in PHP, never in SQL.
     *
     * @return Collection<int, array{
     *     id: string,
     *     customer: string,
     *     total: string,
     *     status: string,
     *     url: string
     * }>
     */
    public function getLatestOrders(int $limit = 5): Collection
    {
        $rows = Cache::remember("dashboard.latest_orders.{$limit}", self::CACHE_TTL_ORDERS, function () use ($limit): array {
            return Order::query()
                ->with('customer')
                ->latest('id')
                ->limit($limit)
                ->get()
                ->map(fn (Order $order): array => [
                    'id' => (string) $order->order_number,
                    'customer' => $this->customerDisplayName($order),
                    'total' => $this->formatMoney((float) $order->total),
                    'status' => $order->status->label(),
                    'url' => route('admin.orders.index'),
                ])
                ->all();
        });

        return collect($rows);
    }

    /**
     * Variants whose remaining quantity has dropped to or below the
     * low-stock threshold, ascending so the emptiest rows lead.
     *
     * Eager-loading the three relations (`product`, `color`, `size`)
     * keeps the per-row mapping in PHP and avoids an N+1.
     *
     * @return Collection<int, array{
     *     product: string,
     *     variant: string,
     *     stock: int,
     *     max: int
     * }>
     */
    public function getLowStockItems(int $limit = 4): Collection
    {
        $rows = Cache::remember("dashboard.low_stock.{$limit}", self::CACHE_TTL_ATTENTION, function () use ($limit): array {
            return Variant::query()
                ->with(['product', 'color', 'size'])
                ->where('quantity', '<=', self::LOW_STOCK_THRESHOLD)
                ->orderBy('quantity')
                ->orderBy('id')
                ->limit($limit)
                ->get()
                ->map(fn (Variant $variant): array => [
                    'product' => (string) ($variant->product?->name ?? '—'),
                    'variant' => $this->variantDisplay($variant),
                    'stock' => (int) $variant->quantity,
                    'max' => self::LOW_STOCK_MAX,
                ])
                ->all();
        });

        return collect($rows);
    }

    /**
     * Calendar boundaries for a range window.
     *
     * Every range ends on today and counts backwards, so `7d` is
     * today plus the six days before it. The `previous` flag shifts
     * the window back by its own length, which is the comparison
     * window the KPI deltas divide by: yesterday for `today`, the
     * seven days before the current week for `7d`, and so on.
     *
     * The window is closed at `now()` rather than at midnight, because
     * that is where `bucketKeys()` stops drawing. A row timestamped
     * later today would otherwise count towards the KPI card while
     * being absent from the chart beside it — the same range reporting
     * two different totals. Clamping keeps the card and the bars
     * describing the same elapsed slice of the day.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function rangeBounds(string $range, bool $previous = false): array
    {
        $days = self::RANGE_DAYS[$this->normalizeRange($range)];
        $shift = $previous ? $days : 0;

        $start = now()->subDays($days - 1 + $shift)->startOfDay();
        $end = now()->subDays($shift)->endOfDay();

        if ($end->greaterThan(now())) {
            $end = now();
        }

        return [$start, $end];
    }

    /**
     * One dataset behind both the bars and the sparklines.
     *
     * Returns one bucket per chart point in chronological order,
     * including the empty ones, so the x-axis never changes shape as
     * orders come in. Sales and order counts come from one grouped
     * read of `orders`; cost of goods from the single join that needs
     * the variant `cost`; cancelled orders from a third grouped read
     * that feeds the RETURNS card.
     *
     * Profit follows the page's existing definition — sales minus the
     * cost of goods — rather than the order total, so a shipped order
     * counts at the same moment a delivered one does.
     *
     * @return array<int, array{
     *     key: string,
     *     label: string,
     *     current: bool,
     *     sales: float,
     *     profit: float,
     *     orders: int,
     *     returns: int
     * }>
     */
    private function seriesFor(string $range): array
    {
        $range = $this->normalizeRange($range);

        return Cache::remember("dashboard.series.{$range}", self::CACHE_TTL_KPI, function () use ($range): array {
            [$from, $to] = $this->rangeBounds($range);
            $expression = $this->bucketExpression($range);

            $sales = $this->salesByBucket($expression, $from, $to);
            $cogs = $this->cogsByBucket($expression, $from, $to);
            $returns = $this->returnsByBucket($expression, $from, $to);

            return array_map(function (array $bucket) use ($sales, $cogs, $returns): array {
                $key = $bucket['key'];
                $salesValue = (float) ($sales[$key]['sales'] ?? 0.0);

                return [
                    'key' => $key,
                    'label' => $bucket['label'],
                    'current' => $bucket['current'],
                    'sales' => $salesValue,
                    'profit' => $salesValue - (float) ($cogs[$key] ?? 0.0),
                    'orders' => (int) ($sales[$key]['orders'] ?? 0),
                    'returns' => (int) ($returns[$key] ?? 0),
                ];
            }, $this->bucketKeys($range, $from, $to));
        });
    }

    /**
     * Every bucket key in the window, empty ones included.
     *
     * The `today` range stops at the current hour rather than running
     * to midnight, so a dashboard opened at 09:00 does not draw
     * fifteen zero-width hours of a day that has not happened yet.
     *
     * @return array<int, array{key: string, label: string, current: bool}>
     */
    private function bucketKeys(string $range, Carbon $from, Carbon $to): array
    {
        $hourly = $range === 'today';
        $cursor = $from->copy()->startOf($hourly ? 'hour' : 'day');
        $limit = $hourly && now()->lessThan($to) ? now() : $to;

        // Weekday abbreviation for the week view, day of the month for
        // the month view, and the 24-hour clock for the day view.
        $labelFormat = $hourly ? 'H' : ($range === '30d' ? 'j' : 'D');

        // The key has to match what bucketExpression() produces, or
        // every lookup misses and the series reads as all zeros. The
        // hourly branch is scoped to a single day, so the bare 24-hour
        // clock is already unique within the window and lines up with
        // SUBSTR(created_at, 12, 2).
        $keyFormat = $hourly ? 'H' : 'Y-m-d';

        $buckets = [];

        while ($cursor->lessThanOrEqualTo($limit)) {
            $buckets[] = [
                'key' => $cursor->format($keyFormat),
                'label' => $cursor->format($labelFormat),
                'current' => $hourly ? $cursor->isSameHour(now()) : $cursor->isSameDay(now()),
            ];
            if ($hourly) {
                $cursor->addHour();
            } else {
                $cursor->addDay();
            }
        }

        return $buckets;
    }

    /**
     * The SQL expression that collapses `created_at` to a bucket key.
     *
     * `DATE()` behaves the same on MySQL and SQLite. `SUBSTR` on the
     * timestamp string is the portable way to pull the hour out of a
     * DATETIME on both drivers — `HOUR()` does not exist in SQLite,
     * and the test suite runs against an in-memory SQLite database.
     */
    private function bucketExpression(string $range): string
    {
        // The COGS branch joins orders / order_items / variants, all of
        // which carry a `created_at`, so the column is always qualified
        // rather than relying on the single-table callers.
        return $range === 'today'
            ? 'SUBSTR(orders.created_at, 12, 2)'
            : 'DATE(orders.created_at)';
    }

    /**
     * Sales and order counts per bucket, keyed by bucket key.
     *
     * Cancelled orders are excluded from both — a cancelled order is
     * not revenue and not a sale — which is the same rule
     * `totalsBetween()` applies to the KPI cards.
     *
     * @return array<string, array{sales: float, orders: int}>
     */
    private function salesByBucket(string $expression, Carbon $from, Carbon $to): array
    {
        return DB::table('orders')
            ->whereBetween('orders.created_at', [$from, $to])
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->selectRaw("{$expression} as bucket, SUM(total) as sales, COUNT(*) as orders")
            ->groupByRaw($expression)
            ->get()
            ->mapWithKeys(fn ($row): array => [
                (string) $row->bucket => [
                    'sales' => (float) $row->sales,
                    'orders' => (int) $row->orders,
                ],
            ])
            ->all();
    }

    /**
     * Cost of goods per bucket, keyed by bucket key.
     *
     * The inner join on `variants` is the one place that drops order
     * items: a line whose variant has since been deleted (the column
     * is nullable, `nullOnDelete`) contributes revenue but no known
     * cost, so it silently lowers profit. That is the same blind spot
     * `totalsBetween()` has always had, kept consistent deliberately.
     *
     * @return array<string, float>
     */
    private function cogsByBucket(string $expression, Carbon $from, Carbon $to): array
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('variants', 'variants.id', '=', 'order_items.variant_id')
            ->whereBetween('orders.created_at', [$from, $to])
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->selectRaw("{$expression} as bucket, SUM(order_items.quantity * variants.cost) as cogs")
            ->groupByRaw($expression)
            ->get()
            ->mapWithKeys(fn ($row): array => [(string) $row->bucket => (float) $row->cogs])
            ->all();
    }

    /**
     * Cancelled order counts per bucket, keyed by bucket key.
     *
     * @return array<string, int>
     */
    private function returnsByBucket(string $expression, Carbon $from, Carbon $to): array
    {
        return DB::table('orders')
            ->whereBetween('orders.created_at', [$from, $to])
            ->where('orders.status', OrderStatus::Cancelled->value)
            ->selectRaw("{$expression} as bucket, COUNT(*) as returns")
            ->groupByRaw($expression)
            ->get()
            ->mapWithKeys(fn ($row): array => [(string) $row->bucket => (int) $row->returns])
            ->all();
    }

    /**
     * Aggregate sales / cost-of-goods / order counts / returns inside
     * the given window.
     *
     * Sales/orders/returns run as independent aggregates on the
     * orders table (two scoped to non-cancelled, two to cancelled,
     * one combined) — they share the driver's cached index lookups
     * for the `created_at` range and are individually cheap. The COGS
     * branch is the only join because we need the variant's `cost`
     * column.
     *
     * @return array{sales: float, cogs: float, orders_count: int, returns_count: int, returns_total: float}
     */
    private function totalsBetween(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $completed = Order::query()
            ->whereBetween('created_at', [$from, $to])
            ->where('status', '!=', OrderStatus::Cancelled);

        $sales = (float) $completed->sum('total');
        $ordersCount = (int) $completed->count('id');

        $cancelled = Order::query()
            ->whereBetween('created_at', [$from, $to])
            ->where('status', OrderStatus::Cancelled);

        $returnsCount = (int) $cancelled->count('id');
        $returnsTotal = (float) $cancelled->sum('total');

        $cogs = (float) DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('variants', 'variants.id', '=', 'order_items.variant_id')
            ->whereBetween('orders.created_at', [$from, $to])
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->sum(DB::raw('order_items.quantity * variants.cost'));

        return [
            'sales' => $sales,
            'cogs' => $cogs,
            'orders_count' => $ordersCount,
            'returns_count' => $returnsCount,
            'returns_total' => $returnsTotal,
        ];
    }

    /**
     * Rescale a series into 0..1 for the sparkline renderer.
     *
     * @param  array<int, int|float>  $values
     * @return array<int, float>
     */
    private function normalize(array $values): array
    {
        if ($values === []) {
            return [];
        }

        $max = max($values);
        $min = min($values);
        $span = $max - $min;

        // A flat series has no meaningful 0 or 1; pinning it to the
        // middle draws a level line instead of a line on the floor.
        if ($span <= 0.0) {
            return array_fill(0, count($values), 0.5);
        }

        return array_map(
            fn (int|float $value): float => round(((float) $value - (float) $min) / $span, 4),
            array_values($values),
        );
    }

    /**
     * Display name for a `Latest Orders` row, preferring the linked
     * Customer over the snapshot column used for guest checkout.
     */
    private function customerDisplayName(Order $order): string
    {
        $registered = $order->customer?->name;

        if (is_string($registered) && $registered !== '') {
            return $registered;
        }

        $snapshot = $order->customer_name;

        return is_string($snapshot) && $snapshot !== '' ? $snapshot : '—';
    }

    /**
     * "Color / Size" label for the Low Stock row, gracefully
     * collapsing to a single label when one side is missing.
     */
    private function variantDisplay(Variant $variant): string
    {
        $color = $variant->color?->name;
        $size = $variant->size?->name;

        if ($color && $size) {
            return "{$color} / {$size}";
        }

        return (string) ($color ?: $size ?: '—');
    }

    /**
     * Format an amount as `1,250 EGP` (no decimals, en-US grouping).
     */
    private function formatMoney(float $value): string
    {
        return number_format($value, 0, '.', ',').' EGP';
    }

    /**
     * Format a percentage change with an explicit `+` / `-` sign.
     *
     * A 0-from-0 delta is reported as `+0%` rather than dividing by
     * zero — it keeps the view free of conditional logic and matches
     * the design tokens that always show a signed arrow.
     */
    private function formatDelta(float $current, float $previous): string
    {
        if ($previous == 0.0) {
            $delta = $current > 0 ? 1.0 : 0.0;
        } else {
            $delta = ($current - $previous) / abs($previous);
        }

        $rounded = (int) round($delta * 100);

        return ($rounded >= 0 ? '+' : '').$rounded.'%';
    }
}

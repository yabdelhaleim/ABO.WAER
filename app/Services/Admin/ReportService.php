<?php

namespace App\Services\Admin;

use App\Enums\OrderStatus;
use App\Models\CancellationReason;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Admin reports screen: the sales, profit, waste and top-product
 * numbers, read from the database.
 *
 * The screen used to draw a static REPORTS_DATA object that lived in
 * public/admin/js/reports.js — every number on it was invented, and
 * the "compare to previous period" arrows were a fixed 7% and a fixed
 * -5%. This service builds the same shape from the orders, the order
 * items and the catalogue, so the browser only draws what the server
 * measured.
 *
 * Definitions
 * -----------
 * A sale is an order that is not cancelled and not returned: a
 * returned order's money went back to the customer, so it is waste
 * rather than revenue. Every aggregate below uses that one rule.
 *
 * Profit follows the dashboard's definition — sales minus the cost of
 * goods — so the two screens never disagree about the same period.
 * The cost of goods is the sum of `quantity * variants.cost`; a line
 * whose variant has since been deleted contributes its revenue but no
 * known cost, which lowers the profit by that line's unknown cost.
 *
 * Shipping is reported on its own (the customer paid it, so it is
 * inside the revenue) rather than deducted from the profit.
 */
class ReportService
{
    /**
     * The ranges the toolbar offers.
     *
     * @var array<int, string>
     */
    public const RANGES = ['today', '7d', '30d', 'month', 'custom'];

    public const DEFAULT_RANGE = '7d';

    /**
     * The statuses that count as a sale.
     *
     * @var array<int, string>
     */
    private const SALE_STATUSES = [
        OrderStatus::Pending->value,
        OrderStatus::Confirmed->value,
        OrderStatus::Processing->value,
        OrderStatus::Shipped->value,
        OrderStatus::Delivered->value,
    ];

    /**
     * The statuses that count as waste.
     *
     * @var array<int, string>
     */
    private const WASTE_STATUSES = [
        OrderStatus::Cancelled->value,
        OrderStatus::Returned->value,
    ];

    /**
     * The order status buckets the "orders by status" bar draws.
     *
     * The same five buckets the dashboard uses, so the two screens
     * count an order the same way. `pending` and `confirmed` are both
     * "new" here, and a returned order sits with the cancelled ones.
     *
     * @var array<int, array{key: string, label: string, hue: string, statuses: array<int, string>}>
     */
    private const STATUS_SEGMENTS = [
        ['key' => 'new', 'label' => 'New', 'hue' => 'lime', 'statuses' => [OrderStatus::Pending->value, OrderStatus::Confirmed->value]],
        ['key' => 'processing', 'label' => 'Processing', 'hue' => 'amber', 'statuses' => [OrderStatus::Processing->value]],
        ['key' => 'shipped', 'label' => 'Shipped', 'hue' => 'sky', 'statuses' => [OrderStatus::Shipped->value]],
        ['key' => 'delivered', 'label' => 'Delivered', 'hue' => 'olive', 'statuses' => [OrderStatus::Delivered->value]],
        ['key' => 'cancelled', 'label' => 'Cancelled', 'hue' => 'gray', 'statuses' => [OrderStatus::Cancelled->value, OrderStatus::Returned->value]],
    ];

    /**
     * The colours the waste chart cycles through, one per reason.
     *
     * @var array<int, string>
     */
    private const REASON_COLORS = ['coral', 'amber', 'dim', 'stone'];

    /**
     * The whole report for one range.
     *
     * @param  array{range?: string|null, from?: string|null, to?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function payload(array $filters = []): array
    {
        $range = $this->normalizeRange($filters['range'] ?? null);

        [$from, $to] = $this->bounds($range, $filters['from'] ?? null, $filters['to'] ?? null);
        [$previousFrom, $previousTo] = $this->previousBounds($range, $from, $to);

        $current = $this->period($from, $to, $range);
        $previous = $this->period($previousFrom, $previousTo, $range);

        return [
            'range' => [
                'key' => $range,
                'label' => $this->rangeLabel($range, $from, $to),
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'series' => $current['series'],
            'totals' => $current['totals'],
            'previous' => [
                'series' => $previous['series'],
                'totals' => $previous['totals'],
            ],
            'reasons' => $current['reasons'],
            'reasonsByDay' => $current['reasonsByDay'],
            'governorates' => $current['governorates'],
            'categories' => $current['categories'],
            'statusSegments' => $current['statusSegments'],
            'costBreakdown' => $current['costBreakdown'],
            'topProducts' => $current['topProducts'],
            'topReturnReason' => $current['topReturnReason'],
        ];
    }

    /**
     * The report as flat rows, for the CSV export.
     *
     * The export is sectioned — a title, a header row and its rows —
     * because the report is five tables and one wide table would be
     * unreadable in a spreadsheet.
     *
     * @param  array{range?: string|null, from?: string|null, to?: string|null}  $filters
     * @return array<int, array{title: string, headers: array<int, string>, rows: array<int, array<int, mixed>>}>
     */
    public function exportSections(array $filters = []): array
    {
        $payload = $this->payload($filters);

        $series = $payload['series'];
        $daily = [];
        foreach ($series['days'] as $index => $label) {
            $daily[] = [
                $label,
                $series['revenue'][$index] ?? 0,
                $series['orders'][$index] ?? 0,
                $series['items'][$index] ?? 0,
                $series['profit'][$index] ?? 0,
                $series['cog'][$index] ?? 0,
                $series['ship'][$index] ?? 0,
                $series['returns'][$index] ?? 0,
                $series['waste'][$index] ?? 0,
            ];
        }

        $totals = $payload['totals'];

        return [
            [
                'title' => 'SUMMARY — '.$payload['range']['label'],
                'headers' => ['Metric', 'Value'],
                'rows' => [
                    ['Range', $payload['range']['from'].' → '.$payload['range']['to']],
                    ['Revenue', $totals['revenue']],
                    ['Orders', $totals['orders']],
                    ['Items sold', $totals['items']],
                    ['Average order', $totals['aov']],
                    ['Cost of goods', $totals['cog']],
                    ['Shipping collected', $totals['ship']],
                    ['Net profit', $totals['profit']],
                    ['Profit margin %', $totals['margin']],
                    ['Returned or cancelled orders', $totals['returns']],
                    ['Waste value', $totals['waste']],
                ],
            ],
            [
                'title' => 'BY DAY',
                'headers' => ['Day', 'Revenue', 'Orders', 'Items', 'Profit', 'Cost of goods', 'Shipping', 'Returns', 'Waste'],
                'rows' => $daily,
            ],
            [
                'title' => 'TOP PRODUCTS',
                'headers' => ['Product', 'Category', 'Units', 'Revenue', 'Profit', 'Return rate %'],
                'rows' => array_map(fn (array $product): array => [
                    $product['name'],
                    $product['cat'],
                    $product['units'],
                    $product['rev'],
                    $product['profit'],
                    $product['ret'],
                ], $payload['topProducts']),
            ],
            [
                'title' => 'SALES BY GOVERNORATE',
                'headers' => ['Governorate', 'Share %', 'Revenue', 'Orders'],
                'rows' => array_map(fn (array $row): array => [
                    $row['name'],
                    $row['share'],
                    $row['revenue'],
                    $row['orders'],
                ], $payload['governorates']),
            ],
            [
                'title' => 'PROFIT BY CATEGORY',
                'headers' => ['Category', 'Share %', 'Revenue', 'Cost of goods', 'Profit'],
                'rows' => array_map(fn (array $row): array => [
                    $row['name'],
                    $row['share'],
                    $row['revenue'],
                    $row['cog'],
                    $row['profit'],
                ], $payload['categories']),
            ],
        ];
    }

    /**
     * One period: the series, the totals and the breakdowns.
     *
     * @return array{series: array<string, array<int, mixed>>, totals: array<string, float|int>, reasons: array<int, array<string, mixed>>, reasonsByDay: array<int, array<string, int>>, governorates: array<int, array<string, mixed>>, categories: array<int, array<string, mixed>>, statusSegments: array<int, array<string, mixed>>, costBreakdown: array<int, array<string, mixed>>, topProducts: array<int, array<string, mixed>>, topReturnReason: array<string, mixed>}
     */
    protected function period(Carbon $from, Carbon $to, string $range): array
    {
        $buckets = $this->buckets($from, $to, $range);
        $expression = $this->bucketExpression($range);

        $sales = $this->salesByBucket($expression, $from, $to);
        $items = $this->itemsByBucket($expression, $from, $to);
        $shipping = $this->shippingByBucket($expression, $from, $to);
        $waste = $this->wasteByBucket($expression, $from, $to);

        $series = [
            'days' => array_map(fn (array $bucket): string => $bucket['label'], $buckets),
            'revenue' => [],
            'orders' => [],
            'items' => [],
            'profit' => [],
            'cog' => [],
            'ship' => [],
            'returns' => [],
            'waste' => [],
        ];

        foreach ($buckets as $bucket) {
            $key = $bucket['key'];
            $revenue = (float) ($sales[$key]['revenue'] ?? 0.0);
            $cog = (float) ($items[$key]['cog'] ?? 0.0);

            $series['revenue'][] = $revenue;
            $series['orders'][] = (int) ($sales[$key]['orders'] ?? 0);
            $series['items'][] = (int) ($items[$key]['items'] ?? 0);
            $series['cog'][] = $cog;
            $series['profit'][] = $revenue - $cog;
            $series['ship'][] = (float) ($shipping[$key] ?? 0.0);
            $series['returns'][] = (int) ($waste[$key]['returns'] ?? 0);
            $series['waste'][] = (float) ($waste[$key]['waste'] ?? 0.0);
        }

        $revenue = array_sum($series['revenue']);
        $orders = array_sum($series['orders']);
        $cog = array_sum($series['cog']);
        $profit = $revenue - $cog;

        $reasons = $this->reasons($expression, $from, $to, $buckets);
        $statusSegments = $this->statusSegments($from, $to);
        $governorates = $this->governorates($from, $to);
        $categories = $this->categories($from, $to);
        $topProducts = $this->topProducts($from, $to);

        return [
            'series' => $series,
            'totals' => [
                'revenue' => round($revenue, 2),
                'orders' => $orders,
                'items' => array_sum($series['items']),
                'aov' => $orders > 0 ? round($revenue / $orders, 2) : 0.0,
                'cog' => round($cog, 2),
                'ship' => round(array_sum($series['ship']), 2),
                'profit' => round($profit, 2),
                'margin' => $revenue > 0 ? round(($profit / $revenue) * 100, 2) : 0.0,
                'returns' => array_sum($series['returns']),
                'waste' => round(array_sum($series['waste']), 2),
            ],
            'reasons' => $reasons['list'],
            'reasonsByDay' => $reasons['byBucket'],
            'governorates' => $governorates,
            'categories' => $categories,
            'statusSegments' => $statusSegments,
            'costBreakdown' => [
                ['key' => 'cog', 'label' => 'Cost of goods', 'count' => round($cog, 2), 'hue' => 'lime'],
                ['key' => 'ship', 'label' => 'Shipping collected', 'count' => round(array_sum($series['ship']), 2), 'hue' => 'olive'],
            ],
            'topProducts' => $topProducts,
            'topReturnReason' => $this->topReturnReason($reasons['list'], $reasons['byBucket']),
        ];
    }

    /**
     * Revenue and order count per bucket, keyed by bucket key.
     *
     * @return array<string, array{revenue: float, orders: int}>
     */
    protected function salesByBucket(string $expression, Carbon $from, Carbon $to): array
    {
        return DB::table('orders')
            ->whereBetween('created_at', [$from, $to])
            ->whereIn('status', self::SALE_STATUSES)
            ->selectRaw("{$expression} as bucket, SUM(total) as revenue, COUNT(*) as orders")
            ->groupByRaw($expression)
            ->get()
            ->mapWithKeys(fn ($row): array => [
                (string) $row->bucket => [
                    'revenue' => (float) $row->revenue,
                    'orders' => (int) $row->orders,
                ],
            ])
            ->all();
    }

    /**
     * Units sold and cost of goods per bucket, keyed by bucket key.
     *
     * The join on `variants` is a left join: a line whose variant has
     * since been deleted still sold, so it still counts as a unit —
     * it just has no known cost.
     *
     * @return array<string, array{items: int, cog: float}>
     */
    protected function itemsByBucket(string $expression, Carbon $from, Carbon $to): array
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('variants', 'variants.id', '=', 'order_items.variant_id')
            ->whereBetween('orders.created_at', [$from, $to])
            ->whereIn('orders.status', self::SALE_STATUSES)
            ->selectRaw("{$expression} as bucket, SUM(order_items.quantity) as items")
            ->selectRaw('SUM(order_items.quantity * COALESCE(variants.cost, 0)) as cog')
            ->groupByRaw($expression)
            ->get()
            ->mapWithKeys(fn ($row): array => [
                (string) $row->bucket => [
                    'items' => (int) $row->items,
                    'cog' => (float) $row->cog,
                ],
            ])
            ->all();
    }

    /**
     * The shipping the customers paid, per bucket.
     *
     * @return array<string, float>
     */
    protected function shippingByBucket(string $expression, Carbon $from, Carbon $to): array
    {
        return DB::table('orders')
            ->whereBetween('created_at', [$from, $to])
            ->whereIn('status', self::SALE_STATUSES)
            ->selectRaw("{$expression} as bucket, SUM(shipping_fee) as ship")
            ->groupByRaw($expression)
            ->get()
            ->mapWithKeys(fn ($row): array => [(string) $row->bucket => (float) $row->ship])
            ->all();
    }

    /**
     * Returned and cancelled orders per bucket: how many and what they
     * were worth.
     *
     * @return array<string, array{returns: int, waste: float}>
     */
    protected function wasteByBucket(string $expression, Carbon $from, Carbon $to): array
    {
        return DB::table('orders')
            ->whereBetween('created_at', [$from, $to])
            ->whereIn('status', self::WASTE_STATUSES)
            ->selectRaw("{$expression} as bucket, COUNT(*) as returns, SUM(total) as waste")
            ->groupByRaw($expression)
            ->get()
            ->mapWithKeys(fn ($row): array => [
                (string) $row->bucket => [
                    'returns' => (int) $row->returns,
                    'waste' => (float) $row->waste,
                ],
            ])
            ->all();
    }

    /**
     * The return reasons, and how many of each happened in each bucket.
     *
     * Only the reasons that actually happened in the period are listed
     * — an empty legend is noise, and the reasons table is where the
     * admin looks for the one that is growing.
     *
     * @param  array<int, array{key: string, label: string}>  $buckets
     * @return array{list: array<int, array<string, mixed>>, byBucket: array<int, array<string, int>>}
     */
    protected function reasons(string $expression, Carbon $from, Carbon $to, array $buckets): array
    {
        $rows = DB::table('orders')
            ->whereBetween('orders.created_at', [$from, $to])
            ->whereIn('orders.status', self::WASTE_STATUSES)
            ->whereNotNull('orders.cancellation_reason_id')
            ->selectRaw("{$expression} as bucket, orders.cancellation_reason_id as reason, COUNT(*) as count")
            ->groupByRaw($expression)
            ->groupBy('orders.cancellation_reason_id')
            ->get();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row->reason][(string) $row->bucket] = (int) $row->count;
        }

        $names = CancellationReason::query()
            ->whereIn('id', array_keys($counts))
            ->get()
            ->keyBy('id');

        $list = [];
        $byBucket = [];

        // The buckets come first so the chart and the list agree on the
        // order, and every bucket has a row even when nothing happened.
        foreach ($buckets as $bucket) {
            $byBucket[$bucket['key']] = [];
        }

        $index = 0;
        foreach ($counts as $reasonId => $perBucket) {
            $reason = $names->get($reasonId);
            $label = $reason?->reason ?: 'Reason #'.$reasonId;
            $key = 'r'.$reasonId;

            $list[] = [
                'key' => $key,
                'label' => $label,
                'color' => self::REASON_COLORS[$index % count(self::REASON_COLORS)],
            ];
            $index++;

            foreach ($byBucket as $bucketKey => $_) {
                $byBucket[$bucketKey][$key] = (int) ($perBucket[$bucketKey] ?? 0);
            }
        }

        return [
            'list' => $list,
            'byBucket' => array_values($byBucket),
        ];
    }

    /**
     * The order counts per status bucket.
     *
     * @return array<int, array{key: string, label: string, count: int, hue: string}>
     */
    protected function statusSegments(Carbon $from, Carbon $to): array
    {
        $counts = DB::table('orders')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $segments = [];
        foreach (self::STATUS_SEGMENTS as $segment) {
            $count = 0;
            foreach ($segment['statuses'] as $status) {
                $count += (int) ($counts[$status] ?? 0);
            }

            $segments[] = [
                'key' => $segment['key'],
                'label' => $segment['label'],
                'count' => $count,
                'hue' => $segment['hue'],
            ];
        }

        return $segments;
    }

    /**
     * The top governorates by revenue, with each one's share of the
     * period's revenue.
     *
     * @return array<int, array{name: string, share: float, revenue: float, orders: int}>
     */
    protected function governorates(Carbon $from, Carbon $to): array
    {
        $rows = DB::table('orders')
            ->whereBetween('created_at', [$from, $to])
            ->whereIn('status', self::SALE_STATUSES)
            ->selectRaw("COALESCE(NULLIF(governorate_name, ''), 'Unknown') as name, SUM(total) as revenue, COUNT(*) as orders")
            ->groupBy('name')
            ->orderByDesc('revenue')
            ->limit(8)
            ->get();

        $total = (float) $rows->sum('revenue');

        return $rows->map(fn ($row): array => [
            'name' => (string) $row->name,
            'share' => $total > 0 ? round(((float) $row->revenue / $total) * 100, 1) : 0.0,
            'revenue' => round((float) $row->revenue, 2),
            'orders' => (int) $row->orders,
        ])->all();
    }

    /**
     * The profit per category, with each one's share of the period's
     * profit.
     *
     * @return array<int, array{name: string, share: float, revenue: float, cog: float, profit: float}>
     */
    protected function categories(Carbon $from, Carbon $to): array
    {
        $rows = $this->productRows($from, $to)
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->selectRaw('categories.name as name')
            ->selectRaw('SUM(order_items.total_price) as revenue')
            ->selectRaw('SUM(order_items.quantity * COALESCE(variants.cost, 0)) as cog')
            ->groupBy('categories.id')
            ->orderByDesc(DB::raw('SUM(order_items.total_price) - SUM(order_items.quantity * COALESCE(variants.cost, 0))'))
            ->limit(8)
            ->get();

        $totalProfit = (float) $rows->sum(fn ($row): float => (float) $row->revenue - (float) $row->cog);

        return $rows->map(function ($row) use ($totalProfit): array {
            $profit = (float) $row->revenue - (float) $row->cog;

            return [
                'name' => (string) $row->name,
                'share' => $totalProfit > 0 ? round(($profit / $totalProfit) * 100, 1) : 0.0,
                'revenue' => round((float) $row->revenue, 2),
                'cog' => round((float) $row->cog, 2),
                'profit' => round($profit, 2),
            ];
        })->all();
    }

    /**
     * The top products by revenue, with their units, profit and how
     * much of them came back.
     *
     * @return array<int, array{id: int, name: string, cat: string, units: int, rev: float, profit: float, ret: float}>
     */
    protected function topProducts(Carbon $from, Carbon $to): array
    {
        $rows = $this->productRows($from, $to)
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->selectRaw('products.id as id, products.name as name')
            ->selectRaw("COALESCE(categories.name, 'Uncategorised') as cat")
            ->selectRaw('SUM(order_items.quantity) as units')
            ->selectRaw('SUM(order_items.total_price) as revenue')
            ->selectRaw('SUM(order_items.quantity * COALESCE(variants.cost, 0)) as cog')
            ->groupBy('products.id')
            ->orderByDesc('revenue')
            ->limit(20)
            ->get();

        $wasted = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereBetween('orders.created_at', [$from, $to])
            ->whereIn('orders.status', self::WASTE_STATUSES)
            ->selectRaw('order_items.product_id as product_id, SUM(order_items.quantity) as units')
            ->groupBy('order_items.product_id')
            ->pluck('units', 'product_id');

        return $rows->map(function ($row) use ($wasted): array {
            $units = (int) $row->units;
            $revenue = (float) $row->revenue;
            $cog = (float) $row->cog;
            $returned = (int) ($wasted[$row->id] ?? 0);

            // The rate is the share of everything that went out — the
            // units that were sold and the units that came back.
            $sent = $units + $returned;

            return [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'cat' => (string) $row->cat,
                'units' => $units,
                'rev' => round($revenue, 2),
                'profit' => round($revenue - $cog, 2),
                'ret' => $sent > 0 ? round(($returned / $sent) * 100, 1) : 0.0,
            ];
        })->all();
    }

    /**
     * The shared join behind the per-category and per-product reads.
     */
    protected function productRows(Carbon $from, Carbon $to): Builder
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->leftJoin('variants', 'variants.id', '=', 'order_items.variant_id')
            ->whereBetween('orders.created_at', [$from, $to])
            ->whereIn('orders.status', self::SALE_STATUSES);
    }

    /**
     * The reason behind most of the returns, and its share of them.
     *
     * @param  array<int, array<string, mixed>>  $list
     * @param  array<int, array<string, int>>  $byBucket
     * @return array{name: string, share: float}
     */
    protected function topReturnReason(array $list, array $byBucket): array
    {
        $totals = [];
        foreach ($list as $reason) {
            $count = 0;
            foreach ($byBucket as $row) {
                $count += (int) ($row[$reason['key']] ?? 0);
            }
            $totals[$reason['key']] = $count;
        }

        $sum = array_sum($totals);
        if ($sum === 0 || $totals === []) {
            return ['name' => '—', 'share' => 0.0];
        }

        arsort($totals);
        $topKey = array_key_first($totals);

        foreach ($list as $reason) {
            if ($reason['key'] === $topKey) {
                return [
                    'name' => $reason['label'],
                    'share' => round(((int) $totals[$topKey] / $sum) * 100, 1),
                ];
            }
        }

        return ['name' => '—', 'share' => 0.0];
    }

    /**
     * The range keys the screen accepts.
     */
    public function normalizeRange(?string $range): string
    {
        return in_array($range, self::RANGES, true) ? $range : self::DEFAULT_RANGE;
    }

    /**
     * The window the report covers.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function bounds(string $range, ?string $from = null, ?string $to = null): array
    {
        $now = now();

        if ($range === 'custom') {
            $start = $from ? Carbon::parse($from)->startOfDay() : $now->copy()->subDays(6)->startOfDay();
            $end = $to ? Carbon::parse($to)->endOfDay() : $now->copy()->endOfDay();

            if ($end->lessThan($start)) {
                [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
            }

            return [$start, $end];
        }

        if ($range === 'today') {
            return [$now->copy()->startOfDay(), $now->copy()];
        }

        if ($range === 'month') {
            return [$now->copy()->startOfMonth(), $now->copy()->endOfDay()];
        }

        $days = $range === '30d' ? 30 : 7;

        return [
            $now->copy()->subDays($days - 1)->startOfDay(),
            $now->copy()->endOfDay(),
        ];
    }

    /**
     * The window right before the current one.
     *
     * The rolling ranges shift by their own length — the seven days
     * before the current seven — and the month compares with the
     * previous month. A custom range compares with the window of the
     * same length that ends where this one starts.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function previousBounds(string $range, Carbon $from, Carbon $to): array
    {
        $now = now();

        if ($range === 'today') {
            return [
                $now->copy()->subDay()->startOfDay(),
                $now->copy()->subDay()->endOfDay(),
            ];
        }

        if ($range === 'month') {
            return [
                $now->copy()->subMonthNoOverflow()->startOfMonth(),
                $now->copy()->subMonthNoOverflow()->endOfMonth(),
            ];
        }

        if ($range === 'custom') {
            $length = abs($from->diffInSeconds($to));

            return [
                $from->copy()->subSeconds($length + 1),
                $from->copy()->subSecond(),
            ];
        }

        $days = $range === '30d' ? 30 : 7;

        return [
            $now->copy()->subDays(($days * 2) - 1)->startOfDay(),
            $now->copy()->subDays($days)->endOfDay(),
        ];
    }

    /**
     * One bucket per point of the chart, empty ones included.
     *
     * The `today` range buckets by hour so the chart shows the shape
     * of the trading day; every other range buckets by day.
     *
     * @return array<int, array{key: string, label: string}>
     */
    protected function buckets(Carbon $from, Carbon $to, string $range): array
    {
        $hourly = $range === 'today';

        $cursor = $from->copy()->startOf($hourly ? 'hour' : 'day');
        $limit = $hourly && now()->lessThan($to) ? now() : $to;

        $labelFormat = $hourly ? 'H' : 'j';
        $keyFormat = $hourly ? 'H' : 'Y-m-d';

        $buckets = [];
        while ($cursor->lessThanOrEqualTo($limit)) {
            $buckets[] = [
                'key' => $cursor->format($keyFormat),
                'label' => $cursor->format($labelFormat),
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
     * DATETIME on both drivers — `HOUR()` does not exist in SQLite.
     */
    protected function bucketExpression(string $range): string
    {
        return $range === 'today'
            ? 'SUBSTR(orders.created_at, 12, 2)'
            : 'DATE(orders.created_at)';
    }

    /**
     * The label the range is shown under.
     */
    protected function rangeLabel(string $range, Carbon $from, Carbon $to): string
    {
        return match ($range) {
            'today' => 'Today',
            '7d' => 'Last 7 days',
            '30d' => 'Last 30 days',
            'month' => 'This month',
            'custom' => $from->toDateString().' → '.$to->toDateString(),
            default => 'Last 7 days',
        };
    }
}

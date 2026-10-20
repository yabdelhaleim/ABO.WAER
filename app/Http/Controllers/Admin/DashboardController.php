<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin dashboard — overview KPIs, attention tiles, latest orders and
 * low-stock variants.
 *
 * The controller is intentionally thin: every number on the page is
 * produced by DashboardService so the Blade template stays free of
 * queries and the per-metric aggregate / fetch is unit-testable in
 * isolation. The four data blocks each have two corresponding
 * `$xxxEmpty` flags so the view can switch between the populated
 * layout and the empty-state copy without an `if` on the rows.
 *
 * `index` renders the page; `rangeData` is the JSON half of the
 * segmented control. Both hand the browser the same shapes, so
 * switching range never puts the page into a state the first paint
 * could not have produced.
 */
class DashboardController extends Controller
{
    public function __construct(protected DashboardService $dashboard) {}

    /**
     * Render the dashboard with metrics pulled from the database.
     *
     * `range` is read from the query string for the segmented control
     * at the top of the page (`/admin?range=30d`); anything the
     * service does not recognise falls back to the default range.
     */
    public function index(Request $request): View
    {
        $range = $this->dashboard->normalizeRange($request->query('range'));

        $attention = $this->dashboard->getAttentionMetrics();
        $today = $this->dashboard->getKpiMetrics($range);
        $latestOrders = $this->dashboard->getLatestOrders(5);
        $lowStock = $this->dashboard->getLowStockItems(4);
        $statusSegments = $this->dashboard->getOrdersByStatus();

        return view('admin.dashboard', [
            'range' => $range,
            'attention' => $attention,
            'today' => $today,
            'kpiCards' => $this->dashboard->getKpiPayload($range),
            'sparklines' => $this->dashboard->getSparklines($range),
            'salesProfit' => $this->dashboard->getSalesProfitChart($range),
            'statusSegments' => $statusSegments,
            'statusTotal' => array_sum(array_column($statusSegments, 'count')),
            'latestOrders' => $latestOrders,
            'lowStock' => $lowStock,
            'ordersEmpty' => $latestOrders->isEmpty(),
            'stockEmpty' => $lowStock->isEmpty(),
        ]);
    }

    /**
     * The JSON payload behind the Today / 7 days / 30 days control.
     *
     * The range arrives as a route segment (`/admin/dashboard/range/7d`)
     * rather than a query parameter so a caller cannot smuggle an
     * arbitrary value past the rule set; it is validated against the
     * service's own RANGES list, which is what the buttons render.
     *
     * Every key in the response is derived from one `getRangePayload()`
     * call, so the bars, the KPI cards and the sparklines are always
     * three views of the same numbers — a partial refresh can never
     * mix a 7-day chart with a 30-day sparkline.
     */
    public function rangeData(Request $request): JsonResponse
    {
        // `$request->validate()` reads query string and body only — a
        // path segment is invisible to it — so the route parameter is
        // merged in explicitly before validating. Without this the
        // rule would never see the value and every range would 422.
        $request->merge(['range' => $request->route('range')]);

        $validated = $request->validate(
            ['range' => ['required', Rule::in(DashboardService::RANGES)]],
            ['range.required' => 'The range field is required.'],
            ['range.in' => 'The selected range is invalid.'],
        );

        $range = (string) $validated['range'];

        return response()->json($this->dashboard->getRangePayload($range));
    }
}

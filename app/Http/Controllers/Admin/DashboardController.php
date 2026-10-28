<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\DashboardService;
use App\Services\CsvExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    /**
     * The dashboard as a CSV file — the numbers behind the EXPORT
     * REPORT button on the page header.
     *
     * The button used to sit there with nothing behind it: it was a
     * `<button>` with no handler, so clicking it did nothing at all.
     * It now downloads the same blocks the screen draws — the summary,
     * the sales-vs-profit series, the status split, the attention
     * strip and the low-stock list — for the range that is selected.
     *
     * @query string range today|7d|30d
     */
    public function export(Request $request): StreamedResponse
    {
        $validated = $request->validate(
            ['range' => ['nullable', Rule::in(DashboardService::RANGES)]],
            ['range.in' => 'The selected range is invalid.'],
        );

        $range = $this->dashboard->normalizeRange($validated['range'] ?? null);
        $sections = $this->dashboard->exportSections($range);

        $filename = 'abo-dashboard-'.now()->format('Y-m-d').'-'.$range.'.csv';

        return response()->streamDownload(function () use ($sections): void {
            $handle = fopen('php://output', 'wb');

            foreach ($sections as $index => $section) {
                if ($index > 0) {
                    fputcsv($handle, []);
                }

                fputcsv($handle, [$section['title']]);
                fputcsv($handle, $section['headers']);

                foreach ($section['rows'] as $row) {
                    fputcsv($handle, CsvExportService::row($row));
                }
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}

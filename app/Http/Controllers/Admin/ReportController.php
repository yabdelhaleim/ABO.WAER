<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\ReportService;
use App\Services\CsvExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin reports screen.
 *
 * The screen used to be a front-end preview: a static REPORTS_DATA
 * object in public/admin/js/reports.js held every number it drew.
 * Admin\ReportService measures the same report from the orders, the
 * order items and the catalogue, and this controller hands that
 * measurement to the browser.
 *
 * Three calls:
 *   GET  /admin/reports                    — the page, with the report
 *   GET  /admin/reports/range/{range}      — the same report for another
 *                                            range, as JSON
 *   GET  /admin/reports/export             — the report as a CSV
 */
class ReportController extends Controller
{
    public function __construct(protected ReportService $service) {}

    /**
     * Show the reports screen with the report and the route map
     * serialised for the browser.
     */
    public function index(Request $request): View
    {
        $filters = $this->filters($request);

        return view('admin.reports.index', [
            'payload' => $this->service->payload($filters),
            'routes' => [
                'range' => [
                    'url' => route('admin.reports.range', ['range' => '__RANGE__']),
                ],
                'export' => [
                    'url' => route('admin.reports.export'),
                ],
            ],
            'defaults' => [
                'from' => $filters['from'] ?? now()->subDays(6)->toDateString(),
                'to' => $filters['to'] ?? now()->toDateString(),
            ],
        ]);
    }

    /**
     * The report for another range.
     *
     * The toolbar switches the range without reloading the page: the
     * browser asks for the range, the server measures it and answers
     * the payload, and the screen is replaced by the answer.
     */
    public function range(Request $request, string $range): JsonResponse
    {
        $filters = $this->filters($request, $range);

        return response()->json([
            'success' => true,
            'report' => $this->service->payload($filters),
        ]);
    }

    /**
     * The report as a CSV file.
     *
     * The file is sectioned — a title, a header row and its rows — one
     * section per table of the report, so the spreadsheet reads like
     * the screen instead of one unreadable wide table.
     */
    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $sections = $this->service->exportSections($filters);

        $filename = 'abo-reports-'.now()->format('Y-m-d').'.csv';

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

    /**
     * The filters the screen sends.
     *
     * An unknown range falls back to the default one rather than
     * failing: a bookmark with a stale range still opens the screen.
     *
     * @return array{range: string, from: string|null, to: string|null}
     */
    protected function filters(Request $request, ?string $range = null): array
    {
        $key = $this->service->normalizeRange($range ?? $request->query('range'));

        $from = $request->query('from');
        $to = $request->query('to');

        return [
            'range' => $key,
            'from' => is_string($from) && $from !== '' ? $from : null,
            'to' => is_string($to) && $to !== '' ? $to : null,
        ];
    }
}

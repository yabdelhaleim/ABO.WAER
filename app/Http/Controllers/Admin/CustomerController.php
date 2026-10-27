<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\Admin\AdminActivityService;
use App\Services\Admin\CustomerService;
use App\Services\CsvExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The customers screen, and the two things an operator may change
 * about a customer from it.
 *
 * The screen itself is a read: the list, the figures above it and the
 * detail slide-over are all built from the database by
 * Admin\CustomerService.
 *
 * Two mutations are offered, and both are real:
 *
 *  - the tags and the internal note, which the back office keeps about
 *    the customer for its own use;
 *  - blocking, which is what the `is_blocked` column is for. A blocked
 *    account cannot sign in and cannot place an order — the two rules
 *    live in the store's sign-in and in OrderService, not here.
 */
class CustomerController extends Controller
{
    public function __construct(
        protected CustomerService $service,
        protected AdminActivityService $activity,
    ) {}

    /**
     * The customers list, read straight from the database.
     */
    public function index(): View
    {
        $payload = $this->service->payload();

        return view('admin.customers.index', [
            'customers' => $payload['customers'],
            'stats' => $payload['stats'],
            'governorates' => $payload['governorates'],
            'routes' => [
                'update' => route('admin.customers.update', ['customer' => '__CUSTOMER__']),
                'block' => route('admin.customers.block', ['customer' => '__CUSTOMER__']),
                'export' => route('admin.customers.export'),
                'orders' => route('admin.orders.index'),
            ],
        ]);
    }

    /**
     * Save the tags and the internal note.
     */
    public function update(Request $request, Customer $customer): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'tags' => ['nullable', 'array'],
            'tags.*' => ['nullable', 'string', 'max:50'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $tags = array_values(array_filter(
            array_map(fn ($tag) => trim((string) $tag), (array) ($validated['tags'] ?? [])),
            fn (string $tag): bool => $tag !== '',
        ));

        $customer->update([
            'tags' => $tags,
            'note' => $validated['note'] ?? null,
        ]);

        $this->activity->record('customer.updated', $customer, [
            'tags' => $tags,
        ]);

        // The saved values go back with the answer: the browser shows
        // what the server stored, not what it hoped it stored.
        return $this->success($request, 'Customer updated.', [
            'tags' => $tags,
            'note' => (string) ($customer->note ?? ''),
        ]);
    }

    /**
     * Block or unblock a customer.
     *
     * A reason is required when an account is blocked: the back office
     * has to be able to say why later, and an unexplained block is the
     * kind of thing that turns into a support ticket.
     */
    public function block(Request $request, Customer $customer): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'blocked' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $blocked = (bool) $validated['blocked'];
        $reason = $blocked ? trim((string) ($validated['reason'] ?? '')) : null;

        if ($blocked && ($reason === null || $reason === '')) {
            return $this->refuse(
                'reason',
                'Say why the account is being blocked.',
                $request,
            );
        }

        $customer->update([
            'is_blocked' => $blocked,
            'block_reason' => $reason,
        ]);

        $this->activity->record(
            $blocked ? 'customer.blocked' : 'customer.unblocked',
            $customer,
            $blocked ? ['reason' => $reason] : [],
        );

        return $this->success(
            $request,
            $blocked ? 'Customer blocked.' : 'Customer unblocked.',
            [
                'blocked' => (bool) $customer->is_blocked,
                'blockReason' => $customer->block_reason,
                // The segment may have moved: a blocked customer is
                // shown as blocked whatever else they are.
                'segment' => $this->service->segmentFor($customer),
            ],
        );
    }

    /**
     * Export the customer list as a CSV file.
     *
     * Every cell goes through CsvExportService first: a customer whose
     * name begins with "=" would otherwise be run as a formula by the
     * spreadsheet that opens the file.
     */
    public function export(): StreamedResponse
    {
        $payload = $this->service->payload();
        $customers = $payload['customers'];

        $filename = 'abo-customers-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($customers) {
            $handle = fopen('php://output', 'wb');

            fputcsv($handle, [
                'ID', 'Name', 'Email', 'Phone', 'Governorate',
                'Orders', 'Delivered orders', 'Total spent',
                'Segment', 'Status', 'Tags', 'Note', 'Signed up',
            ]);

            foreach ($customers as $customer) {
                fputcsv($handle, CsvExportService::row([
                    $customer['id'],
                    $customer['name'],
                    $customer['email'],
                    $customer['phone'],
                    $customer['governorate'],
                    $customer['ordersCount'],
                    $customer['deliveredCount'],
                    $customer['totalSpent'],
                    $customer['segment'],
                    $customer['blocked'] ? 'Blocked' : 'Active',
                    implode(', ', $customer['tags']),
                    $customer['note'],
                    $customer['signupDate'],
                ]));
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * The answer for a successful action.
     *
     * @param  array<string, mixed>  $data
     */
    protected function success(Request $request, string $message, array $data = []): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => $message,
                ...$data,
            ]);
        }

        return redirect()->route('admin.customers.index')->with('success', $message);
    }

    /**
     * The answer for a refused action.
     */
    protected function refuse(string $key, string $message, Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => $message,
                'errors' => [$key => [$message]],
            ], 422);
        }

        return back()->withErrors([$key => $message]);
    }
}

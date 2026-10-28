<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Governorate;
use App\Models\Order;
use App\Services\Admin\OrderService;
use App\Services\Admin\ReceiptService;
use App\Services\CsvExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin orders screen.
 *
 * index() is a full page render: the entire order list is serialised
 * into one JSON array and hydrated by public/admin/js/orders.js, which
 * does all its filtering, sorting and paging on the client. The three
 * mutations are the only server calls that file makes, and each one
 * answers {success, message} plus whatever the caller needs to reflect
 * the change locally.
 */
class OrderController extends Controller
{
    public function __construct(protected OrderService $service) {}

    /**
     * Show the orders screen with the order list and the governorate
     * filter options serialised for the browser.
     *
     * The screen can be narrowed to one customer with ?customer=<id> —
     * that is where the customers screen sends you when you ask to see
     * the orders of one account. The narrowing happens in the query,
     * so the page carries the orders of that customer and not the
     * whole book.
     */
    public function index(Request $request): View
    {
        $customerFilter = $this->customerFilter($request);

        $orders = $this->service->getOrdersPayload(
            $customerFilter === null ? null : (int) $customerFilter['id'],
        );

        return view('admin.orders.index', [
            'ordersJson' => json_encode($orders, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ordersCount' => count($orders),
            'governorates' => Governorate::query()
                ->orderBy('name')
                ->pluck('name'),
            'customerFilter' => $customerFilter,
            'routes' => [
                'status' => [
                    'url' => route('admin.orders.status', ['order' => '__ORDER__']),
                    'method' => 'PATCH',
                ],
                'cancel' => [
                    'url' => route('admin.orders.cancel', ['order' => '__ORDER__']),
                    'method' => 'POST',
                ],
                'tracking' => [
                    'url' => route('admin.orders.tracking', ['order' => '__ORDER__']),
                    'method' => 'PATCH',
                ],
                'export' => [
                    'url' => route('admin.orders.export'),
                ],
                // The two printable documents. The invoice is one order,
                // so its URL carries the `{order}` placeholder the rest
                // of the routes use; the labels sheet takes the whole
                // selection at once, so its URL takes a query string.
                'invoice' => [
                    'url' => route('admin.orders.invoice', ['order' => '__ORDER__']),
                ],
                'labels' => [
                    'url' => route('admin.orders.labels'),
                ],
            ],
        ]);
    }

    /**
     * The customer the screen is narrowed to, if any.
     *
     * An id that belongs to no customer is ignored rather than
     * refused: a stale link should show the whole list, not an error.
     *
     * @return array<string, string>|null
     */
    protected function customerFilter(Request $request): ?array
    {
        $id = $request->query('customer');

        if (! is_numeric($id) || (int) $id <= 0) {
            return null;
        }

        $customer = Customer::query()->find((int) $id);

        if ($customer === null) {
            return null;
        }

        return [
            'id' => (string) $customer->id,
            'name' => (string) $customer->name,
        ];
    }

    /**
     * Apply a status chosen in the drawer or the bulk bar.
     *
     * Cancelling through the status dropdown goes through cancelOrder so
     * the sold units still come back off the variants — otherwise the
     * two ways of cancelling an order would leave stock in different
     * states.
     */
    public function updateStatus(Request $request, Order $order): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in($this->service->availableStatusKeys())],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        $status = $this->service->resolveStatus($validated['status']);

        if ($status === null) {
            return response()->json(['success' => false, 'message' => 'Invalid status.'], 422);
        }

        if ($status === OrderStatus::Cancelled) {
            $this->service->cancelOrder($order, null, $validated['comment'] ?? null, auth('admin')->id());
        } else {
            $this->service->updateOrderStatus(
                $order,
                $status,
                $validated['comment'] ?? null,
                auth('admin')->id(),
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Order status updated successfully.',
            'status' => $this->service->statusKey($order->fresh()->status),
        ]);
    }

    /**
     * Cancel an order, recording the reason and returning the sold
     * quantity to its variant.
     */
    public function cancel(Request $request, Order $order): JsonResponse
    {
        $validated = $request->validate([
            'cancellation_reason_id' => ['nullable', 'integer', 'exists:cancellation_reasons,id'],
            'cancellation_note' => ['nullable', 'string', 'max:500'],
        ]);

        $alreadyCancelled = $order->status === OrderStatus::Cancelled;

        $this->service->cancelOrder(
            $order,
            $validated['cancellation_reason_id'] ?? null,
            $validated['cancellation_note'] ?? null,
            auth('admin')->id(),
        );

        return response()->json([
            'success' => true,
            'message' => $alreadyCancelled
                ? 'Order was already cancelled.'
                : 'Order cancelled and stock returned successfully.',
        ]);
    }

    /**
     * Save the courier's waybill number.
     */
    public function updateTracking(Request $request, Order $order): JsonResponse
    {
        $validated = $request->validate([
            'tracking_number' => ['nullable', 'string', 'max:100'],
        ]);

        $this->service->updateTracking($order, $validated['tracking_number'] ?? null);

        return response()->json([
            'success' => true,
            'message' => 'Tracking number updated.',
            'tracking_number' => $order->fresh()->tracking_number,
        ]);
    }

    /**
     * The printable invoice for one order.
     *
     * The drawer used to answer "Invoice ready (demo)" — a toast and no
     * document. This is the document: the order's own rows and the
     * receipt settings the store saved, laid out for the paper the
     * settings ask for.
     *
     * The page is deliberately outside the admin chrome. A receipt is
     * handed to a customer; it must not carry the back office's
     * sidebar, its bell or its signed-in admin's name.
     */
    public function invoice(Order $order, ReceiptService $receipts): View
    {
        return view('admin.orders.print.invoice', [
            'receipt' => $receipts->invoice($order),
        ]);
    }

    /**
     * The printable shipping labels for a batch of orders.
     *
     * The bulk bar used to answer "Labels ready (demo)" — a toast and no
     * sheet. This is the sheet: one label per selected order, each one
     * measured from the order's own rows.
     *
     * The orders travel as a comma-separated list of order numbers in
     * the query string, because that is how the browser addresses them
     * (public/admin/js/orders.js keys its rows by order_number). A
     * number that belongs to no order is skipped rather than refused:
     * one stale id in a selection should not cost the operator the
     * whole sheet.
     */
    public function labels(Request $request, ReceiptService $receipts): View
    {
        $numbers = $this->requestedOrderNumbers($request);

        $orders = $numbers === []
            ? collect()
            : Order::query()
                ->whereIn('order_number', $numbers)
                ->with('items')
                ->get()
                ->sortBy(fn (Order $order): int => array_search($order->order_number, $numbers, true))
                ->values();

        return view('admin.orders.print.labels', [
            'labels' => $receipts->labels($orders),
            'layout' => $receipts->layout(),
            'store' => $receipts->store(),
        ]);
    }

    /**
     * The order numbers the labels request asked for.
     *
     * @return array<int, string>
     */
    protected function requestedOrderNumbers(Request $request): array
    {
        $raw = (string) $request->query('orders', '');

        if (trim($raw) === '') {
            return [];
        }

        $numbers = array_values(array_filter(array_map(
            fn (string $number): string => trim($number),
            explode(',', $raw),
        )));

        return array_values(array_unique($numbers));
    }

    /**
     * Export the orders as a CSV file.
     *
     * The browser sends the filters the screen is showing, so the file
     * holds the rows the admin is looking at rather than the whole
     * book. The rows are read from the database instead of from the
     * copy the page carries: that copy holds only the orders the page
     * was opened with, and a filter that narrows the list would
     * otherwise have nothing to narrow.
     *
     * Every cell goes through CsvExportService first — a customer
     * whose name begins with "=" would otherwise be run as a formula
     * by the spreadsheet that opens the file.
     */
    public function export(Request $request): StreamedResponse
    {
        $orders = $this->service->exportOrders($this->exportFilters($request));

        $filename = 'abo-orders-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($orders): void {
            $handle = fopen('php://output', 'wb');

            fputcsv($handle, [
                'Order number', 'Date', 'Status', 'Customer', 'Phone', 'Email',
                'Governorate', 'Area', 'Address', 'Items', 'Items count',
                'Subtotal', 'Shipping', 'Discount', 'Coupon', 'Total',
                'Payment method', 'Payment status', 'Tracking number',
            ]);

            foreach ($orders as $order) {
                fputcsv($handle, CsvExportService::row([
                    $order->order_number,
                    $order->created_at?->format('Y-m-d H:i'),
                    $this->service->statusLabel($order->status),
                    $order->customer_name,
                    $order->customer_phone,
                    $order->customer_email,
                    $order->governorate_name,
                    $order->area,
                    $order->address,
                    $this->itemsSummary($order),
                    (int) $order->items->sum('quantity'),
                    $order->subtotal,
                    $order->shipping_fee,
                    $order->discount_amount,
                    $order->coupon_code,
                    $order->total,
                    $order->payment_method,
                    $order->payment_status?->value,
                    $order->tracking_number,
                ]));
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * The filters the export should apply.
     *
     * These are the filters the screen shows, sent back by the browser
     * as query parameters. Anything missing or unknown means "no
     * filter" — an export of the whole book is a better answer than a
     * refused download.
     *
     * @return array<string, mixed>
     */
    protected function exportFilters(Request $request): array
    {
        $bucket = (string) $request->query('status', '');

        $payment = (string) $request->query('payment', '');
        $governorate = (string) $request->query('governorate', '');
        $search = (string) $request->query('q', '');
        $customerId = $request->query('customer');

        return [
            'statuses' => $this->service->statusesForBucket($bucket),
            'search' => $search,
            'payment' => $payment,
            'governorate' => $governorate,
            'customer_id' => is_numeric($customerId) ? (int) $customerId : null,
            'date_from' => $this->dateFilter($request->query('from')),
            'date_to' => $this->dateFilter($request->query('to')),
            'sort' => $request->query('sort') === 'total' ? 'total' : 'date',
            'direction' => $request->query('dir') === 'asc' ? 'asc' : 'desc',
        ];
    }

    /**
     * A date filter, or null when it is absent or malformed.
     */
    protected function dateFilter(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $value;
    }

    /**
     * The order's line items as one cell: "Hoodie (Black / M) x2, Cap x1".
     */
    protected function itemsSummary(Order $order): string
    {
        $parts = [];

        foreach ($order->items as $item) {
            $label = (string) $item->product_name;

            $variant = array_values(array_filter([
                $item->color_name,
                $item->size_name,
            ]));

            if ($variant !== []) {
                $label .= ' ('.implode(' / ', $variant).')';
            }

            $parts[] = $label.' x'.(int) $item->quantity;
        }

        return implode(', ', $parts);
    }
}

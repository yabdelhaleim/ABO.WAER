<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Governorate;
use App\Models\Order;
use App\Services\Admin\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

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
}

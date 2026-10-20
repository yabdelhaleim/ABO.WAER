<?php

namespace App\Http\Controllers\Store;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Store\CancelOrderRequest;
use App\Models\Customer;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The customer-facing "My Orders" surface: the page shell, the JSON the
 * page hydrates itself from, and customer-initiated cancellation.
 *
 * All three actions sit behind the `auth` + `not_blocked` middleware group
 * in routes/web.php, so a customer is always signed in and never blocked.
 * Ownership of an individual order is re-checked here rather than trusted
 * from the route — see assertOwnedBy.
 */
class OrderController extends Controller
{
    public function __construct(protected OrderService $orders) {}

    /**
     * Render the orders page shell.
     *
     * The view paints no order data itself — resources/js/orders.js fetches
     * `orders.data` on load and renders every card in the browser — so this
     * action only resolves the announcement bar, which stays on the static
     * editorial copy in views/store/data/home.php like the other store pages.
     */
    public function index(): View
    {
        $data = require resource_path('views/store/data/home.php');

        return view('my-orders', $data);
    }

    /**
     * The signed-in customer's orders, shaped for the stepper.
     *
     * Returns only rows owned by the caller, newest first.
     */
    public function list(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'orders' => $this->orders->ordersForCustomer($this->customer($request)),
        ]);
    }

    /**
     * Cancel one of the caller's own orders.
     *
     * Stock goes back to inventory inside the service's transaction. An
     * order that has moved past Pending is refused with 422 — the caller
     * needs the reason, not a 404, because they legitimately own it and
     * the store needs to tell them to contact support instead.
     */
    public function cancel(CancelOrderRequest $request, Order $order): JsonResponse
    {
        $customer = $this->customer($request);

        $this->assertOwnedBy($customer, $order);

        if ($order->status !== OrderStatus::Pending) {
            return response()->json([
                'success' => false,
                'message' => 'This order can no longer be cancelled. Please contact support.',
            ], 422);
        }

        $this->orders->cancelOrderForCustomer($order, $customer, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Order cancelled successfully and stock returned.',
        ]);
    }

    /**
     * Refuse to act on somebody else's order.
     *
     * 404 rather than 403: a 403 would confirm that the order exists, which
     * is itself information the caller is not entitled to.
     */
    protected function assertOwnedBy(Customer $customer, Order $order): void
    {
        if ($order->customer_id !== $customer->id) {
            abort(404);
        }
    }

    /**
     * The signed-in customer. Guaranteed non-null by the `auth` middleware
     * on the route group.
     */
    protected function customer(Request $request): Customer
    {
        /** @var Customer $customer */
        $customer = $request->user();

        return $customer;
    }
}

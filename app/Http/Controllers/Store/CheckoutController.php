<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Store\CheckoutRequest;
use App\Models\Governorate;
use App\Models\Order;
use App\Services\CartService;
use App\Services\OrderService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Server side of the checkout funnel.
 *
 * `show` renders the same Blade view the closure route used to render
 * — the cart chrome, the form, the JS hooks — but the governorate
 * dropdown is fed from the database (and only from the database: the
 * page used to fall back to a shipped list of names when the table was
 * empty).
 *
 * `process` is the new POST target. It accepts either a regular form
 * submission (redirects to the success page) or an AJAX JSON request
 * (returns a JSON envelope the existing checkout.js understands).
 *
 * `success` is the real order-success page: it looks the order up by
 * its generated order_number so the URL is a stable identifier.
 */
class CheckoutController extends Controller
{
    public function __construct(
        protected OrderService $orderService,
        protected CartService $cart,
    ) {}

    /**
     * Render the checkout shell.
     *
     * The view still extends the shared home.php data file for its
     * editorial chrome (hero, ticker, footer) — everything the form
     * actually operates on comes from the database: the governorate
     * <select> options and the product catalog the page prices against.
     */
    public function show(Request $request): View
    {
        $data = require resource_path('views/store/data/home.php');

        // The governorates the admin published — and only those. The
        // page used to fall back to a shipped list of 27 names when the
        // table was empty, which meant a shopper could pick a
        // governorate the store does not deliver to: the dropdown and
        // the shipping zones disagreed, and the fee that came back was
        // the global default rather than the zone's own.
        //
        // An empty table now means an empty dropdown and a page that
        // says the store is not taking orders yet — which is the truth.
        $data['governorates'] = Governorate::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name')
            ->all();

        // The catalog the checkout prices and validates against is the
        // live one, exactly like the cart page — NOT the editorial
        // product array that home.php carries for the home page's
        // fallback grid. Publishing the static array here meant every
        // real product slug failed the client-side check and the
        // customer was bounced back to /cart before they could order.
        $data['products'] = $this->cart->getStorefrontCatalog();

        return view('checkout', $data);
    }

    /**
     * Process a checkout submission.
     *
     * Honors the same response shapes checkout.js already knows how to
     * handle: AJAX callers get JSON with the order number and a
     * redirect URL, plain form posts are redirected to the success page.
     */
    public function process(CheckoutRequest $request): JsonResponse|RedirectResponse
    {
        $order = $this->orderService->placeOrder(
            customer: $request->user(),
            sessionId: $request->session()->getId(),
            checkoutData: $request->validated(),
        );

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Order placed successfully.',
                'order_number' => $order->order_number,
                'redirect_url' => route('checkout.success', $order->order_number),
            ], 201);
        }

        return redirect()->route('checkout.success', $order->order_number);
    }

    /**
     * The success page for a placed order.
     *
     * The view receives the real Order model so the JS payload the
     * page already publishes (order_number, payment method key, total,
     * items) can be sourced from the database instead of sessionStorage.
     */
    public function success(string $orderNumber): View
    {
        $order = Order::query()
            ->where('order_number', $orderNumber)
            ->with('items')
            ->firstOrFail();

        $data = require resource_path('views/store/data/home.php');

        $data['order'] = $order->order_number;
        $data['orderModel'] = $order;
        $data['paymentMethods'] = [
            'cod' => ['key' => 'cod', 'label' => 'Cash on delivery'],
        ];

        return view('checkout.success', $data);
    }
}

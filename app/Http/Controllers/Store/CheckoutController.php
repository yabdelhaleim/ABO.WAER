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
 * dropdown is now fed from the database (with the static list as a
 * fallback so the page still renders with zero DB rows).
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
     * editorial chrome (announcement, hero, footer) — only the
     * governorate <select> options are sourced from the database now.
     */
    public function show(Request $request): View
    {
        $data = require resource_path('views/store/data/home.php');

        // The summary is priced against the live catalog, exactly like
        // the cart page. The static home.php product list is a
        // different set, so checkout.js would reject every line as
        // "a product that no longer exists" and bounce the shopper
        // back to /cart.
        $data['products'] = $this->cart->getStorefrontCatalog();

        $governorates = Governorate::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name')
            ->all();

        // Keep the static 27-name list as a Blade-level fallback so
        // the page still renders before the admin has populated the
        // governorates table.
        $data['governorates'] = ! empty($governorates)
            ? $governorates
            : ($data['governorates'] ?? []);

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
                'order' => $this->presentOrder($order),
            ], 201);
        }

        return redirect()->route('checkout.success', $order->order_number);
    }

    /**
     * The order as the success page needs it.
     *
     * The browser keeps a copy of this payload so the page can render
     * without a second request. Every figure here comes from the
     * database, so the copy and the stored order can never disagree.
     *
     * @return array<string, mixed>
     */
    protected function presentOrder(Order $order): array
    {
        // The item image is read through the product → productColors →
        // images chain, so all three have to be eager-loaded.
        $order->loadMissing('items.product.productColors.images');

        return [
            'number' => $order->order_number,
            'subtotal' => (float) $order->subtotal,
            'total' => (float) $order->total,
            'shipping' => (float) $order->shipping_fee,
            'discount' => (float) $order->discount_amount,
            'paymentMethod' => (string) $order->payment_method,
            'placedAt' => $order->created_at?->getTimestamp() ?? time(),
            'items' => $order->items->map(fn ($item): array => [
                'name' => $item->product_name,
                'image' => $item->product?->primary_image?->path,
                'color' => $item->color_name,
                'size' => $item->size_name,
                'qty' => (int) $item->quantity,
                'price' => (float) $item->unit_price,
                'line_total' => (float) $item->total_price,
            ])->all(),
        ];
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
        $data['orderPayload'] = $this->presentOrder($order);
        $data['paymentMethods'] = [
            'cod' => ['key' => 'cod', 'label' => 'Cash on delivery'],
        ];

        return view('checkout.success', $data);
    }
}

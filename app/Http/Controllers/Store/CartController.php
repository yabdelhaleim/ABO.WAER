<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\Customer;
use App\Services\CartService;
use App\Services\Store\StorefrontService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class CartController extends Controller
{
    public function __construct(
        protected CartService $cart,
        protected StorefrontService $storefront,
    ) {}

    /**
     * The cart page.
     *
     * The page shell and its editorial copy come from the shared
     * home.php data file, so the surrounding chrome renders exactly as
     * before. Only the product list is swapped for the live catalog:
     * cart.js prices every line off the JSON payload this view
     * publishes, and that payload is now built from products, variants
     * and colors in the database instead of a static array.
     */
    public function index(): View
    {
        $data = require resource_path('views/store/data/home.php');

        $data['products'] = $this->cart->getStorefrontCatalog();

        // The shop's minimum order. The checkout refuses an order below
        // it, so the cart says so while the shopper can still act on
        // it.
        $data['minimumOrder'] = $this->storefront->minimumOrder();

        return view('cart', $data);
    }

    /**
     * The whole cart: every line plus subtotal and free-shipping standing.
     */
    public function summary(Request $request): JsonResponse
    {
        return response()->json($this->cart->getSummary($this->customer($request), $this->sessionId($request)));
    }

    /**
     * Add a variant. Works for guests and signed-in customers alike.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'variant_id' => ['required', 'integer', 'exists:variants,id'],
            'quantity' => ['nullable', 'integer', 'min:1'],
        ]);

        $item = $this->cart->addItem(
            $this->customer($request),
            $this->sessionId($request),
            $validated,
        );

        return response()->json($this->payload($request, $item), 201);
    }

    /**
     * Set an exact quantity on a line.
     */
    public function update(Request $request, CartItem $cartItem): JsonResponse
    {
        $this->cart->assertOwned($cartItem, $this->customer($request), $this->sessionId($request));

        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $item = $this->cart->updateItem($cartItem, (int) $validated['quantity']);

        return response()->json($this->payload($request, $item->fresh()));
    }

    /**
     * Remove a single line.
     */
    public function destroy(Request $request, CartItem $cartItem): Response
    {
        $this->cart->assertOwned($cartItem, $this->customer($request), $this->sessionId($request));

        $this->cart->removeItem($cartItem);

        return response()->noContent();
    }

    /**
     * Adopt the cart held in the visitor's browser onto their account.
     *
     * Sync only makes sense against an identity — a guest has no account to
     * sync onto — so this endpoint is the one that requires authentication.
     */
    public function merge(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        if (! $customer) {
            return response()->json(['success' => false], 401);
        }

        $validated = $request->validate([
            'items' => ['present', 'array'],
            'items.*.slug' => ['required', 'string'],
            'items.*.color' => ['nullable', 'string'],
            'items.*.qty' => ['nullable', 'integer'],
        ]);

        $result = $this->cart->mergeClientCart($customer, $validated['items']);

        return response()->json([
            'success' => true,
            ...$result,
            'summary' => $this->cart->getSummary($customer, $this->sessionId($request)),
        ]);
    }

    /**
     * A single stored line plus the refreshed cart totals.
     *
     * @return array<string, mixed>
     */
    protected function payload(Request $request, ?CartItem $item): array
    {
        return [
            'success' => true,
            'item_id' => $item?->id,
            'summary' => $this->cart->getSummary($this->customer($request), $this->sessionId($request)),
        ];
    }

    /**
     * The signed-in customer, or null for a guest.
     */
    protected function customer(Request $request): ?Customer
    {
        return $request->user();
    }

    /**
     * The id used to key a guest cart.
     */
    protected function sessionId(Request $request): string
    {
        return $request->session()->getId();
    }
}

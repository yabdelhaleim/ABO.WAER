<?php

namespace App\Services;

use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ShippingSetting;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cart arithmetic and persistence.
 *
 * Ownership model: every line belongs either to a signed-in customer
 * (customer_id) or to a visitor's session (session_id). Exactly one is set.
 * A visitor's lines are folded into the customer's cart on login.
 *
 * Prices are never trusted from the client. Every figure here is derived
 * from the variant's own record, so a tampered payload cannot change what
 * a line costs.
 */
class CartService
{
    /**
     * Hard ceiling on a single cart line. This mirrors MAX_QTY_PER_LINE in
     * resources/js/cart.js — the browser clamps at the same value, and the
     * comment at the top of that file requires a server-side cap to match.
     */
    public const MAX_QUANTITY_PER_LINE = 10;

    /**
     * The live catalog in the shape the cart page hands to cart.js.
     *
     * Only products a shopper can actually act on: not archived, and with at
     * least one variant. The price is the cheapest in-stock variant so the
     * figure on screen matches what the shopper can buy today; a fully
     * out-of-stock product still renders, priced at its cheapest variant, and
     * is rejected later by the stock check when it is added.
     *
     * Every product also carries its own `variants` list, so the browser can
     * address the exact colour × size row a shopper picked instead of
     * guessing from the product level. Each entry names the variant id, the
     * colour hex and its name, the size, that variant's own effective price
     * and the stock behind it. The variant price is that variant's own
     * final_price — never the product's cheapest — so the figure quoted for
     * a combination is the one that combination is sold at.
     *
     * @return array<int, array{slug: string, name: string, price: float, image: ?string, category: ?string, colors: array<int, string>, variants: array<int, array{id: int, color: ?string, color_name: ?string, size: ?string, price: float, stock: int}>}>
     */
    public function getStorefrontCatalog(): array
    {
        return Product::query()
            ->active()
            ->whereHas('variants')
            ->with([
                'category',
                'colors',
                'variants.color',
                'variants.size',
                'productColors.images',
            ])
            ->orderBy('id')
            ->get()
            ->map(fn (Product $product): array => [
                'slug' => $product->slug,
                'name' => $product->name,
                'price' => $this->catalogPrice($product),
                'image' => $product->primary_image?->url,
                'category' => $product->category?->slug,
                'colors' => $product->colors->pluck('hex_code')->values()->all(),
                'variants' => $product->variants
                    ->sortBy('id')
                    ->values()
                    ->map(fn (Variant $variant): array => $this->presentVariant($variant))
                    ->all(),
            ])
            ->all();
    }

    /**
     * Add a variant to the cart, folding it into an existing line for the
     * same variant instead of creating a duplicate row.
     *
     * @param  array{variant_id: int, quantity?: int}  $data
     *
     * @throws ValidationException
     */
    public function addItem(?Customer $customer, string $sessionId, array $data): CartItem
    {
        $variant = $this->findPurchasableVariant((int) ($data['variant_id'] ?? 0));
        $quantity = $this->assertOrderable($variant, (int) ($data['quantity'] ?? 1));

        return DB::transaction(function () use ($customer, $sessionId, $variant, $quantity): CartItem {
            $existing = $this->ownedItemsQuery($customer, $sessionId)
                ->where('variant_id', $variant->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $existing->update([
                    'quantity' => $this->clampQuantity($variant, $existing->quantity + $quantity),
                ]);

                return $existing;
            }

            return CartItem::create([
                'customer_id' => $customer?->id,
                'session_id' => $customer ? null : $sessionId,
                'product_id' => $variant->product_id,
                'variant_id' => $variant->id,
                'quantity' => $quantity,
            ]);
        });
    }

    /**
     * Set an exact quantity on a line, rejecting anything the stock cannot cover.
     *
     * @throws ValidationException
     */
    public function updateItem(CartItem $item, int $quantity): CartItem
    {
        $variant = $this->findPurchasableVariant($item->variant_id);

        $item->update(['quantity' => $this->assertOrderable($variant, $quantity)]);

        return $item;
    }

    /**
     * Drop a single line from the cart.
     */
    public function removeItem(CartItem $item): void
    {
        $item->delete();
    }

    /**
     * Empty the cart entirely.
     *
     * Called at the end of a successful checkout so the shopper doesn't
     * see the same lines on the cart page after the order has been
     * placed. The lines are deleted through ownedItemsQuery so a guest's
     * session lines and a customer's account lines are both reachable.
     */
    public function clearCart(?Customer $customer, string $sessionId): void
    {
        $this->ownedItemsQuery($customer, $sessionId)->delete();
    }

    /**
     * The full cart state: every line plus the money figures derived from them.
     *
     * The free-shipping comparison lives here and nowhere else — the cart
     * page itself must not surface a progress bar or "free shipping" copy.
     *
     * @return array{items: array<int, array<string, mixed>>, item_count: int, subtotal: float, free_shipping_enabled: bool, free_shipping_threshold: float, qualifies_for_free_shipping: bool, remaining_for_free_shipping: float}
     */
    public function getSummary(?Customer $customer, string $sessionId): array
    {
        $items = $this->ownedItemsQuery($customer, $sessionId)
            ->with(['product.productColors.images', 'variant', 'variant.color', 'variant.size'])
            ->get();

        $subtotal = (float) $items->sum(fn (CartItem $item) => $item->line_total);

        $threshold = (float) ShippingSetting::get('free_shipping_threshold', 0);
        $enabled = (bool) ShippingSetting::get('free_shipping_enabled', false);
        $qualifies = $enabled && $threshold > 0 && $subtotal >= $threshold;

        return [
            'items' => $items->map(fn (CartItem $item) => $this->presentItem($item))->all(),
            'item_count' => (int) $items->sum('quantity'),
            'subtotal' => $subtotal,
            'free_shipping_enabled' => $enabled,
            'free_shipping_threshold' => $threshold,
            'qualifies_for_free_shipping' => $qualifies,
            'remaining_for_free_shipping' => max(0.0, $threshold - $subtotal),
        ];
    }

    /**
     * Fold a visitor's session cart into their account at login.
     *
     * Quantities for the same variant are summed, then clamped to whatever
     * stock is left, so a customer who filled a cart as a guest and again as
     * a member ends up with the largest quantity that can actually ship.
     */
    public function mergeGuestCart(Customer $customer, string $sessionId): void
    {
        DB::transaction(function () use ($customer, $sessionId): void {
            $guestItems = CartItem::query()
                ->whereNull('customer_id')
                ->where('session_id', $sessionId)
                ->with('variant')
                ->get();

            foreach ($guestItems as $guestItem) {
                $variant = $guestItem->variant;

                if (! $variant || $variant->quantity < 1) {
                    $guestItem->delete();

                    continue;
                }

                $owned = $this->ownedItemsQuery($customer, $sessionId)
                    ->where('variant_id', $variant->id)
                    ->lockForUpdate()
                    ->first();

                if ($owned) {
                    $owned->update([
                        'quantity' => $this->clampQuantity($variant, $owned->quantity + $guestItem->quantity),
                    ]);
                    $guestItem->delete();

                    continue;
                }

                $guestItem->update([
                    'customer_id' => $customer->id,
                    'session_id' => null,
                    'quantity' => $this->clampQuantity($variant, $guestItem->quantity),
                ]);
            }
        });
    }

    /**
     * Sync a browser-held cart (the localStorage payload cart.js maintains)
     * onto the customer's account.
     *
     * Lines are resolved by product slug plus colour, since that is the
     * identity the browser stores. Anything that no longer maps to a live
     * variant, or that exceeds remaining stock, is reported as skipped
     * rather than silently persisted.
     *
     * @param  array<int, array{slug: string, color?: ?string, qty?: int}>  $lines
     * @return array{merged: int, skipped: int}
     */
    public function mergeClientCart(Customer $customer, array $lines): array
    {
        $merged = 0;
        $skipped = 0;

        DB::transaction(function () use ($customer, $lines, &$merged, &$skipped): void {
            foreach ($lines as $line) {
                if (! is_array($line) || blank($line['slug'] ?? null)) {
                    $skipped++;

                    continue;
                }

                $variant = $this->resolveVariantForLine((string) $line['slug'], $line['color'] ?? null);

                if (! $variant || $variant->quantity < 1) {
                    $skipped++;

                    continue;
                }

                $requested = max(1, (int) ($line['qty'] ?? 1));
                $quantity = $this->clampQuantity($variant, $requested);

                if ($quantity < 1) {
                    $skipped++;

                    continue;
                }

                $existing = $this->ownedItemsQuery($customer, '')
                    ->where('variant_id', $variant->id)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    $existing->update([
                        'quantity' => $this->clampQuantity($variant, $existing->quantity + $quantity),
                    ]);
                } else {
                    CartItem::create([
                        'customer_id' => $customer->id,
                        'session_id' => null,
                        'product_id' => $variant->product_id,
                        'variant_id' => $variant->id,
                        'quantity' => $quantity,
                    ]);
                }

                $merged++;
            }
        });

        return ['merged' => $merged, 'skipped' => $skipped];
    }

    /**
     * Verify a line belongs to the caller, so one visitor cannot mutate
     * another visitor's cart by guessing an id.
     *
     * @throws ModelNotFoundException
     */
    public function assertOwned(CartItem $item, ?Customer $customer, string $sessionId): CartItem
    {
        $owned = $this->ownedItemsQuery($customer, $sessionId)->whereKey($item->id)->exists();

        if (! $owned) {
            throw (new ModelNotFoundException)->setModel(CartItem::class, [$item->id]);
        }

        return $item;
    }

    /**
     * Restrict a cart-item query to the caller's own lines.
     *
     * @return Builder<CartItem>
     */
    private function ownedItemsQuery(?Customer $customer, string $sessionId): Builder
    {
        return CartItem::query()
            ->when(
                $customer,
                fn (Builder $query) => $query->where('customer_id', $customer->id),
                fn (Builder $query) => $query->whereNull('customer_id')->where('session_id', $sessionId),
            );
    }

    /**
     * The cheapest price a shopper can act on: the lowest in-stock variant,
     * falling back to the lowest variant of any kind when nothing is stocked.
     */
    private function catalogPrice(Product $product): float
    {
        $inStock = $product->variants
            ->filter(fn (Variant $variant) => $variant->quantity > 0)
            ->map(fn (Variant $variant) => (float) $variant->final_price);

        return (float) ($inStock->min() ?? $product->variants->min(fn (Variant $variant) => (float) $variant->final_price) ?? 0);
    }

    /**
     * Load a variant that a shopper is still allowed to buy.
     *
     * @throws ValidationException
     */
    private function findPurchasableVariant(int $variantId): Variant
    {
        $variant = Variant::query()
            ->with(['product', 'color', 'size'])
            ->whereHas('product', fn (Builder $query) => $query->where('is_archived', false))
            ->find($variantId);

        if (! $variant) {
            throw ValidationException::withMessages([
                'variant_id' => 'This product is no longer available.',
            ]);
        }

        return $variant;
    }

    /**
     * Validate a requested quantity against the per-line cap and real stock.
     *
     * @throws ValidationException
     */
    private function assertOrderable(Variant $variant, int $quantity): int
    {
        if ($quantity < 1) {
            throw ValidationException::withMessages([
                'quantity' => 'Quantity must be at least 1.',
            ]);
        }

        if ($quantity > self::MAX_QUANTITY_PER_LINE) {
            throw ValidationException::withMessages([
                'quantity' => 'You can order at most '.self::MAX_QUANTITY_PER_LINE.' units of this item.',
            ]);
        }

        if ($variant->quantity < 1) {
            throw ValidationException::withMessages([
                'variant_id' => 'This option is out of stock.',
            ]);
        }

        if ($quantity > $variant->quantity) {
            throw ValidationException::withMessages([
                'quantity' => "Only {$variant->quantity} units left in stock.",
            ]);
        }

        return $quantity;
    }

    /**
     * Cap a quantity to what stock allows, used when merging carts where the
     * shopper is not at fault for the number they ended up with.
     */
    private function clampQuantity(Variant $variant, int $desired): int
    {
        $ceiling = min(self::MAX_QUANTITY_PER_LINE, $variant->quantity);

        return max(1, min($desired, $ceiling));
    }

    /**
     * Map a browser cart line onto a real variant: exact colour match first,
     * otherwise the cheapest variant that is actually purchasable.
     */
    private function resolveVariantForLine(string $slug, ?string $color): ?Variant
    {
        $candidates = Variant::query()
            ->whereHas('product', fn (Builder $query) => $query->where('slug', $slug)->where('is_archived', false))
            ->with(['color', 'size'])
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        $wanted = $color !== null ? strtoupper(trim($color)) : null;

        if ($wanted !== '') {
            $match = $candidates->first(
                fn (Variant $variant) => strtoupper((string) $variant->color?->hex_code) === $wanted
            );

            if ($match) {
                return $match;
            }
        }

        return $candidates->filter(fn (Variant $variant) => $variant->quantity > 0)
            ->sortBy(fn (Variant $variant) => (float) $variant->final_price)
            ->first() ?? $candidates->first();
    }

    /**
     * Shape a line for the JSON API.
     *
     * @return array<string, mixed>
     */
    private function presentItem(CartItem $item): array
    {
        $variant = $item->variant;

        return [
            'id' => $item->id,
            'product_id' => $item->product_id,
            'variant_id' => $item->variant_id,
            'slug' => $item->product?->slug,
            'name' => $item->product?->name,
            'image' => $item->product?->primary_image?->url,
            'price' => (float) ($variant?->final_price ?? 0),
            'quantity' => $item->quantity,
            'line_total' => $item->line_total,
            'color' => $variant?->color?->name,
            // The hex of the same colour, so a client can paint the swatch
            // without holding a catalog of its own. The name above is for
            // the label; a CSS custom property needs the hex.
            'color_hex' => $variant?->color?->hex_code,
            'size' => $variant?->size?->name,
            'available_stock' => $variant?->quantity ?? 0,
            'is_available' => $item->is_available,
        ];
    }

    /**
     * Shape one variant for the storefront catalog.
     *
     * The price is the variant's own effective price — the discount price
     * when the variant carries one, its list price otherwise — so a shopper
     * is quoted for the combination they picked and not for the cheapest
     * variant of the product. The stock figure is what is actually on the
     * shelf right now, which is the number the server will check the cart
     * against.
     *
     * @return array{id: int, color: ?string, color_name: ?string, size: ?string, price: float, stock: int}
     */
    private function presentVariant(Variant $variant): array
    {
        return [
            'id' => $variant->id,
            'color' => $variant->color?->hex_code,
            'color_name' => $variant->color?->name,
            'size' => $variant->size?->name,
            'price' => (float) $variant->final_price,
            'stock' => (int) $variant->quantity,
        ];
    }
}

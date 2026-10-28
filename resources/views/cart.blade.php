{{--
    Cart page — fully client-rendered.

    The page is served by routes/web.php as a presentation-only
    closure (no session, no DB, no controller). All cart state
    lives in localStorage and is hydrated by resources/js/cart.js.

    What this view provides:
    - Static page shell: header, items list container, summary,
      mobile bottom bar, empty-state panel, toast slot.
    - The product data, exposed as JSON in a <script type="application/json">
      tag so the JS can validate slugs / colors and compute prices
      from the same source of truth as the rest of the storefront.
    - aria-live regions for the item counter, per-line subtotals,
      and the grand total (so screen readers announce updates).
--}}

@extends('layouts.store')

@section('title', 'Your cart — ABO')
@section('description', 'Review the pieces in your cart before checkout.')

@section('content')
    @php
        // Slim payload for the JS — what cart.js needs to validate and
        // price lines, plus the variant list that lets it address an exact
        // colour × size row instead of guessing from the product. No fake
        // numbers, no fabricated data: this is the real catalog from the
        // database.
        $productsPayload = array_map(
            fn ($p) => [
                'slug'    => $p['slug'],
                'name'    => $p['name'],
                // The catalog carries decimal prices, so no integer cast
                // here — truncating would quietly drop the cents off the
                // shelf before the cart ever sees them.
                'price'   => $p['price'],
                'image'   => $p['image'],
                'category'=> $p['category'],
                'colors'  => $p['colors'] ?? [],
                // Every colour × size combination, each carrying the
                // variant id the cart API needs to address that row.
                'variants'=> $p['variants'] ?? [],
            ],
            $products ?? []
        );

        // The minimum order the shop enforces. The checkout refuses an
        // order below it, so the cart says so while the shopper can
        // still do something about it. Zero means the shop has no
        // minimum, and no notice is shown at all.
        $minimumOrder = (float) ($minimumOrder ?? 0);
    @endphp

    <section class="cart" aria-labelledby="cart-title">
        <header class="cart__head">
            <p class="cart__eyebrow">
                <span class="cart__eyebrow-line" aria-hidden="true"></span>
                <span>REVIEW YOUR PICKS</span>
            </p>
            <div class="cart__head-row">
                <h1 class="cart__title" id="cart-title">
                    YOUR <span class="cart__title-accent">CART.</span>
                </h1>
                <p class="cart__count" aria-live="polite" data-cart-count>
                    0 items
                </p>
            </div>
        </header>

        {{-- Items + summary grid. JS populates the rows; the empty
             state and the summary shells are always present. --}}
        <div class="cart__grid" data-cart-grid>
            <ul
                class="cart__items"
                role="list"
                aria-label="Cart items"
                data-cart-items
            ></ul>

            {{-- Empty state. Shown when there are 0 lines. Hidden by JS
                 once the cart is populated. --}}
            <div class="cart__empty" data-cart-empty role="status">
                <div class="cart__empty-inner">
                    <p class="cart__empty-title">NOTHING HERE. YET.</p>
                    <p class="cart__empty-line">
                        Your cart is empty. Start with the new drop and pick the
                        pieces that speak to you.
                    </p>
                    <a class="cart__empty-cta" href="{{ route('shop') }}">
                        CONTINUE SHOPPING
                    </a>
                </div>
            </div>

            {{-- Order summary. Sticky on desktop, always visible. --}}
            <aside class="cart__summary" aria-label="Order summary" data-cart-summary>
                <h2 class="cart__summary-title">ORDER SUMMARY</h2>

                <dl class="cart__summary-list">
                    <div class="cart__summary-row">
                        <dt>Items</dt>
                        <dd data-cart-summary-count>0</dd>
                    </div>
                    <div class="cart__summary-row">
                        <dt>Subtotal</dt>
                        <dd data-cart-summary-subtotal>0 EGP</dd>
                    </div>
                    <div class="cart__summary-row">
                        <dt>Shipping</dt>
                        <dd>Calculated at checkout</dd>
                    </div>
                </dl>

                <div class="cart__summary-total">
                    <span>Total</span>
                    <span class="cart__summary-total-value" aria-live="polite" data-cart-summary-total>
                        0 EGP
                    </span>
                </div>

                <p class="cart__summary-note">
                    Shipping is added at checkout.
                </p>

                {{-- The minimum order. The shop refuses an order below
                     it, so the cart says how far the shopper is from it
                     rather than letting them find out at the checkout. --}}
                <p class="cart__summary-note cart__summary-note--minimum"
                   data-cart-minimum-note
                   role="status"
                   aria-live="polite"
                   hidden></p>

                {{-- The checkout CTA is a real <a>: when the cart is
                     empty — or below the shop's minimum order — the JS
                     swaps it into a disabled-looking state
                     (aria-disabled + data-disabled + tabindex) instead
                     of using the native `disabled` attribute, which
                     doesn't exist on anchors. --}}
                <a
                    class="cart__checkout"
                    data-cart-checkout
                    href="{{ route('checkout.show') }}"
                    aria-disabled="true"
                    data-disabled="true"
                    tabindex="-1"
                >
                    PROCEED TO CHECKOUT
                </a>

                <a class="cart__continue" href="{{ route('shop') }}">
                    CONTINUE SHOPPING
                </a>
            </aside>
        </div>

        {{-- Mobile fixed bottom bar. Hidden on desktop. Mirrors the
             summary total + the checkout CTA so users on small
             screens never have to scroll back to the top. The mobile
             CTA is also a real <a> so it can navigate to /checkout. --}}
        <div class="cart__mobilebar" data-cart-mobilebar hidden>
            <div class="cart__mobilebar-text">
                <span class="cart__mobilebar-label">Total</span>
                <span class="cart__mobilebar-value" data-cart-mobilebar-total>
                    0 EGP
                </span>
            </div>
            <a
                class="cart__mobilebar-cta"
                data-cart-mobilebar-checkout
                href="{{ route('checkout.show') }}"
                aria-disabled="true"
                data-disabled="true"
                tabindex="-1"
            >
                CHECKOUT
            </a>
        </div>

        {{-- Toast slot. JS creates toast nodes here. --}}
        <div
            class="cart__toasts"
            data-cart-toasts
            role="region"
            aria-label="Cart notifications"
        ></div>
    </section>

    {{-- Product data, exposed to the JS as a single JSON payload.
         This is the only place the page references prices/names —
         every line on screen and every total is computed from it. --}}
    <script type="application/json" data-cart-products>{!! json_encode($productsPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>

    {{-- The shop's rules the cart has to honour before the checkout
         does. The summary endpoint carries the same figures and is the
         authority; this copy is what the page can paint before the
         first round trip lands. --}}
    <script type="application/json" data-cart-rules>{!! json_encode(['minimum_order' => $minimumOrder], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
@endsection

@push('scripts')
    {{-- Page-specific hook: tell cart.js this is the cart page. --}}
    <script>
        document.documentElement.dataset.cartPage = '1';
    </script>
@endpush

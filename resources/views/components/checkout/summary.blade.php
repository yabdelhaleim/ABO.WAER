{{--
    Checkout summary — reusable order summary list.

    The summary rows themselves are filled in by the client (the
    server has no cart). This component only renders the shell, the
    eyebrow, the items list placeholder, the totals rows, and the
    "EDIT CART" link.

    Props
    -----
    @param  bool    $showTitle  Render the "ORDER SUMMARY" heading.
    @param  string  $idSuffix   Makes the heading id unique. The summary
                                is rendered twice on the checkout page
                                (a mobile copy inside the collapsible
                                panel and the desktop rail), so every
                                id has to carry the suffix of the copy
                                it belongs to.
--}}

@props([
    'showTitle' => true,
    'idSuffix' => null,
])

@php
    $titleId = 'checkout-summary-title'.($idSuffix ? '-'.$idSuffix : '');
@endphp

<aside
    class="checkout__summary"
    @if ($showTitle) aria-labelledby="{{ $titleId }}" @else aria-label="Order summary" @endif
    data-checkout-summary
>
    @if ($showTitle)
        <h2 class="checkout__summary-title" id="{{ $titleId }}">ORDER SUMMARY</h2>
    @endif

    <ul
        class="checkout__summary-items"
        role="list"
        aria-label="Cart items"
        data-checkout-summary-items
    ></ul>

    <dl class="checkout__summary-list">
        <div class="checkout__summary-row">
            <dt>Items</dt>
            <dd data-checkout-summary-count>0</dd>
        </div>
        <div class="checkout__summary-row">
            <dt>Subtotal</dt>
            <dd data-checkout-summary-subtotal>0 EGP</dd>
        </div>
        <div class="checkout__summary-row">
            <dt>Shipping</dt>
            <dd>Calculated after order review</dd>
        </div>
    </dl>

    <div class="checkout__summary-total">
        <span>Total</span>
        <span class="checkout__summary-total-value" aria-live="polite" data-checkout-summary-total>
            0 EGP
        </span>
    </div>

    <p class="checkout__summary-note">
        Shipping is confirmed with the order. No charges today.
    </p>

    <a class="checkout__edit-cart" href="{{ route('cart') }}">EDIT CART</a>
</aside>

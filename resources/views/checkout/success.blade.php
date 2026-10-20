{{--
    Order success — polished, brand-aligned. Front-end only.

    The JS module (resources/js/success.js) reads the order payload
    that checkout.js wrote to sessionStorage.abo_last_order and
    fills in the placeholders below. If the storage key is missing
    (direct visit / refresh after the sessionStorage is cleared) the
    JS redirects to /shop — we never show a success state without a
    real order backing it.

    Personal data (name / phone / address) is deliberately NOT
    rendered here. The submit flow stores only the line items,
    subtotal, payment method key, order number and placedAt
    timestamp. The task spec ("Do not invent addresses…") plus the
    existing privacy design both forbid displaying more than that.

    VIEW MY ORDERS is intentionally omitted — there is no orders
    route in this codebase. The task spec says: "If the destination
    is not implemented, do not create a fake destination… Omit the
    button or leave it out until the relevant page exists."
--}}

@extends('layouts.store')

@section('title', 'Order confirmed — ABO')
@section('description', 'Thank you. Your order is in and being prepared.')

@section('announcement')
    <aside class="announcement" aria-label="Store announcement">
        <div class="announcement__inner">
            <span>{{ $announcement }}</span>
        </div>
    </aside>
@endsection

@section('content')
    <section class="checkout-success" aria-labelledby="checkout-success-title">
        <div class="checkout-success__inner">
            {{-- Animated checkmark. SVG path draws in via stroke-dasharray
                 CSS animation; the whole hero is hidden by default and
                 the JS removes the [hidden] flag once it has confirmed
                 the order payload, so the animation only plays after a
                 real submission. Respects prefers-reduced-motion. --}}
            <div class="checkout-success__mark" aria-hidden="true" data-checkout-success-mark hidden>
                <svg viewBox="0 0 80 80" class="checkout-success__mark-svg" focusable="false">
                    <circle
                        class="checkout-success__mark-circle"
                        cx="40" cy="40" r="36"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                    />
                    <path
                        class="checkout-success__mark-check"
                        d="M24 42 L36 54 L58 28"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="3"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    />
                </svg>
            </div>

            <p class="checkout-success__eyebrow" data-checkout-success-eyebrow>
                <span class="checkout-success__eyebrow-line" aria-hidden="true"></span>
                <span>ORDER PLACED</span>
            </p>

            <h1 class="checkout-success__title" id="checkout-success-title">
                ORDER <span class="checkout-success__title-accent">CONFIRMED.</span>
            </h1>

            <p class="checkout-success__line">
                Your order is in. We're getting it ready.
            </p>

            {{-- Order info grid. Server-rendered shell with the
                 data-checkout-success-* hooks the JS fills in. Any
                 field that's not in the payload stays as a neutral
                 placeholder (—), never fabricated. --}}
            <dl class="checkout-success__info" aria-label="Order details">
                <div class="checkout-success__info-item">
                    <dt>Order number</dt>
                    <dd class="checkout-success__info-value checkout-success__info-value--accent" data-checkout-success-order>
                        {{ $order }}
                    </dd>
                </div>
                <div class="checkout-success__info-item">
                    <dt>Order date</dt>
                    <dd class="checkout-success__info-value" data-checkout-success-date>
                        —
                    </dd>
                </div>
                <div class="checkout-success__info-item">
                    <dt>Payment method</dt>
                    <dd class="checkout-success__info-value" data-checkout-success-payment>
                        —
                    </dd>
                </div>
                <div class="checkout-success__info-item">
                    <dt>Order total</dt>
                    <dd class="checkout-success__info-value checkout-success__info-value--total" data-checkout-success-total>
                        —
                    </dd>
                </div>
            </dl>

            {{-- Ordered products. The list is filled in by
                 resources/js/success.js from the order payload. The
                 server renders only the empty list shell. --}}
            <section class="checkout-success__items" aria-label="Ordered items" data-checkout-success-items-section>
                <h2 class="checkout-success__items-title">YOUR PIECES</h2>
                <ul
                    class="checkout-success__items-list"
                    role="list"
                    data-checkout-success-items
                ></ul>
            </section>

            {{-- Action buttons. Only CONTINUE SHOPPING is rendered
                 (primary). VIEW MY ORDERS / TRACK ORDER are omitted
                 per the task spec: no orders route exists in this
                 codebase, so no fake destination is created. --}}
            <div class="checkout-success__actions">
                <a class="checkout-success__cta checkout-success__cta--primary" href="{{ route('shop') }}">
                    CONTINUE SHOPPING
                </a>
            </div>
        </div>
    </section>

    {{-- Payment-method labels keyed by the same keys the checkout
         form's <input type="radio" name="payment_method"> uses.
         success.js looks up the label from this payload so the
         rendered string ("Cash on delivery") matches the option
         the customer picked at checkout. --}}
    <script type="application/json" data-checkout-success-payment-methods>{!! json_encode($paymentMethods, JSON_UNESCAPED_UNICODE) !!}</script>
@endsection

@push('scripts')
    <script>
        // The order number from the URL is passed in as a fallback
        // for the (rare) case where sessionStorage was cleared
        // between submit and arrival here. If the storage payload is
        // missing entirely the JS will redirect to /shop.
        document.documentElement.dataset.checkoutSuccessPage = '1';
        window.__checkoutSuccessOrderNumber = @json($order);
    </script>
@endpush

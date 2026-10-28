{{--
    Order success — polished, brand-aligned.

    Everything on this page is rendered from the Order the server
    loaded for the order number in the URL
    (Store\CheckoutController::success). The order is a real row in
    the `orders` table — there is no client-side copy of it and no
    sessionStorage payload to lose, so a refresh or a shared link
    renders the same confirmation.

    Personal data (name / phone / address) is deliberately NOT
    rendered here. The order confirmation shows what was bought, how
    it is paid for and what it costs — nothing about who it is for.

    The JS module (resources/js/success.js) is only responsible for
    the animated checkmark; every value below is server-rendered.
--}}

@extends('layouts.store')

@section('title', 'Order confirmed — ABO')
@section('description', 'Thank you. Your order is in and being prepared.')

@section('content')
    <section class="checkout-success" aria-labelledby="checkout-success-title">
        <div class="checkout-success__inner">
            {{-- Animated checkmark. SVG path draws in via stroke-dasharray
                 CSS animation. The page is rendered from a real order, so
                 the animation always plays; it respects
                 prefers-reduced-motion. --}}
            <div class="checkout-success__mark" aria-hidden="true" data-checkout-success-mark>
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

            {{-- Order info grid. Every value comes from the Order row the
                 server loaded for this order number — nothing here is a
                 placeholder waiting for a client-side payload. --}}
            <dl class="checkout-success__info" aria-label="Order details">
                <div class="checkout-success__info-item">
                    <dt>Order number</dt>
                    <dd class="checkout-success__info-value checkout-success__info-value--accent" data-checkout-success-order>
                        {{ $orderModel->order_number }}
                    </dd>
                </div>
                <div class="checkout-success__info-item">
                    <dt>Order date</dt>
                    <dd class="checkout-success__info-value" data-checkout-success-date>
                        {{ $orderModel->created_at->format('D, d M Y · H:i') }}
                    </dd>
                </div>
                <div class="checkout-success__info-item">
                    <dt>Payment method</dt>
                    <dd class="checkout-success__info-value" data-checkout-success-payment>
                        {{ $paymentMethods[$orderModel->payment_method]['label'] ?? 'Cash on delivery' }}
                    </dd>
                </div>
                <div class="checkout-success__info-item">
                    <dt>Order total</dt>
                    <dd class="checkout-success__info-value checkout-success__info-value--total" data-checkout-success-total>
                        {{ number_format((float) $orderModel->total, 0, '.', ',') }} EGP
                    </dd>
                </div>
            </dl>

            {{-- Ordered products. The line items the order was created
                 with, read straight off the order. --}}
            <section class="checkout-success__items" aria-label="Ordered items" data-checkout-success-items-section>
                <h2 class="checkout-success__items-title">YOUR PIECES</h2>
                <ul
                    class="checkout-success__items-list"
                    role="list"
                    data-checkout-success-items
                >
                    @foreach ($orderModel->items as $item)
                        <li class="checkout-success__item">
                            <div class="checkout-success__item-body">
                                <p class="checkout-success__item-name">{{ $item->product_name }}</p>
                                @if ($item->color_name || $item->size_name)
                                    <p class="checkout-success__item-chips">
                                        @if ($item->color_name) Color · {{ $item->color_name }} @endif
                                        @if ($item->size_name) Size · {{ $item->size_name }} @endif
                                    </p>
                                @endif
                                <p class="checkout-success__item-qty">Qty {{ $item->quantity }}</p>
                            </div>
                            <div class="checkout-success__item-total">
                                {{ number_format((float) $item->total_price, 0, '.', ',') }} EGP
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>

            {{-- Action buttons. Only CONTINUE SHOPPING is rendered
                 (primary) — the confirmation is a dead end the customer
                 reads, not a place to navigate from. --}}
            <div class="checkout-success__actions">
                <a class="checkout-success__cta checkout-success__cta--primary" href="{{ route('shop') }}">
                    CONTINUE SHOPPING
                </a>
            </div>
        </div>
    </section>
@endsection

{{--
    Checkout page — front-end only.

    The page is server-rendered as a shell. The cart lives in
    localStorage (see resources/js/cart.js) and is hydrated by the
    page's <script> blocks; the server has no cart, so the order
    summary is empty on first paint and filled in by
    resources/js/checkout.js.

    Submission is intercepted entirely by the JS: it computes the
    order number client-side, stores a short-lived payload in
    sessionStorage under `abo_last_order`, and navigates to
    /checkout/success/{order_number}. The server has no order
    processing in this task — that lives in a follow-up.
--}}

@extends('layouts.store')

@section('title', 'Checkout — ABO')
@section('description', 'Complete your order.')

@section('announcement')
    <aside class="announcement" aria-label="Store announcement">
        <div class="announcement__inner">
            <span>{{ $announcement }}</span>
        </div>
    </aside>
@endsection

@section('content')
    @php
        $productsPayload = array_map(
            fn ($p) => [
                'slug'    => $p['slug'],
                'name'    => $p['name'],
                'price'   => (int) $p['price'],
                'image'   => $p['image'],
                'category'=> $p['category'],
                'colors'  => $p['colors'] ?? [],
            ],
            $products ?? []
        );
        $governorates = require resource_path('views/store/data/governorates.php');
        // Single source of truth for the list of payment methods
        // the storefront currently supports. Mirrored in
        // resources/js/checkout.js for the radios.
        $paymentMethods = [
            'cod' => ['key' => 'cod', 'label' => 'Cash on delivery', 'hint' => 'Pay in cash when your order arrives.'],
        ];
        $termsAvailable = \Illuminate\Support\Facades\Route::has('terms')
            || \Illuminate\Support\Facades\Route::has('terms.show')
            || \Illuminate\Support\Facades\Route::has('pages.terms');
        $hasPaymentMethods = count($paymentMethods) > 0;
    @endphp

    <section class="checkout" aria-labelledby="checkout-title">
        <header class="checkout__head">
            <ol class="checkout__steps" aria-label="Checkout progress">
                <li class="checkout__step">
                    <a href="{{ route('cart') }}">CART</a>
                </li>
                <li class="checkout__step checkout__step--current" aria-current="step">
                    <span>CHECKOUT</span>
                </li>
                <li class="checkout__step">DONE</li>
            </ol>

            <p class="checkout__eyebrow">
                <span class="checkout__eyebrow-line" aria-hidden="true"></span>
                <span>ALMOST YOURS</span>
            </p>

            <div class="checkout__head-row">
                <h1 class="checkout__title" id="checkout-title">
                    CHECK<span class="checkout__title-accent">OUT.</span>
                </h1>
            </div>
        </header>

        {{-- Mobile collapsible summary, shown first on narrow viewports --}}
        <details class="checkout__mobile-summary" data-checkout-mobile-summary>
            <summary class="checkout__mobile-summary-bar" aria-controls="checkout-mobile-summary-body">
                <span class="checkout__mobile-summary-label">
                    ORDER SUMMARY ·
                    <span data-checkout-mobile-summary-count>0</span> ITEMS
                </span>
                <span class="checkout__mobile-summary-total" data-checkout-mobile-summary-total>
                    0 EGP
                </span>
            </summary>
            <div class="checkout__mobile-summary-body" id="checkout-mobile-summary-body">
                <x-checkout.summary :show-title="false" id-suffix="mobile" />
            </div>
        </details>

        <div class="checkout__grid" data-checkout-grid>
            {{-- Form column. Server-side submission: the action
                 targets Store\CheckoutController::process, which
                 either redirects (regular form post) or returns a
                 JSON envelope (AJAX post). The JS still handles
                 the busy state and client-side validation. --}}
            <form
                class="checkout__form"
                method="POST"
                action="{{ route('checkout.process') }}"
                novalidate
                data-checkout-form
            >
                @csrf
                {{-- Hidden fields populated by JS on submit. Server does
                     not exist for this task; the JS intercepts submit
                     and navigates to the success route. The fields are
                     here so a future server implementation can read them. --}}
                <input type="hidden" name="cart_json" value="" data-checkout-cart-json>
                <input type="hidden" name="expected_total" value="0" data-checkout-expected-total>
                {{-- Coupon code — populated by checkout.js once the customer
                     validates it through the AJAX endpoint. Server-side
                     validation happens in OrderService during placeOrder. --}}
                <input type="hidden" name="coupon_code" value="" data-checkout-coupon-input>

                {{-- Error summary --}}
                @if ($errors->any())
                    <div class="checkout__alert" role="alert" data-checkout-alert>
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" class="checkout__alert-icon">
                            <path d="M12 2 1 21h22L12 2zm0 6 7.5 13h-15L12 8zm-1 4v4h2v-4h-2zm0 5v2h2v-2h-2z" fill="currentColor"/>
                        </svg>
                        <div>
                            <p class="checkout__alert-title">Please fix the highlighted fields.</p>
                            <ul class="checkout__alert-list">
                                @foreach ($errors->all() as $err)
                                    <li>{{ $err }}</li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                @endif

                {{-- 01 DELIVERY DETAILS --}}
                <fieldset class="checkout__panel">
                    <legend class="checkout__panel-legend">
                        <span class="checkout__panel-num">01</span>
                        <span class="checkout__panel-title">DELIVERY DETAILS</span>
                    </legend>

                    <x-checkout.field
                        name="name"
                        label="Full name"
                        autocomplete="name"
                        required
                    />

                    <x-checkout.field
                        name="phone"
                        label="Mobile number"
                        type="tel"
                        inputmode="tel"
                        autocomplete="tel-national"
                        placeholder="01XXXXXXXXX"
                        required
                    />

                    <x-checkout.field
                        name="governorate"
                        label="Governorate"
                        :options="array_combine($governorates, $governorates)"
                        placeholder="Select your governorate"
                        autocomplete="address-level1"
                        required
                    />

                    <x-checkout.field
                        name="area"
                        label="Area / City"
                        autocomplete="address-level2"
                        required
                    />

                    <x-checkout.field
                        name="address"
                        label="Detailed address (street, building, floor, apartment)"
                        :textarea="true"
                        :rows="3"
                        autocomplete="street-address"
                        required
                    />

                    <x-checkout.field
                        name="notes"
                        label="Notes (optional)"
                        :textarea="true"
                        :rows="2"
                    />
                </fieldset>

                {{-- 02 PAYMENT --}}
                <fieldset class="checkout__panel" data-checkout-payment-state="{{ $hasPaymentMethods ? 'available' : 'none' }}">
                    <legend class="checkout__panel-legend">
                        <span class="checkout__panel-num">02</span>
                        <span class="checkout__panel-title">PAYMENT</span>
                    </legend>

                    @if ($hasPaymentMethods)
                        <div class="checkout__payment-list" role="radiogroup" aria-label="Payment method">
                            @foreach ($paymentMethods as $method)
                                <label class="checkout__payment-option">
                                    <input
                                        type="radio"
                                        name="payment_method"
                                        value="{{ $method['key'] }}"
                                        class="checkout__payment-radio"
                                        @checked(old('payment_method') === $method['key'] || $loop->first)
                                        required
                                    >
                                    <span class="checkout__payment-mark" aria-hidden="true"></span>
                                    <span class="checkout__payment-text">
                                        <span class="checkout__payment-label">{{ $method['label'] }}</span>
                                        <span class="checkout__payment-hint">{{ $method['hint'] }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    @else
                        <div class="checkout__payment-empty" role="status">
                            <p class="checkout__payment-empty-title">NO PAYMENT METHOD YET.</p>
                            <p class="checkout__payment-empty-line">
                                We're not accepting online orders just yet. Drop us a line and
                                we'll arrange payment manually.
                            </p>
                        </div>
                    @endif
                </fieldset>

                {{-- Submit (desktop only; the mobile bar holds the equivalent CTA) --}}
                <div class="checkout__submit-wrap">
                    <button
                        type="submit"
                        class="checkout__submit"
                        data-checkout-submit
                        @if (!$hasPaymentMethods) disabled aria-disabled="true" @endif
                    >
                        <span class="checkout__submit-label">PLACE ORDER</span>
                        <span class="checkout__submit-busy" aria-hidden="true">
                            <span class="checkout__submit-spinner" aria-hidden="true"></span>
                            PLACING ORDER…
                        </span>
                    </button>

                    @if ($termsAvailable)
                        <p class="checkout__terms">
                            By placing your order you agree to our
                            <a href="{{ route('terms') }}">terms</a>.
                        </p>
                    @endif
                </div>
            </form>

            {{-- Desktop right-rail summary --}}
            <div class="checkout__aside">
                <x-checkout.summary id-suffix="desktop" />
            </div>
        </div>

        {{-- Mobile fixed bottom bar (mirrors the desktop submit) --}}
        <div class="checkout__mobilebar" data-checkout-mobilebar>
            <div class="checkout__mobilebar-text">
                <span class="checkout__mobilebar-label">Total</span>
                <span class="checkout__mobilebar-value" data-checkout-mobilebar-total>0 EGP</span>
            </div>
            <button
                type="button"
                class="checkout__mobilebar-cta"
                data-checkout-mobilebar-submit
                @if (!$hasPaymentMethods) disabled aria-disabled="true" @endif
            >
                PLACE ORDER
            </button>
        </div>
    </section>

    {{-- Products JSON, same shape as the cart page --}}
    <script type="application/json" data-checkout-products>{!! json_encode($productsPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
    {{-- Governorates JSON, also from the same source of truth --}}
    <script type="application/json" data-checkout-governorates>{!! json_encode($governorates, JSON_UNESCAPED_UNICODE) !!}</script>
    {{-- Payment methods list (single source of truth, server side) --}}
    <script type="application/json" data-checkout-payment-methods>{!! json_encode($paymentMethods, JSON_UNESCAPED_UNICODE) !!}</script>
@endsection

@push('scripts')
    <script>
        document.documentElement.dataset.checkoutPage = '1';
    </script>
@endpush

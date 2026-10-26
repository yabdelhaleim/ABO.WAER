{{--
    ABO — 419 "page expired".

    The checkout form carries a CSRF token that belongs to the session
    it was rendered in. When that session expires — the tab sat open too
    long, the browser dropped the cookie — the token no longer matches
    and the server refuses the POST with a 419 instead of placing the
    order.

    Nothing is lost. The cart lives in the browser's localStorage, so
    the page can send the customer straight back to /cart or /checkout
    with everything they picked still in place.

    Bilingual on purpose: the storefront language is not settled yet, so
    the Arabic carries the message and a small English line sits under
    it.

    Like the other error pages this view reads no models and takes no
    view data — it renders after the failed request, so it must not
    depend on anything that request was mutating. The shared layout
    reads the session (through @auth) but no tables, which is safe for
    an expired session.
--}}
@extends('layouts.store')

@section('title', 'Session Expired')
@section('description', 'Your session expired before the order went through. Your cart is still here.')
@section('og_title', 'Session Expired — ABO')
@section('og_extra')
    <meta name="robots" content="noindex, follow">
@endsection

@section('content')
    <section class="error-page" aria-labelledby="error-page-title">
        <p class="error-page__eyebrow">
            <span class="error-page__eyebrow-line" aria-hidden="true"></span>
            <span dir="rtl">انتهت الجلسة</span>
        </p>

        <span class="error-page__code error-page__code--accent error-page__code--drift" aria-hidden="true">419</span>

        <h1 class="error-page__title" id="error-page-title" dir="rtl">انتهت صلاحية الجلسة</h1>

        <p class="error-page__note">Your session expired before the order went through.</p>

        <p class="error-page__lede" dir="rtl">
            الصفحة فضلت مفتوحة وقت أطول من المدة المسموح بها، فالجلسة انتهت والطلب ما اتسجلش.
            سلة التسوق لسه محفوظة في المتصفح — افتحها تاني وكمّل من غير ما تفقد أي حاجة.
        </p>

        <p class="error-page__note">Your cart is still in the browser — nothing you picked was lost.</p>

        <div class="error-page__actions">
            <a href="{{ route('cart') }}" class="store-btn store-btn--primary">
                <span>Back to Cart</span>
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M5 12h12M13 6l6 6-6 6" fill="none" stroke="currentColor"
                          stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </a>

            <a href="{{ route('checkout.show') }}" class="store-btn store-btn--ghost">
                <span>Back to Checkout</span>
            </a>
        </div>
    </section>
@endsection

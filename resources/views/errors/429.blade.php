{{--
    ABO — 429 "too many requests".

    The checkout endpoint is throttled so order-spam bots cannot fill
    the orders table. A double tap, a stale tab or a retry loop can
    bounce a customer here. Nothing was written — the request never
    reached the order — so the way out is to wait a moment and try
    again.

    Bilingual on purpose: the storefront language is not settled yet, so
    the Arabic carries the message and a small English line sits under
    it.
--}}
@extends('layouts.store')

@section('title', 'Too Many Attempts')
@section('description', 'Too many attempts in a row. Wait a moment and try again — your cart is safe.')
@section('og_title', 'Too Many Attempts — ABO')
@section('og_extra')
    <meta name="robots" content="noindex, follow">
@endsection

@section('content')
    <section class="error-page" aria-labelledby="error-page-title">
        <p class="error-page__eyebrow">
            <span class="error-page__eyebrow-line" aria-hidden="true"></span>
            <span dir="rtl">محاولات كتير</span>
        </p>

        <span class="error-page__code error-page__code--accent error-page__code--drift" aria-hidden="true">429</span>

        <h1 class="error-page__title" id="error-page-title" dir="rtl">محاولات كتير في وقت قصير</h1>

        <p class="error-page__note">Too many attempts in a row.</p>

        <p class="error-page__lede" dir="rtl">
            استلمنا محاولات كتير في وقت قصير، فوقفنا الطلبات مؤقت. استنى دقيقة وحاول تاني —
            سلة التسوق لسه محفوظة في المتصفح.
        </p>

        <p class="error-page__note">Give it a minute and try again — your cart is safe.</p>

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

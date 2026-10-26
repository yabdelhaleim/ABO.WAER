{{--
    ABO — 401 "unauthenticated".

    Shown when a request reaches an area that needs a signed-in customer
    and the session does not carry one. The customer-facing auth
    middleware normally redirects to the sign-in screen instead, so this
    page is the fallback for the requests that cannot redirect (an
    API call, a fetch without a redirect target).

    Bilingual on purpose: the storefront language is not settled yet, so
    the Arabic carries the message and a small English line sits under
    it.
--}}
@extends('layouts.store')

@section('title', 'Sign In Required')
@section('description', 'Sign in to open this part of the ABO store.')
@section('og_title', 'Sign In Required — ABO')
@section('og_extra')
    <meta name="robots" content="noindex, follow">
@endsection

@section('content')
    <section class="error-page" aria-labelledby="error-page-title">
        <p class="error-page__eyebrow">
            <span class="error-page__eyebrow-line" aria-hidden="true"></span>
            <span dir="rtl">محتاج تسجيل دخول</span>
        </p>

        <span class="error-page__code error-page__code--accent error-page__code--drift" aria-hidden="true">401</span>

        <h1 class="error-page__title" id="error-page-title" dir="rtl">سجّل دخولك عشان تفتح الصفحة دي</h1>

        <p class="error-page__note">You need to sign in to open this page.</p>

        <p class="error-page__lede" dir="rtl">
            الجزء ده من المتجر محجوز للعملاء المسجّلين. سجّل دخولك — بياخد ثانية وراجع لنفس المكان.
        </p>

        <p class="error-page__note">Signing in takes a moment and brings you straight back.</p>

        <div class="error-page__actions">
            <a href="{{ route('login') }}" class="store-btn store-btn--primary">
                <span>Sign In</span>
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M5 12h12M13 6l6 6-6 6" fill="none" stroke="currentColor"
                          stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </a>

            <a href="{{ route('shop') }}" class="store-btn store-btn--ghost">
                <span>Back to Shop</span>
            </a>
        </div>
    </section>
@endsection

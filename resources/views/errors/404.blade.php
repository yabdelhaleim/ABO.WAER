{{--
    ABO — 404 "page not found".

    Extends layouts.store so the nav, announcement bar and footer stay
    identical to every other storefront page; the error only replaces the
    <main> content. Resolved automatically by Laravel's exception handler
    (resources/views/errors/{code}.blade.php) — no route needed.
--}}
@extends('layouts.store')

@section('title', 'Page Not Found')
@section('description', 'The page you were looking for is not available. Browse the ABO collection instead.')
@section('og_title', 'Page Not Found — ABO')
@section('og_extra')
    {{-- Tell crawlers the soft-404 is genuinely gone rather than missing. --}}
    <meta name="robots" content="noindex, follow">
@endsection

@section('content')
    <section class="error-page" aria-labelledby="error-page-title">
        <p class="error-page__eyebrow">
            <span class="error-page__eyebrow-line" aria-hidden="true"></span>
            <span>Lost the thread</span>
        </p>

        <span class="error-page__code error-page__code--accent error-page__code--drift" aria-hidden="true">404</span>

        <h1 class="error-page__title" id="error-page-title">This page took a different route</h1>

        <p class="error-page__lede">
            Looks like this page or the piece you were after is not available right now.
            The collection, however, is exactly where you left it.
        </p>

        <div class="error-page__actions">
            <a href="{{ route('shop') }}" class="store-btn store-btn--primary">
                <span>Back to Shop</span>
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M5 12h12M13 6l6 6-6 6" fill="none" stroke="currentColor"
                          stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </a>

            <a href="{{ route('home') }}" class="store-btn store-btn--ghost">
                <span>Go Home</span>
            </a>
        </div>
    </section>
@endsection
{{--
    ABO — 403 "access denied".

    Shown for AuthorizationException. Extends layouts.store so the page
    still carries the storefront chrome — a wall of plain text here would
    read as a broken site rather than a guarded area.
--}}
@extends('layouts.store')

@section('title', 'Access Denied')
@section('description', 'You do not have permission to open this area. Head back to the ABO storefront.')
@section('og_title', 'Access Denied — ABO')
@section('og_extra')
    <meta name="robots" content="noindex, follow">
@endsection

@section('content')
    <section class="error-page" aria-labelledby="error-page-title">
        <p class="error-page__eyebrow">
            <span class="error-page__eyebrow-line" aria-hidden="true"></span>
            <span>Members only</span>
        </p>

        <span class="error-page__code error-page__code--accent error-page__code--drift" aria-hidden="true">403</span>

        <h1 class="error-page__title" id="error-page-title">You don't have permission to access this area</h1>

        <p class="error-page__lede">
            This part of the store is reserved for the team. If you think this is a mistake,
            signing in again usually clears it up.
        </p>

        <div class="error-page__actions">
            <a href="{{ route('home') }}" class="store-btn store-btn--primary">
                <span>Go Home</span>
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
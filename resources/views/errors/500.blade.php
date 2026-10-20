{{--
    ABO — 500 "server error".

    Extends layouts.store like the other error pages so it still reads as
    part of the store rather than a raw exception screen. Deliberately
    says nothing about what failed: the cause stays in storage/logs, and
    the customer only gets an apology and a way out.

    Note: this view renders AFTER the failure, so it must not depend on
    anything the failed request might have been mutating. It reads no
    models and takes no view data.
--}}
@extends('layouts.store')

@section('title', 'Something Went Wrong')
@section('description', 'An unexpected error occurred. Our team is on it — please try again in a moment.')
@section('og_title', 'Something Went Wrong — ABO')
@section('og_extra')
    <meta name="robots" content="noindex, nofollow">
@endsection

@section('content')
    <section class="error-page" aria-labelledby="error-page-title">
        <p class="error-page__eyebrow">
            <span class="error-page__eyebrow-line" aria-hidden="true"></span>
            <span>Hold tight</span>
        </p>

        <span class="error-page__code error-page__code--accent error-page__code--drift" aria-hidden="true">500</span>

        <h1 class="error-page__title" id="error-page-title">Something went wrong on our side</h1>

        <p class="error-page__lede">
            An unexpected error popped up and our technical team is already on it.
            Give it a moment and try again — nothing on your end was lost.
        </p>

        <div class="error-page__actions">
            {{-- Full reload, not a history back: a retry has to hit the
                 server again for it to be worth anything. --}}
            <a href="{{ route('home') }}" class="store-btn store-btn--primary">
                <span>Refresh Page</span>
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M21 12a9 9 0 1 1-2.64-6.36M21 4v5h-5" fill="none" stroke="currentColor"
                          stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </a>

            <a href="{{ route('shop') }}" class="store-btn store-btn--ghost">
                <span>Back to Shop</span>
            </a>
        </div>
    </section>
@endsection
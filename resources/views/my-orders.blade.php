{{--
    My Orders — front-end only.

    Renders the authenticated customer's order history. The page is
    fully client-rendered from a static mock file
    (resources/js/orders.mock.js) that lives behind a single
    `getOrders()` wrapper. No API calls, no storage, no network.

    The three dev-only states (loading / empty / error) are reachable
    via `?state=loading|empty|error` so designers can review each
    layout without writing any code. The dev switch has no effect
    when the query param is missing — the page falls back to the
    real (mock) flow.

    The view only paints the static shell (sidebar, stats, toolbar,
    list container, toast slot). The JS hydrates the order cards,
    status badges, totals and toasts. No prices, names or counts
    are baked into this template — they are all computed in JS from
    the mock array.
--}}

@extends('layouts.store')

@section('title', 'My Orders — ABO')
@section('description', 'Track every ABO order — in progress, delivered, cancelled — in a single dashboard.')

@section('announcement')
    <aside class="announcement" aria-label="Store announcement">
        <div class="announcement__inner">
            <span>{{ $announcement }}</span>
        </div>
    </aside>
@endsection

@section('content')
    <section
        class="orders"
        aria-labelledby="orders-title"
        data-orders-state="loading"
    >
        {{-- ─── Page title block ──────────────────────────────────── --}}
        <header class="orders__head">
            <p class="orders__eyebrow">
                <span class="orders__eyebrow-line" aria-hidden="true"></span>
                <span>MY ACCOUNT</span>
            </p>
            <h1 class="orders__title" id="orders-title">
                MY <span class="orders__title-accent">ORDERS</span>
            </h1>
        </header>

        <div class="orders__layout">
            {{-- ─── Sidebar ──────────────────────────────────────────── --}}
            <aside class="orders__sidebar" aria-label="Account navigation">
                <div class="orders__identity">
                    <span class="orders__avatar" aria-hidden="true">
                        {{ strtoupper(mb_substr(auth()->user()->name ?? 'M', 0, 1)) }}
                    </span>
                    <div class="orders__identity-text">
                        <p class="orders__welcome">Welcome back</p>
                        <p class="orders__email">
                            {{ auth()->user()->email ?? 'member@abo.store' }}
                        </p>
                    </div>
                </div>

                <nav class="orders__nav" aria-label="Account sections">
                    <a href="#" class="orders__nav-link">Profile</a>
                    <a
                        href="#"
                        class="orders__nav-link is-active"
                        aria-current="page"
                    >My Orders</a>
                    <a href="#" class="orders__nav-link">Wishlist</a>
                    <a href="#" class="orders__nav-link">Addresses</a>
                    <a href="#" class="orders__nav-link">Log out</a>
                </nav>
            </aside>

            {{-- ─── Main content ──────────────────────────────────────── --}}
            <div class="orders__main" data-orders-state>
                {{-- (a) Stats strip --}}
                <section class="orders__stats" aria-label="Order stats">
                    <div class="orders__stat">
                        <span class="orders__stat-value" data-orders-stat-total>0</span>
                        <span class="orders__stat-label">TOTAL ORDERS</span>
                    </div>
                    <div class="orders__stat">
                        <span class="orders__stat-value orders__stat-value--lime" data-orders-stat-progress>0</span>
                        <span class="orders__stat-label">IN PROGRESS</span>
                    </div>
                    <div class="orders__stat">
                        <span class="orders__stat-value" data-orders-stat-spent>0 EGP</span>
                        <span class="orders__stat-label">TOTAL SPENT</span>
                    </div>
                </section>

                {{-- (b) Toolbar --}}
                <div class="orders__toolbar">
                    <div
                        class="orders__tabs"
                        role="toolbar"
                        aria-label="Filter orders by status"
                    >
                        <button
                            type="button"
                            class="orders__tab orders__tab--active"
                            data-orders-tab="all"
                            aria-pressed="true"
                        >
                            <span class="orders__tab-label">All</span>
                            <span class="orders__tab-count" data-orders-tab-count="all">0</span>
                        </button>
                        <button
                            type="button"
                            class="orders__tab"
                            data-orders-tab="in-progress"
                            aria-pressed="false"
                        >
                            <span class="orders__tab-label">In progress</span>
                            <span class="orders__tab-count" data-orders-tab-count="in-progress">0</span>
                        </button>
                        <button
                            type="button"
                            class="orders__tab"
                            data-orders-tab="delivered"
                            aria-pressed="false"
                        >
                            <span class="orders__tab-label">Delivered</span>
                            <span class="orders__tab-count" data-orders-tab-count="delivered">0</span>
                        </button>
                        <button
                            type="button"
                            class="orders__tab"
                            data-orders-tab="cancelled"
                            aria-pressed="false"
                        >
                            <span class="orders__tab-label">Cancelled</span>
                            <span class="orders__tab-count" data-orders-tab-count="cancelled">0</span>
                        </button>
                    </div>

                    <label class="orders__search">
                        <span class="sr-only">Search orders</span>
                        <svg
                            class="orders__search-icon"
                            viewBox="0 0 24 24"
                            aria-hidden="true"
                            focusable="false"
                        >
                            <path d="M21 20.3 16.7 16a8 8 0 1 0-1.4 1.4L20.3 21 21 20.3zM4 10a6 6 0 1 1 12 0 6 6 0 0 1-12 0z" fill="currentColor"/>
                        </svg>
                        <input
                            type="search"
                            class="orders__search-input"
                            placeholder="Search orders"
                            aria-label="Search orders"
                            autocomplete="off"
                            data-orders-search
                        >
                    </label>
                </div>

                {{-- (c) Orders list / empty / error --}}
                <div
                    class="orders__list"
                    data-orders-list
                    aria-live="polite"
                    aria-busy="true"
                ></div>

                <div class="orders__empty" data-orders-empty hidden>
                    <h3 class="orders__empty-title">NO ORDERS FOUND</h3>
                    <p class="orders__empty-line">Nothing matches this filter yet.</p>
                    <a class="orders__btn orders__btn--primary" href="{{ route('shop') }}">
                        START SHOPPING →
                    </a>
                </div>
            </div>
        </div>

        {{-- Toast slot (one toast at a time per spec §8). --}}
        <div
            class="orders__toasts"
            data-orders-toasts
            aria-label="Order notifications"
        ></div>
    </section>
@endsection

@push('scripts')
    {{-- Flag page this page for orders.js. No state = real flow;
         ?state=loading|empty|error = design review. --}}
    <script>
        document.documentElement.dataset.ordersPage = '1';
    </script>
@endpush
{{--
    Admin · Orders list.

    Extends the existing admin layout and is rendered by
    Admin\OrderController. The orders are read from the database by
    Admin\OrderService, serialised into one JSON array, and hydrated
    in public/admin/js/orders.js — which then renders the table,
    slide-over, modal and toast from that array and does all its
    filtering, sorting and paging on the client. The three mutations
    (status, cancel, tracking) are the only calls the script makes
    back to the server; their URLs arrive in the
    `abo-admin-orders-routes` tag below.

    Page sections (top to bottom):
      1. Page header  — SALES eyebrow, "OR" + "DERS" title,
                        EXPORT CSV button on the right
      2. Status tabs  — All / New / Processing / Shipped /
                        Delivered / Cancelled / Returned with counts
      3. Toolbar      — search, Payment select, Governorate select,
                        date range (From / To), Clear filters
      4. Bulk bar     — visible when 1+ row selected (Change status,
                        Print labels)
      5. Orders table — checkbox + Order # + Date + Customer +
                        Governorate + Items + Total + Payment +
                        Status + View
                        Mobile: stacked cards (CSS swap)
      6. Pagination   — 10 rows per page, prev/next + page buttons
      7. Order slide-over — header, status block, timeline, customer,
                        items, payment, internal notes, danger, footer
      8. Cancel modal — single shared instance
      9. Toast        — single shared instance

    Classes reused from the existing admin shell (abo-admin-*)
    wherever they fit; new order-specific classes are prefixed
    `abo-admin-orders-`.
--}}

@extends('admin.layouts.app')

@section('title', 'Orders · Sales')
@section('description', 'ABO — admin orders list: status tabs, filters, bulk actions, order detail slide-over and timeline.')

@php

    // Option catalogues for the status / payment / governorate
    // dropdowns. The order list itself is not generated here any more:
    // $ordersJson and $governorates both arrive from
    // Admin\OrderController, which reads them from the database.
    $statusLabels = [
        'new'        => 'New',
        'processing' => 'Processing',
        'shipped'    => 'Shipped',
        'delivered'  => 'Delivered',
        'cancelled'  => 'Cancelled',
        'returned'   => 'Returned',
    ];

    $paymentMethodLabels = [
        'cod'    => 'Cash on delivery',
        'card'   => 'Card',
        'wallet' => 'Wallet',
    ];
@endphp

@section('content')
    {{-- ─── Page header ────────────────────────────────────────── --}}
    <header class="abo-admin-page__head">
        <div class="abo-admin-page__head-text">
            <p class="abo-admin-page__eyebrow">
                <span class="abo-admin-page__eyebrow-line" aria-hidden="true"></span>
                <span>SALES</span>
            </p>
            <h1 class="abo-admin-page__title">
                <span>OR</span><span class="abo-admin-orders__title-lime">DERS</span>
            </h1>
            <p class="abo-admin-page__date" id="abo-admin-today-date">Loading date…</p>
        </div>

        <div class="abo-admin-page__head-actions">
            <button type="button" class="abo-admin-btn abo-admin-btn--ghost"
                    data-abo-admin-orders-action="export">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M12 21V9m0 0-4 4m4-4 4 4M5 3h14"
                          stroke="currentColor" stroke-width="1.6" fill="none"
                          stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                EXPORT CSV
            </button>
        </div>
    </header>

    {{-- ─── Status tabs ─────────────────────────────────────────── --}}
    <nav class="abo-admin-orders__tabs"
         role="tablist"
         aria-label="Order status"
         data-abo-admin-orders-tabs>
        <button type="button" role="tab" aria-selected="true"  data-abo-admin-orders-tab="all"         class="is-active">All <span data-abo-admin-orders-tab-count="all">0</span></button>
        <button type="button" role="tab" aria-selected="false" data-abo-admin-orders-tab="new"        >New <span data-abo-admin-orders-tab-count="new">0</span></button>
        <button type="button" role="tab" aria-selected="false" data-abo-admin-orders-tab="processing" >Processing <span data-abo-admin-orders-tab-count="processing">0</span></button>
        <button type="button" role="tab" aria-selected="false" data-abo-admin-orders-tab="shipped"    >Shipped <span data-abo-admin-orders-tab-count="shipped">0</span></button>
        <button type="button" role="tab" aria-selected="false" data-abo-admin-orders-tab="delivered"  >Delivered <span data-abo-admin-orders-tab-count="delivered">0</span></button>
        <button type="button" role="tab" aria-selected="false" data-abo-admin-orders-tab="cancelled"  >Cancelled <span data-abo-admin-orders-tab-count="cancelled">0</span></button>
        <button type="button" role="tab" aria-selected="false" data-abo-admin-orders-tab="returned"   >Returned <span data-abo-admin-orders-tab-count="returned">0</span></button>
    </nav>

    {{-- ─── Toolbar ─────────────────────────────────────────────── --}}
    <section class="abo-admin-card abo-admin-orders__toolbar" aria-label="Filter orders">
        @if ($customerFilter)
            {{-- The screen was narrowed to one customer — the link
                 from the customers screen. The chip says who, and the
                 cross takes the filter off. --}}
            <p class="abo-admin-orders__customer-filter">
                <span class="abo-admin-orders__customer-filter-label">CUSTOMER</span>
                <span class="abo-admin-orders__customer-filter-name">{{ $customerFilter['name'] }}</span>
                <a class="abo-admin-orders__customer-filter-clear"
                   href="{{ route('admin.orders.index') }}"
                   aria-label="Show all customers again">&times;</a>
            </p>
        @endif
        <div class="abo-admin-orders__filter-search">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" class="abo-admin-orders__filter-icon">
                <path d="M21 20.3 16.7 16a8 8 0 1 0-1.4 1.4L20.3 21 21 20.3zM4 10a6 6 0 1 1 12 0 6 6 0 0 1-12 0z"
                      fill="currentColor"/>
            </svg>
            <label class="sr-only" for="abo-admin-orders-search">Search orders</label>
            <input id="abo-admin-orders-search"
                   type="search"
                   placeholder="Search by order #, name or phone…"
                   autocomplete="off"
                   data-abo-admin-orders-search>
        </div>

        <label class="sr-only" for="abo-admin-orders-payment">Payment method</label>
        <select id="abo-admin-orders-payment" class="abo-admin-orders__select"
                data-abo-admin-orders-filter="payment">
            <option value="">All payment methods</option>
            @foreach ($paymentMethodLabels as $key => $label)
                <option value="{{ $key }}">{{ $label }}</option>
            @endforeach
        </select>

        <label class="sr-only" for="abo-admin-orders-governorate">Governorate</label>
        <select id="abo-admin-orders-governorate" class="abo-admin-orders__select"
                data-abo-admin-orders-filter="governorate">
            <option value="">All governorates</option>
            @foreach ($governorates as $g)
                <option value="{{ $g }}">{{ $g }}</option>
            @endforeach
        </select>

        <label class="sr-only" for="abo-admin-orders-date-from">From date</label>
        <input id="abo-admin-orders-date-from"
               type="date"
               class="abo-admin-orders__date"
               data-abo-admin-orders-filter="dateFrom"
               aria-label="From date">

        <label class="sr-only" for="abo-admin-orders-date-to">To date</label>
        <input id="abo-admin-orders-date-to"
               type="date"
               class="abo-admin-orders__date"
               data-abo-admin-orders-filter="dateTo"
               aria-label="To date">

        <button type="button" class="abo-admin-orders__clear" hidden
                data-abo-admin-orders-clear>
            Clear filters
        </button>
    </section>

    {{-- ─── Bulk actions bar (hidden until selection) ───────────── --}}
    <section class="abo-admin-orders__bulk"
             role="region"
             aria-label="Bulk actions"
             hidden
             data-abo-admin-orders-bulk>
        <p class="abo-admin-orders__bulk-count">
            <span class="abo-admin-orders__bulk-num" data-abo-admin-orders-bulk-count>0</span>
            selected
        </p>
        <div class="abo-admin-orders__bulk-actions">
            <label class="sr-only" for="abo-admin-orders-bulk-status">Change status</label>
            <select id="abo-admin-orders-bulk-status" class="abo-admin-orders__select"
                    data-abo-admin-orders-bulk-status>
                <option value="">Change status…</option>
                @foreach ($statusLabels as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
            <button type="button" class="abo-admin-orders__bulk-apply"
                    data-abo-admin-orders-bulk-action="apply">
                APPLY
            </button>
            <button type="button" class="abo-admin-orders__bulk-print"
                    data-abo-admin-orders-bulk-action="print">
                PRINT LABELS
            </button>
        </div>
    </section>

    {{-- ─── Orders table ────────────────────────────────────────── --}}
    <article class="abo-admin-card abo-admin-orders__table-card" aria-label="Orders">
        {{-- Live count — announced via aria-live=polite so screen
             readers hear the results count after a filter change. --}}
        <p class="sr-only" aria-live="polite" data-abo-admin-orders-result-count>0 orders</p>

        <div class="abo-admin-orders__wrap abo-admin-orders__table-wrap">
            <table class="abo-admin-orders__table abo-admin-orders__table--orders">
                <caption class="sr-only">Orders list — 38 sample orders across all statuses.</caption>
                <thead>
                    <tr>
                        <th scope="col" class="abo-admin-orders__th-check">
                            <label class="sr-only" for="abo-admin-orders-check-all">Select all rows</label>
                            <input id="abo-admin-orders-check-all"
                                   type="checkbox"
                                   aria-label="Select all orders on this page"
                                   data-abo-admin-orders-check-all>
                        </th>
                        <th scope="col">Order #</th>
                        <th scope="col" class="abo-admin-orders__th-sortable" data-abo-admin-orders-sort="date" aria-sort="none">
                            Date
                            <span class="abo-admin-orders__sort-arrow" aria-hidden="true">↕</span>
                        </th>
                        <th scope="col">Customer</th>
                        <th scope="col">Governorate</th>
                        <th scope="col">Items</th>
                        <th scope="col" class="abo-admin-orders__th-sortable" data-abo-admin-orders-sort="total" aria-sort="none">
                            Total
                            <span class="abo-admin-orders__sort-arrow" aria-hidden="true">↕</span>
                        </th>
                        <th scope="col">Payment</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="abo-admin-orders__th-actions"><span class="sr-only">View</span></th>
                    </tr>
                </thead>
                <tbody data-abo-admin-orders-tbody>
                    {{-- Rows + mobile cards are both injected by
                         public/admin/js/orders.js from the JSON
                         dataset below. No server-side rows here by
                         design — every cell is generated in JS so
                         the demo is fully data-driven. --}}
                </tbody>
            </table>

            {{-- Mobile stacked cards (rendered next to the table on
                 desktop, hidden). The same dataset is reused. --}}
            <ul class="abo-admin-orders__cards" data-abo-admin-orders-cards></ul>
        </div>

        {{-- States: skeleton / empty / no-results are mutually
             exclusive and toggled by orders.js. --}}
        <div class="abo-admin-orders__state" data-abo-admin-orders-state="empty" hidden>
            <p class="abo-admin-empty">No orders yet. They will appear here once customers start buying.</p>
        </div>
        <div class="abo-admin-orders__state" data-abo-admin-orders-state="noresults" hidden>
            <p class="abo-admin-empty">No results. Try clearing the filters above.</p>
            <button type="button" class="abo-admin-btn abo-admin-btn--ghost"
                    data-abo-admin-orders-clear>
                CLEAR FILTERS
            </button>
        </div>
        <div class="abo-admin-orders__state" data-abo-admin-orders-state="loading" hidden>
            <div class="abo-admin-orders__skeleton" aria-hidden="true">
                @for ($i = 0; $i < 6; $i++)
                    <div class="abo-admin-orders__skeleton-row">
                        <span class="abo-admin-orders__skeleton-cell" style="width: 22px; height: 22px;"></span>
                        <span class="abo-admin-orders__skeleton-cell" style="width: 70px; height: 14px;"></span>
                        <span class="abo-admin-orders__skeleton-cell" style="width: 90px; height: 14px;"></span>
                        <span class="abo-admin-orders__skeleton-cell" style="width: 60%; height: 14px;"></span>
                        <span class="abo-admin-orders__skeleton-cell" style="width: 70px; height: 14px;"></span>
                        <span class="abo-admin-orders__skeleton-cell" style="width: 36px; height: 14px;"></span>
                        <span class="abo-admin-orders__skeleton-cell" style="width: 70px; height: 14px;"></span>
                        <span class="abo-admin-orders__skeleton-cell" style="width: 88px; height: 14px;"></span>
                        <span class="abo-admin-orders__skeleton-cell" style="width: 80px; height: 18px;"></span>
                    </div>
                @endfor
            </div>
        </div>
    </article>

    {{-- ─── Pagination footer ──────────────────────────────────── --}}
    <nav class="abo-admin-orders__pagination" aria-label="Pagination"
         data-abo-admin-orders-pagination>
        <p class="abo-admin-orders__pagination-info">
            <span data-abo-admin-orders-pagination-info>0 to 0 of 0 orders</span>
        </p>
        <ul class="abo-admin-orders__pagination-list" data-abo-admin-orders-pagination-list>
            {{-- Buttons injected by orders.js. --}}
        </ul>
    </nav>

    {{-- ─── Order detail slide-over ────────────────────────────── --}}
    <div class="abo-admin-orders__drawer"
         role="dialog"
         aria-modal="true"
         aria-labelledby="abo-admin-orders-drawer-title"
         hidden
         data-abo-admin-orders-drawer>
        <div class="abo-admin-orders__drawer-overlay" data-abo-admin-orders-drawer-close></div>
        <aside class="abo-admin-orders__drawer-panel" tabindex="-1">
            <header class="abo-admin-orders__drawer-head">
                <div class="abo-admin-orders__drawer-head-text">
                    <h2 class="abo-admin-orders__drawer-title" id="abo-admin-orders-drawer-title">
                        ORDER <span data-abo-admin-orders-drawer-id>#1042</span>
                    </h2>
                    <p class="abo-admin-orders__drawer-meta">
                        <span data-abo-admin-orders-drawer-status-wrap></span>
                        <span aria-hidden="true">·</span>
                        <span data-abo-admin-orders-drawer-date>—</span>
                    </p>
                </div>
                <div class="abo-admin-orders__drawer-head-actions">
                    <button type="button"
                            class="abo-admin-orders__drawer-print"
                            aria-label="Print invoice"
                            data-abo-admin-orders-drawer-print>
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M7 9V4h10v5M7 18H5a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M7 14h10v6H7z"
                                  stroke="currentColor" stroke-width="1.6" fill="none"
                                  stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>
                    <button type="button"
                            class="abo-admin-orders__drawer-close"
                            aria-label="Close order details"
                            data-abo-admin-orders-drawer-close>
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M6 6l12 12M18 6 6 18"
                                  stroke="currentColor" stroke-width="1.6"
                                  fill="none" stroke-linecap="round"/>
                        </svg>
                    </button>
                </div>
            </header>

            <div class="abo-admin-orders__drawer-body">
                {{-- Status block --}}
                <section class="abo-admin-orders__block" aria-label="Status">
                    <h3 class="abo-admin-orders__block-label">STATUS</h3>
                    <div class="abo-admin-orders__status-row">
                        <label class="sr-only" for="abo-admin-orders-drawer-status">Change status</label>
                        <select id="abo-admin-orders-drawer-status" class="abo-admin-orders__select"
                                data-abo-admin-orders-drawer-status>
                            @foreach ($statusLabels as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <button type="button" class="abo-admin-orders__status-apply"
                                data-abo-admin-orders-drawer-status-apply>
                            UPDATE STATUS
                        </button>
                    </div>

                    <h4 class="abo-admin-orders__block-sub">TIMELINE</h4>
                    <ol class="abo-admin-orders__timeline" data-abo-admin-orders-drawer-timeline></ol>
                </section>

                {{-- Customer block --}}
                <section class="abo-admin-orders__block" aria-label="Customer">
                    <h3 class="abo-admin-orders__block-label">CUSTOMER</h3>
                    <p class="abo-admin-orders__customer-name" data-abo-admin-orders-drawer-customer-name>—</p>
                    <p class="abo-admin-orders__customer-contact">
                        <a href="#" data-abo-admin-orders-drawer-phone class="abo-admin-orders__customer-link">—</a>
                        <span aria-hidden="true"> · </span>
                        <a href="#" data-abo-admin-orders-drawer-email class="abo-admin-orders__customer-link abo-admin-orders__customer-link--muted">—</a>
                    </p>

                    <h4 class="abo-admin-orders__block-sub">SHIPPING ADDRESS</h4>
                    <p class="abo-admin-orders__customer-address" data-abo-admin-orders-drawer-address>—</p>
                    <p class="abo-admin-orders__customer-delivery">
                        <span class="abo-admin-orders__customer-delivery-label">DELIVERY</span>
                        <span data-abo-admin-orders-drawer-delivery>—</span>
                    </p>
                </section>

                {{-- Items block --}}
                <section class="abo-admin-orders__block" aria-label="Items">
                    <h3 class="abo-admin-orders__block-label">ITEMS</h3>
                    <ul class="abo-admin-orders__items" data-abo-admin-orders-drawer-items></ul>
                </section>

                {{-- Payment block --}}
                <section class="abo-admin-orders__block" aria-label="Payment">
                    <h3 class="abo-admin-orders__block-label">PAYMENT</h3>
                    <p class="abo-admin-orders__payment-method" data-abo-admin-orders-drawer-payment>—</p>

                    <dl class="abo-admin-orders__totals">
                        <div class="abo-admin-orders__totals-row">
                            <dt>Subtotal</dt>
                            <dd data-abo-admin-orders-drawer-subtotal>—</dd>
                        </div>
                        <div class="abo-admin-orders__totals-row">
                            <dt>Shipping</dt>
                            <dd data-abo-admin-orders-drawer-shipping>—</dd>
                        </div>
                        <div class="abo-admin-orders__totals-row" data-abo-admin-orders-drawer-discount-row hidden>
                            <dt>Discount</dt>
                            <dd>
                                <span data-abo-admin-orders-drawer-discount>—</span>
                                <span class="abo-admin-orders__coupon" data-abo-admin-orders-drawer-coupon></span>
                            </dd>
                        </div>
                        <div class="abo-admin-orders__totals-row abo-admin-orders__totals-row--total">
                            <dt>Total</dt>
                            <dd data-abo-admin-orders-drawer-total>—</dd>
                        </div>
                    </dl>
                </section>

                {{-- Internal notes --}}
                <section class="abo-admin-orders__block" aria-label="Internal notes">
                    <h3 class="abo-admin-orders__block-label">INTERNAL NOTES</h3>
                    <label class="sr-only" for="abo-admin-orders-drawer-note-input">Internal note (not visible to the customer)</label>
                    <textarea id="abo-admin-orders-drawer-note-input"
                              class="abo-admin-orders__note-input"
                              placeholder="Internal note (not visible to the customer)"
                              rows="3"
                              data-abo-admin-orders-drawer-note-input></textarea>
                    <button type="button" class="abo-admin-btn abo-admin-btn--primary abo-admin-orders__note-add"
                            data-abo-admin-orders-drawer-note-add>
                        + ADD NOTE
                    </button>
                    <ul class="abo-admin-orders__notes" data-abo-admin-orders-drawer-notes></ul>
                </section>

                {{-- Danger --}}
                <section class="abo-admin-orders__block abo-admin-orders__block--danger" aria-label="Danger zone" data-abo-admin-orders-drawer-danger hidden>
                    <h3 class="abo-admin-orders__block-label">DANGER ZONE</h3>
                    <button type="button" class="abo-admin-orders__danger"
                            data-abo-admin-orders-drawer-cancel>
                        CANCEL ORDER
                    </button>
                </section>
            </div>

            <footer class="abo-admin-orders__drawer-foot">
                <button type="button" class="abo-admin-orders__drawer-prev"
                        data-abo-admin-orders-drawer-prev
                        aria-label="Previous order">
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="M15 6 9 12l6 6" stroke="currentColor" stroke-width="1.6"
                              fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    Previous
                </button>
                <span class="abo-admin-orders__drawer-pos" data-abo-admin-orders-drawer-pos>—</span>
                <button type="button" class="abo-admin-orders__drawer-next"
                        data-abo-admin-orders-drawer-next
                        aria-label="Next order">
                    Next
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="M9 6l6 6-6 6" stroke="currentColor" stroke-width="1.6"
                              fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
            </footer>
        </aside>
    </div>

    {{-- ─── Cancel confirmation modal (single shared instance) ──── --}}
    <div class="abo-admin-orders__modal"
         role="dialog"
         aria-modal="true"
         aria-labelledby="abo-admin-orders-modal-title"
         hidden
         data-abo-admin-orders-modal>
        <div class="abo-admin-orders__modal-backdrop" data-abo-admin-orders-modal-close></div>
        <div class="abo-admin-orders__modal-card">
            <h2 class="abo-admin-orders__modal-title" id="abo-admin-orders-modal-title">CANCEL ORDER?</h2>
            <p class="abo-admin-orders__modal-body"
               data-abo-admin-orders-modal-body>This will mark the order as cancelled. This action cannot be undone.</p>
            <div class="abo-admin-orders__modal-actions">
                <button type="button" class="abo-admin-btn abo-admin-btn--ghost"
                        data-abo-admin-orders-modal-close>KEEP ORDER</button>
                <button type="button" class="abo-admin-orders__modal-confirm"
                        data-abo-admin-orders-modal-confirm>CANCEL ORDER</button>
            </div>
        </div>
    </div>

    {{-- ─── Single shared toast ────────────────────────────────── --}}
    <div class="abo-admin-orders__toast" role="status" aria-live="polite" hidden
         data-abo-admin-orders-toast>
        <span class="abo-admin-orders__toast-dot" aria-hidden="true"></span>
        <span data-abo-admin-orders-toast-text>Saved (demo)</span>
    </div>

    {{-- The dataset is built by Admin\OrderService from the orders
         table, serialised here, and consumed by
         public/admin/js/orders.js. --}}
    <script type="application/json" id="abo-admin-orders-data">{!! $ordersJson !!}</script>

    {{-- The customer the screen is narrowed to, when it was opened
         with ?customer=<id>. orders.js starts with that filter
         applied. --}}
    <script type="application/json" id="abo-admin-orders-customer-filter">@json($customerFilter)</script>

    {{-- The three mutation endpoints the page calls back into. Each
         carries an __ORDER__ placeholder that orders.js swaps for the
         order number it is acting on. --}}
    <script type="application/json" id="abo-admin-orders-routes">@json($routes)</script>
@endsection

@push('head')
    {{-- Page-local stylesheet. Loaded only on the orders page. --}}
    <link rel="stylesheet" href="{{ asset('admin/css/orders.css') }}">
@endpush

@push('scripts')
    {{-- Page-local script. Loaded only on the orders page. --}}
    <script src="{{ asset('admin/js/orders.js') }}" defer></script>
@endpush

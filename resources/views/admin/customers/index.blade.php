{{--
    Admin · Customers list — wired to the database.

    Extends the existing admin layout. The customers, their addresses,
    their orders and the figures above the list are built by
    Admin\CustomerService and handed to this view; the view only
    serialises them for public/admin/js/customers.js, which renders the
    table, the segment tabs, the slide-over, the modal and the toast
    from that one payload.

    Page sections (top to bottom):
      1. Page header      — SALES eyebrow, "CUS" + "TOMERS" title,
                            EXPORT CSV button on the right
      2. KPI cards        — 4 small cards: total, new this month,
                            returning %, average order value
      3. Segment tabs     — All / New / Returning / VIP / No orders /
                            Blocked with counts (lime active border)
      4. Toolbar          — search, Governorate select, Sort by
                            select, Clear filters
      5. Customers table  — initials + name + email, phone, governorate,
                            orders, total spent, last order, segment,
                            View icon button. Mobile: stacked cards.
      6. Pagination       — 10 rows per page, prev/next + page buttons
      7. Customer detail  — header (initials, name, badge, since),
        slide-over          contact (phone/email/promotions), stats,
                            addresses, order history, tags, internal
                            note, danger, prev/next footer
      8. Block modal      — single shared instance (Block / Unblock)
                            with the reason the block is given
      9. Toast            — single shared instance

    Reuses the brand tokens (--abo-lime, --abo-bg, --abo-admin-panel,
    --abo-admin-coral, …), the page-header / eyebrow / title classes,
    the .abo-admin-kpis / .abo-admin-kpi stat cards, .abo-admin-card
    base, and .abo-admin-empty. All customers-specific classes are
    prefixed `abo-admin-customers-`.
--}}

@extends('admin.layouts.app')

@section('title', 'Customers · Sales')
@section('description', 'ABO — admin customers list: segment tabs, filters, customer detail slide-over, block action and order history.')

@php
    // The payload is built by Admin\CustomerService, which reads the
    // customers, their addresses, their orders and the figures that
    // belong to them out of the database. The view only serialises
    // what it is given.
    //
    // The segment of a customer is decided on the server too — the
    // browser reads it, it never derives it. There is one definition
    // of "spend" and of "VIP" in the application, and it lives in
    // App\Services\CustomerMetricsService.

    $customersJson = json_encode($customers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $routesJson   = json_encode($routes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $totalCustomers = (int) $stats['total'];
    $newThisMonth   = (int) $stats['new'];
    $returningCount = (int) $stats['returning'];
    $returningPct   = (int) $stats['returning_percentage'];
    $avgOrderValue  = (int) $stats['average_order_value'];
    $deliveredSpend = (float) $stats['delivered_spend'];
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
                <span>CUS</span><span class="abo-admin-customers__title-lime">TOMERS</span>
            </h1>
            <p class="abo-admin-page__date" id="abo-admin-today-date">Loading date…</p>
        </div>

        <div class="abo-admin-page__head-actions">
            <a href="{{ route('admin.customers.export') }}"
               class="abo-admin-btn abo-admin-btn--ghost"
               data-abo-admin-customers-action="export">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M12 21V9m0 0-4 4m4-4 4 4M5 3h14"
                          stroke="currentColor" stroke-width="1.6" fill="none"
                          stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                EXPORT CSV
            </a>
        </div>
    </header>

    {{-- ─── Stat cards ──────────────────────────────────────────── --}}
    <section class="abo-admin-kpis" aria-label="Customer KPIs" data-abo-admin-customers-stats>
        <article class="abo-admin-kpi" data-abo-admin-kpi="total">
            <p class="abo-admin-kpi__label">TOTAL CUSTOMERS</p>
            <p class="abo-admin-kpi__value abo-admin-kpi__value--lime">{{ $totalCustomers }}</p>
            <p class="abo-admin-kpi__delta">All registered accounts</p>
        </article>

        <article class="abo-admin-kpi" data-abo-admin-kpi="new">
            <p class="abo-admin-kpi__label">NEW THIS MONTH</p>
            <p class="abo-admin-kpi__value abo-admin-kpi__value--olive">{{ $newThisMonth }}</p>
            <p class="abo-admin-kpi__delta">Signed up in the last 30 days</p>
        </article>

        <article class="abo-admin-kpi" data-abo-admin-kpi="returning">
            <p class="abo-admin-kpi__label">RETURNING</p>
            <p class="abo-admin-kpi__value">{{ $returningPct }}%</p>
            <p class="abo-admin-kpi__delta">{{ $returningCount }} of {{ $totalCustomers }} customers</p>
        </article>

        <article class="abo-admin-kpi" data-abo-admin-kpi="aov">
            <p class="abo-admin-kpi__label">AVG ORDER VALUE</p>
            <p class="abo-admin-kpi__value">EGP {{ number_format($avgOrderValue, 0, '.', ',') }}</p>
            <p class="abo-admin-kpi__delta">Across {{ $stats['delivered_orders'] }} delivered orders</p>
        </article>
    </section>

    {{-- ─── Segment tabs ────────────────────────────────────────── --}}
    <nav class="abo-admin-customers__tabs"
         role="tablist"
         aria-label="Customer segments"
         data-abo-admin-customers-tabs>
        <button type="button" role="tab" aria-selected="true"  data-abo-admin-customers-tab="all"        class="is-active">All <span data-abo-admin-customers-tab-count="all">0</span></button>
        <button type="button" role="tab" aria-selected="false" data-abo-admin-customers-tab="new"       >New <span data-abo-admin-customers-tab-count="new">0</span></button>
        <button type="button" role="tab" aria-selected="false" data-abo-admin-customers-tab="returning" >Returning <span data-abo-admin-customers-tab-count="returning">0</span></button>
        <button type="button" role="tab" aria-selected="false" data-abo-admin-customers-tab="vip"       >VIP <span data-abo-admin-customers-tab-count="vip">0</span></button>
        <button type="button" role="tab" aria-selected="false" data-abo-admin-customers-tab="noorders"  >No orders <span data-abo-admin-customers-tab-count="noorders">0</span></button>
        <button type="button" role="tab" aria-selected="false" data-abo-admin-customers-tab="blocked"   >Blocked <span data-abo-admin-customers-tab-count="blocked">0</span></button>
    </nav>

    {{-- ─── Toolbar ─────────────────────────────────────────────── --}}
    <section class="abo-admin-card abo-admin-customers__toolbar" aria-label="Filter customers">
        <div class="abo-admin-customers__filter-search">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" class="abo-admin-customers__filter-icon">
                <path d="M21 20.3 16.7 16a8 8 0 1 0-1.4 1.4L20.3 21 21 20.3zM4 10a6 6 0 1 1 12 0 6 6 0 0 1-12 0z"
                      fill="currentColor"/>
            </svg>
            <label class="sr-only" for="abo-admin-customers-search">Search customers</label>
            <input id="abo-admin-customers-search"
                   type="search"
                   placeholder="Search by name, email or phone…"
                   autocomplete="off"
                   data-abo-admin-customers-search>
        </div>

        <label class="sr-only" for="abo-admin-customers-governorate">Governorate</label>
        <select id="abo-admin-customers-governorate" class="abo-admin-customers__select"
                data-abo-admin-customers-filter="governorate">
            <option value="">All governorates</option>
            @foreach ($governorates as $g)
                <option value="{{ $g }}">{{ $g }}</option>
            @endforeach
        </select>

        <label class="sr-only" for="abo-admin-customers-sort">Sort by</label>
        <select id="abo-admin-customers-sort" class="abo-admin-customers__select"
                data-abo-admin-customers-sort>
            <option value="newest">Sort: Newest</option>
            <option value="orders-desc">Sort: Most orders</option>
            <option value="spend-desc">Sort: Highest spend</option>
            <option value="name-asc">Sort: Name A to Z</option>
        </select>

        <button type="button" class="abo-admin-customers__clear" hidden
                data-abo-admin-customers-clear>
            Clear filters
        </button>
    </section>

    {{-- ─── Customers table ─────────────────────────────────────── --}}
    <article class="abo-admin-card abo-admin-customers__table-card" aria-label="Customers">
        {{-- Live count — announced via aria-live=polite so screen
             readers hear the results count after a filter change. --}}
        <p class="sr-only" aria-live="polite" data-abo-admin-customers-result-count>0 customers</p>

        <div class="abo-admin-customers__wrap abo-admin-customers__table-wrap">
            <table class="abo-admin-customers__table">
                <caption class="sr-only">Customers list — every account in the store.</caption>
                <thead>
                    <tr>
                        <th scope="col">Customer</th>
                        <th scope="col">Phone</th>
                        <th scope="col">Governorate</th>
                        <th scope="col" class="abo-admin-customers__th-sortable" data-abo-admin-customers-sort-col="orders" aria-sort="none">
                            Orders
                            <span class="abo-admin-customers__sort-arrow" aria-hidden="true">↕</span>
                        </th>
                        <th scope="col" class="abo-admin-customers__th-sortable" data-abo-admin-customers-sort-col="spend" aria-sort="none">
                            Total spent
                            <span class="abo-admin-customers__sort-arrow" aria-hidden="true">↕</span>
                        </th>
                        <th scope="col">Last order</th>
                        <th scope="col">Segment</th>
                        <th scope="col" class="abo-admin-customers__th-actions"><span class="sr-only">View</span></th>
                    </tr>
                </thead>
                <tbody data-abo-admin-customers-tbody>
                    {{-- Rows + mobile cards are both injected by
                         public/admin/js/customers.js from the JSON
                         dataset below. No server-side rows here by
                         design — every cell is generated in JS so
                         the demo is fully data-driven. --}}
                </tbody>
            </table>

            {{-- Mobile stacked cards (rendered next to the table on
                 desktop, hidden). The same dataset is reused. --}}
            <ul class="abo-admin-customers__cards" data-abo-admin-customers-cards></ul>
        </div>

        {{-- States: skeleton / empty / no-results are mutually
             exclusive and toggled by customers.js. --}}
        <div class="abo-admin-customers__state" data-abo-admin-customers-state="empty" hidden>
            <p class="abo-admin-empty">No customers yet. They will appear here once people sign up.</p>
        </div>
        <div class="abo-admin-customers__state" data-abo-admin-customers-state="noresults" hidden>
            <p class="abo-admin-empty">No results. Try clearing the filters above.</p>
            <button type="button" class="abo-admin-btn abo-admin-btn--ghost"
                    data-abo-admin-customers-clear>
                CLEAR FILTERS
            </button>
        </div>
        <div class="abo-admin-customers__state" data-abo-admin-customers-state="loading" hidden>
            <div class="abo-admin-customers__skeleton" aria-hidden="true">
                @for ($i = 0; $i < 6; $i++)
                    <div class="abo-admin-customers__skeleton-row">
                        <span class="abo-admin-customers__skeleton-cell" style="width: 36px; height: 36px;"></span>
                        <span class="abo-admin-customers__skeleton-cell" style="width: 60%; height: 14px;"></span>
                        <span class="abo-admin-customers__skeleton-cell" style="width: 90px; height: 14px;"></span>
                        <span class="abo-admin-customers__skeleton-cell" style="width: 80px; height: 14px;"></span>
                        <span class="abo-admin-customers__skeleton-cell" style="width: 36px; height: 14px;"></span>
                        <span class="abo-admin-customers__skeleton-cell" style="width: 80px; height: 14px;"></span>
                        <span class="abo-admin-customers__skeleton-cell" style="width: 80px; height: 14px;"></span>
                        <span class="abo-admin-customers__skeleton-cell" style="width: 70px; height: 18px;"></span>
                    </div>
                @endfor
            </div>
        </div>
    </article>

    {{-- ─── Pagination footer ──────────────────────────────────── --}}
    <nav class="abo-admin-customers__pagination" aria-label="Pagination"
         data-abo-admin-customers-pagination>
        <p class="abo-admin-customers__pagination-info">
            <span data-abo-admin-customers-pagination-info>0 to 0 of 0 customers</span>
        </p>
        <ul class="abo-admin-customers__pagination-list" data-abo-admin-customers-pagination-list>
            {{-- Buttons injected by customers.js. --}}
        </ul>
    </nav>

    {{-- ─── Customer detail slide-over ─────────────────────────── --}}
    <div class="abo-admin-customers__drawer"
         role="dialog"
         aria-modal="true"
         aria-labelledby="abo-admin-customers-drawer-title"
         hidden
         data-abo-admin-customers-drawer>
        <div class="abo-admin-customers__drawer-overlay" data-abo-admin-customers-drawer-close></div>
        <aside class="abo-admin-customers__drawer-panel" tabindex="-1">
            <header class="abo-admin-customers__drawer-head">
                <div class="abo-admin-customers__drawer-head-text">
                    <div class="abo-admin-customers__drawer-head-row">
                        <span class="abo-admin-customers__avatar" data-abo-admin-customers-drawer-avatar>—</span>
                        <div class="abo-admin-customers__drawer-head-meta">
                            <h2 class="abo-admin-customers__drawer-title" id="abo-admin-customers-drawer-title" data-abo-admin-customers-drawer-name>—</h2>
                            <p class="abo-admin-customers__drawer-since">
                                <span data-abo-admin-customers-drawer-since>Customer since —</span>
                                <span data-abo-admin-customers-drawer-badge></span>
                            </p>
                        </div>
                    </div>
                </div>
                <div class="abo-admin-customers__drawer-head-actions">
                    <button type="button"
                            class="abo-admin-customers__drawer-close"
                            aria-label="Close customer details"
                            data-abo-admin-customers-drawer-close>
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M6 6l12 12M18 6 6 18"
                                  stroke="currentColor" stroke-width="1.6"
                                  fill="none" stroke-linecap="round"/>
                        </svg>
                    </button>
                </div>
            </header>

            <div class="abo-admin-customers__drawer-body">
                {{-- Contact block --}}
                <section class="abo-admin-customers__block" aria-label="Contact">
                    <h3 class="abo-admin-customers__block-label">CONTACT</h3>
                    <p class="abo-admin-customers__contact-row">
                        <a href="#" data-abo-admin-customers-drawer-phone class="abo-admin-customers__contact-link">—</a>
                    </p>
                    <p class="abo-admin-customers__contact-row">
                        <a href="#" data-abo-admin-customers-drawer-email class="abo-admin-customers__contact-link abo-admin-customers__contact-link--muted">—</a>
                    </p>
                    <p class="abo-admin-customers__lang-row">
                        <span class="abo-admin-customers__block-sub">PROMOTIONS</span>
                        <span class="abo-admin-customers__lang-pill" data-abo-admin-customers-drawer-promotions>—</span>
                    </p>
                </section>

                {{-- Stats block --}}
                <section class="abo-admin-customers__block" aria-label="Stats">
                    <h3 class="abo-admin-customers__block-label">STATS</h3>
                    <div class="abo-admin-customers__stats">
                        <div class="abo-admin-customers__stat">
                            <p class="abo-admin-customers__stat-label">ORDERS</p>
                            <p class="abo-admin-customers__stat-value" data-abo-admin-customers-drawer-orders>—</p>
                        </div>
                        <div class="abo-admin-customers__stat">
                            <p class="abo-admin-customers__stat-label">TOTAL SPENT</p>
                            <p class="abo-admin-customers__stat-value" data-abo-admin-customers-drawer-spent>—</p>
                        </div>
                        <div class="abo-admin-customers__stat">
                            <p class="abo-admin-customers__stat-label">AVG ORDER</p>
                            <p class="abo-admin-customers__stat-value" data-abo-admin-customers-drawer-avg>—</p>
                        </div>
                    </div>
                </section>

                {{-- Addresses block --}}
                <section class="abo-admin-customers__block" aria-label="Addresses">
                    <h3 class="abo-admin-customers__block-label">ADDRESSES</h3>
                    <ul class="abo-admin-customers__addresses" data-abo-admin-customers-drawer-addresses></ul>
                </section>

                {{-- Order history block --}}
                <section class="abo-admin-customers__block" aria-label="Order history">
                    <h3 class="abo-admin-customers__block-label">RECENT ORDERS</h3>
                    <ul class="abo-admin-customers__orders" data-abo-admin-customers-drawer-orders-list></ul>
                    <p class="abo-admin-customers__orders-empty" data-abo-admin-customers-drawer-orders-empty hidden>
                        No orders yet.
                    </p>
                </section>

                {{-- Tags block --}}
                <section class="abo-admin-customers__block" aria-label="Tags">
                    <h3 class="abo-admin-customers__block-label">TAGS</h3>
                    <ul class="abo-admin-customers__tags" data-abo-admin-customers-drawer-tags></ul>
                    <form class="abo-admin-customers__tag-form" data-abo-admin-customers-tag-form>
                        <label class="sr-only" for="abo-admin-customers-drawer-tag-input">Add a tag</label>
                        <input id="abo-admin-customers-drawer-tag-input"
                               class="abo-admin-customers__tag-input"
                               type="text"
                               placeholder="Add a tag (e.g. Wholesale)"
                               maxlength="40"
                               autocomplete="off"
                               data-abo-admin-customers-tag-input>
                    </form>
                </section>

                {{-- Internal note --}}
                <section class="abo-admin-customers__block" aria-label="Internal note">
                    <h3 class="abo-admin-customers__block-label">INTERNAL NOTE</h3>
                    <label class="sr-only" for="abo-admin-customers-drawer-note-input">Internal note (not visible to the customer)</label>
                    <textarea id="abo-admin-customers-drawer-note-input"
                              class="abo-admin-customers__note-input"
                              placeholder="Internal note (not visible to the customer)"
                              rows="3"
                              data-abo-admin-customers-drawer-note-input></textarea>
                    <button type="button" class="abo-admin-btn abo-admin-btn--primary abo-admin-customers__note-save"
                            data-abo-admin-customers-drawer-note-save>
                        SAVE NOTE
                    </button>
                </section>

                {{-- Danger zone --}}
                <section class="abo-admin-customers__block abo-admin-customers__block--danger" aria-label="Danger zone">
                    <h3 class="abo-admin-customers__block-label">DANGER ZONE</h3>
                    <button type="button" class="abo-admin-customers__danger"
                            data-abo-admin-customers-drawer-block>
                        <span data-abo-admin-customers-drawer-block-label>BLOCK CUSTOMER</span>
                    </button>
                    <p class="abo-admin-customers__danger-note">Customers can't be deleted here to keep order history intact.</p>
                </section>
            </div>

            <footer class="abo-admin-customers__drawer-foot">
                <button type="button" class="abo-admin-customers__drawer-prev"
                        data-abo-admin-customers-drawer-prev
                        aria-label="Previous customer">
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="M15 6 9 12l6 6" stroke="currentColor" stroke-width="1.6"
                              fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    Previous
                </button>
                <span class="abo-admin-customers__drawer-pos" data-abo-admin-customers-drawer-pos>—</span>
                <button type="button" class="abo-admin-customers__drawer-next"
                        data-abo-admin-customers-drawer-next
                        aria-label="Next customer">
                    Next
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="M9 6l6 6-6 6" stroke="currentColor" stroke-width="1.6"
                              fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
            </footer>
        </aside>
    </div>

    {{-- ─── Block / Unblock confirmation modal ─────────────────── --}}
    <div class="abo-admin-customers__modal"
         role="dialog"
         aria-modal="true"
         aria-labelledby="abo-admin-customers-modal-title"
         hidden
         data-abo-admin-customers-modal>
        <div class="abo-admin-customers__modal-backdrop" data-abo-admin-customers-modal-close></div>
        <div class="abo-admin-customers__modal-card">
            <h2 class="abo-admin-customers__modal-title" id="abo-admin-customers-modal-title">BLOCK CUSTOMER?</h2>
            <p class="abo-admin-customers__modal-body"
               data-abo-admin-customers-modal-body>A blocked customer can't place new orders.</p>
            <div class="abo-admin-customers__modal-field" hidden
                 data-abo-admin-customers-modal-reason-field>
                <label class="sr-only" for="abo-admin-customers-modal-reason">Why is the account being blocked?</label>
                <input id="abo-admin-customers-modal-reason"
                       type="text"
                       maxlength="200"
                       placeholder="Why is the account being blocked?"
                       data-abo-admin-customers-modal-reason>
            </div>
            <div class="abo-admin-customers__modal-actions">
                <button type="button" class="abo-admin-btn abo-admin-btn--ghost"
                        data-abo-admin-customers-modal-close>CANCEL</button>
                <button type="button" class="abo-admin-customers__modal-confirm"
                        data-abo-admin-customers-modal-confirm>BLOCK CUSTOMER</button>
            </div>
        </div>
    </div>

    {{-- ─── Single shared toast ────────────────────────────────── --}}
    <div class="abo-admin-customers__toast" role="status" aria-live="polite" hidden
         data-abo-admin-customers-toast>
        <span class="abo-admin-customers__toast-dot" aria-hidden="true"></span>
        <span data-abo-admin-customers-toast-text>Saved.</span>
    </div>

    {{-- The dataset is generated in PHP at the top of this view,
         serialised to JSON, and consumed by public/admin/js/customers.js. --}}
    <script type="application/json" id="abo-admin-customers-data">{!! $customersJson !!}</script>
    <script type="application/json" id="abo-admin-customers-routes">{!! $routesJson !!}</script>
@endsection

@push('head')
    {{-- Page-local stylesheet. Loaded only on the customers page. --}}
    <link rel="stylesheet" href="{{ asset('admin/css/customers.css') }}">
@endpush

@push('scripts')
    {{-- Page-local script. Loaded only on the customers page. --}}
    <script src="{{ asset('admin/js/customers.js') }}" defer></script>
@endpush

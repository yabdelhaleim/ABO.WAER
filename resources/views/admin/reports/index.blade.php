{{--
    Admin · Reports.

    Extends the existing admin layout. Every number on this screen is
    measured by App\Services\Admin\ReportService and handed to the
    browser in one payload: the series, the totals, the breakdowns and
    the top products. The browser draws that payload and asks the
    server for another range when the toolbar changes — it never
    invents a number.

    Page sections (top to bottom):
      1. Page header — INSIGHTS eyebrow, "RE" + "PORTS" title,
         "Export CSV" + "Export PDF" outlined buttons on the right.
      2. Toolbar     — date range segmented control (Today, 7 days,
                       30 days, This month, Custom + two date inputs)
                       + "Compare to previous period" toggle.
      3. Tabs        — Sales, Profit, Waste and returns, Top products.
                       Tab state is kept in the URL hash.
      4. KPI cards   — 4 cards, change per tab, each with a sparkline
                       and a delta vs previous period.
      5. Main chart  — tab-dependent SVG: bars + line, sales vs
                       profit, or stacked bars by reason.
      6. Second row  — 1.6fr / 1fr: per-tab content
                       (governorate/profit/waste + segmented bar).
      7. Top products tab — sortable table + search + category filter.
      8. Footer note — the range that is on screen + generated date.

    Conventions match every other admin page in this area:
      - Extends admin.layouts.app; the sidebar + topbar partials
        are inherited untouched.
      - Reuses the brand palette and every available shell class
        from public/admin/css/admin.css.
      - New reports-only classes are prefixed `abo-admin-reports-`
        and live in public/admin/css/reports.css.
--}}

@extends('admin.layouts.app')

@section('title', 'Reports · Analytics')
@section('description', 'ABO — analytics: sales, profit, waste and returns, top products.')

@section('content')
    {{-- ─── Page header ────────────────────────────────────────── --}}
    <header class="abo-admin-page__head">
        <div class="abo-admin-page__head-text">
            <p class="abo-admin-page__eyebrow">
                <span class="abo-admin-page__eyebrow-line" aria-hidden="true"></span>
                <span>INSIGHTS</span>
            </p>
            <h1 class="abo-admin-page__title abo-admin-reports__title">
                <span class="abo-admin-reports__title-white">RE</span><span class="abo-admin-reports__title-lime">PORTS</span>
            </h1>
            <p class="abo-admin-page__date" id="abo-admin-reports-generated">{{ $payload['range']['label'] }} — generated …</p>
        </div>

        <div class="abo-admin-page__head-actions">
            <button type="button" class="abo-admin-btn abo-admin-btn--ghost" data-abo-admin-reports-export="csv">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M12 3v12m0 0 4-4m-4 4-4-4M5 21h14" stroke="currentColor" stroke-width="1.6" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                EXPORT CSV
            </button>
            <button type="button" class="abo-admin-btn abo-admin-btn--ghost" data-abo-admin-reports-export="pdf">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M12 3v12m0 0 4-4m-4 4-4-4M5 21h14" stroke="currentColor" stroke-width="1.6" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                EXPORT PDF
            </button>
        </div>
    </header>

    {{-- ─── Toolbar (date range + compare toggle) ────────────────── --}}
    <section class="abo-admin-reports__toolbar" aria-label="Report filters">
        <div class="abo-admin-reports__toolbar-group">
            <span class="abo-admin-reports__toolbar-label">DATE RANGE</span>
            <div class="abo-admin-segmented" role="tablist" aria-label="Date range" data-abo-admin-reports-range>
                <button type="button" role="tab" aria-selected="false" data-range="today">Today</button>
                <button type="button" role="tab" aria-selected="true"  data-range="7d"    class="is-active">7 days</button>
                <button type="button" role="tab" aria-selected="false" data-range="30d"   >30 days</button>
                <button type="button" role="tab" aria-selected="false" data-range="month" >This month</button>
                <button type="button" role="tab" aria-selected="false" data-range="custom">Custom</button>
            </div>

            <div class="abo-admin-reports__custom" data-abo-admin-reports-custom hidden>
                <label class="abo-admin-reports__custom-field">
                    <span>FROM</span>
                    <input type="date" data-abo-admin-reports-from value="{{ $defaults['from'] }}">
                </label>
                <label class="abo-admin-reports__custom-field">
                    <span>TO</span>
                    <input type="date" data-abo-admin-reports-to value="{{ $defaults['to'] }}">
                </label>
            </div>
        </div>

        <label class="abo-admin-reports__toggle">
            <input type="checkbox" data-abo-admin-reports-compare>
            <span class="abo-admin-reports__toggle-track" aria-hidden="true"></span>
            <span class="abo-admin-reports__toggle-label">Compare to previous period</span>
        </label>
    </section>

    {{-- ─── Tabs ─────────────────────────────────────────────────── --}}
    <nav class="abo-admin-reports__tabs" role="tablist" aria-label="Report sections" data-abo-admin-reports-tabs>
        <button type="button" role="tab" aria-selected="true"  class="is-active" data-tab="sales">Sales</button>
        <button type="button" role="tab" aria-selected="false" data-tab="profit">Profit</button>
        <button type="button" role="tab" aria-selected="false" data-tab="waste">Waste and returns</button>
        <button type="button" role="tab" aria-selected="false" data-tab="top">Top products</button>
    </nav>

    {{-- ─── KPI cards (4) — content swapped per tab in JS ─────────── --}}
    <section class="abo-admin-kpis abo-admin-reports__kpis" aria-label="Key performance indicators" data-abo-admin-reports-kpis>
        {{-- Populated by reports.js. Skeletons render by default; once
             REPORTS_DATA is available the JS replaces them with KPI
             cards (reusing the same .abo-admin-kpi shell). --}}
        <div class="abo-admin-kpi abo-admin-reports__skeleton" aria-hidden="true">
            <div class="abo-admin-reports__sk-line abo-admin-reports__sk-line--label"></div>
            <div class="abo-admin-reports__sk-line abo-admin-reports__sk-line--value"></div>
            <div class="abo-admin-reports__sk-line abo-admin-reports__sk-line--delta"></div>
        </div>
        <div class="abo-admin-kpi abo-admin-reports__skeleton" aria-hidden="true">
            <div class="abo-admin-reports__sk-line abo-admin-reports__sk-line--label"></div>
            <div class="abo-admin-reports__sk-line abo-admin-reports__sk-line--value"></div>
            <div class="abo-admin-reports__sk-line abo-admin-reports__sk-line--delta"></div>
        </div>
        <div class="abo-admin-kpi abo-admin-reports__skeleton" aria-hidden="true">
            <div class="abo-admin-reports__sk-line abo-admin-reports__sk-line--label"></div>
            <div class="abo-admin-reports__sk-line abo-admin-reports__sk-line--value"></div>
            <div class="abo-admin-reports__sk-line abo-admin-reports__sk-line--delta"></div>
        </div>
        <div class="abo-admin-kpi abo-admin-reports__skeleton" aria-hidden="true">
            <div class="abo-admin-reports__sk-line abo-admin-reports__sk-line--label"></div>
            <div class="abo-admin-reports__sk-line abo-admin-reports__sk-line--value"></div>
            <div class="abo-admin-reports__sk-line abo-admin-reports__sk-line--delta"></div>
        </div>
    </section>

    {{-- ─── Main chart card ─────────────────────────────────────── --}}
    <section class="abo-admin-reports__chart" aria-label="Main chart" data-abo-admin-reports-main>
        <article class="abo-admin-card">
            <header class="abo-admin-card__head">
                <h2 class="abo-admin-card__title" data-abo-admin-reports-main-title>REVENUE BY DAY</h2>
                <ul class="abo-admin-chart__legend" aria-label="Legend" data-abo-admin-reports-main-legend>
                    <li><span class="abo-admin-chart__swatch abo-admin-reports__swatch--lime"></span>Revenue</li>
                    <li><span class="abo-admin-chart__swatch abo-admin-reports__swatch--line"></span>Orders</li>
                </ul>
            </header>
            <div class="abo-admin-chart__canvas abo-admin-reports__chart-canvas"
                 data-abo-admin-reports-main-chart
                 role="img"
                 aria-label="Bar chart of revenue per day for the selected period."
                 tabindex="0">
                {{-- SVG injected by reports.js (no chart library). --}}
            </div>
        </article>
    </section>

    {{-- ─── Second row (1.6fr / 1fr) ─────────────────────────────── --}}
    <section class="abo-admin-row" aria-label="Breakdowns" data-abo-admin-reports-breakdown>
        <article class="abo-admin-card" aria-labelledby="abo-admin-reports-breakdown1-title">
            <header class="abo-admin-card__head">
                <h2 class="abo-admin-card__title" id="abo-admin-reports-breakdown1-title" data-abo-admin-reports-breakdown1-title>SALES BY GOVERNORATE</h2>
                <p class="abo-admin-card__soft" data-abo-admin-reports-breakdown1-soft>Top 8</p>
            </header>
            <div class="abo-admin-reports__bars" data-abo-admin-reports-breakdown1>
                {{-- Populated by reports.js. --}}
            </div>
        </article>

        <article class="abo-admin-card" aria-labelledby="abo-admin-reports-breakdown2-title">
            <header class="abo-admin-card__head">
                <h2 class="abo-admin-card__title" id="abo-admin-reports-breakdown2-title" data-abo-admin-reports-breakdown2-title>ORDERS BY STATUS</h2>
                <p class="abo-admin-card__soft" data-abo-admin-reports-breakdown2-soft>All</p>
            </header>
            <div class="abo-admin-reports__status" data-abo-admin-reports-breakdown2>
                <div class="abo-admin-status__bar" data-abo-admin-reports-segbar role="img" aria-label="Horizontal segmented bar."></div>
                <ul class="abo-admin-status__legend" data-abo-admin-reports-seglegend></ul>
            </div>
        </article>
    </section>

    {{-- ─── Top products table (only shown on the Top products tab)  --}}
    <section class="abo-admin-reports__top" aria-label="Top products" data-abo-admin-reports-top hidden>
        <article class="abo-admin-card">
            <header class="abo-admin-card__head abo-admin-reports__top-head">
                <h2 class="abo-admin-card__title">TOP PRODUCTS</h2>
                <div class="abo-admin-reports__top-controls">
                    <label class="sr-only" for="abo-admin-reports-top-search">Search products</label>
                    <div class="abo-admin-reports__top-search">
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" class="abo-admin-reports__top-search-icon">
                            <path d="M21 20.3 16.7 16a8 8 0 1 0 1.4 1.4L20.3 21 21 20.3zM4 10a6 6 0 1 1 12 0 6 6 0 0 1-12 0z" fill="currentColor"/>
                        </svg>
                        <input id="abo-admin-reports-top-search" type="search" placeholder="Search products…" autocomplete="off" data-abo-admin-reports-top-search>
                    </div>
                    <label class="sr-only" for="abo-admin-reports-top-category">Filter by category</label>
                    <select class="abo-admin-reports__top-select" id="abo-admin-reports-top-category" data-abo-admin-reports-top-category>
                        <option value="">All categories</option>
                    </select>
                </div>
            </header>

            <div class="abo-admin-orders__wrap">
                <table class="abo-admin-orders__table abo-admin-reports__top-table" data-abo-admin-reports-top-table>
                    <thead>
                        <tr>
                            <th scope="col" data-sort="rank"   aria-sort="none">Rank</th>
                            <th scope="col" data-sort="name"   aria-sort="none">Product</th>
                            <th scope="col" data-sort="units"  aria-sort="none" class="abo-admin-reports__num">Units sold</th>
                            <th scope="col" data-sort="rev"    aria-sort="none" class="abo-admin-reports__num">Revenue</th>
                            <th scope="col" data-sort="profit" aria-sort="none" class="abo-admin-reports__num">Profit</th>
                            <th scope="col" data-sort="ret"    aria-sort="none" class="abo-admin-reports__num abo-admin-reports__col-return">Return rate</th>
                        </tr>
                    </thead>
                    <tbody data-abo-admin-reports-top-body>
                        {{-- Populated by reports.js. --}}
                    </tbody>
                </table>
            </div>
        </article>
    </section>

    {{-- ─── Footer note ─────────────────────────────────────────── --}}
    <footer class="abo-admin-reports__foot">
        <span class="abo-admin-reports__foot-pill" aria-hidden="true"></span>
        <span>{{ $payload['range']['label'] }} — generated <time data-abo-admin-reports-generated-time datetime="">{{ now()->toDateString() }}</time></span>
    </footer>

    {{-- ─── Page-local toast ─────────────────────────────────────── --}}
    <div class="abo-admin-reports__toast" role="status" aria-live="polite" hidden data-abo-admin-reports-toast>
        <span class="abo-admin-reports__toast-dot" aria-hidden="true"></span>
        <span data-abo-admin-reports-toast-text>Export ready</span>
    </div>
@endsection

@push('head')
    {{-- Page-local stylesheet. Loaded only on the reports page. --}}
    <link rel="stylesheet" href="{{ asset('admin/css/reports.css') }}">
@endpush

@push('scripts')
    {{-- The report the server measured, and the routes it answers on.
         public/admin/js/reports.js draws the payload and asks the
         server for another range when the toolbar changes. --}}
    <script type="application/json" data-abo-admin-reports-payload>{!! json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
    <script type="application/json" data-abo-admin-reports-routes>{!! json_encode($routes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>

    {{-- Page-local script. Loaded only on the reports page. --}}
    <script src="{{ asset('admin/js/reports.js') }}" defer></script>
@endpush

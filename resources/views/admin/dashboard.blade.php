{{--
    Admin dashboard.

    Page structure (top to bottom):
      1. Page header — OVERVIEW label, DASHBOARD title, date line,
         EXPORT REPORT + segmented control (Today / 7 days / 30 days)
      2. "Needs attention" strip — 3 cards with 2px coloured left border
      3. 4 KPI cards (sales, profit, orders, returns) — each with a
         small inline SVG sparkline at the bottom
      4. Row: Sales vs Profit SVG bar chart (1.6fr) + Orders by
         Status segmented horizontal bar (1fr)
      5. Row: Latest Orders table (1.6fr) + Low Stock list (1fr)

    Every metric surface — attention strip, KPI cards, both charts,
    latest orders and low stock — is produced by
    `Admin\DashboardController` via `App\Services\Admin\DashboardService`.
    The view renders the selected range server-side and then hands the
    identical payload to public/admin/js/admin.js, so switching the
    segmented control re-draws the same numbers in place instead of
    replacing the page with a differently-sourced view.

    Reuses the brand palette (var(--abo-lime), var(--abo-bg), …) and
    the brand font stack (var(--font-display), var(--font-sans)) from
    the global stylesheet; all admin-only styles live in
    public/admin/css/admin.css and use the abo-admin-* prefix.
--}}

@extends('admin.layouts.app')

@section('title', 'Dashboard')
@section('description', 'ABO — admin overview: sales, profit, orders, stock and fulfilment.')

@php
    // The range the control has selected. DashboardController normalises
    // the ?range= query string against DashboardService::RANGES, so it
    // is always one of today / 7d / 30d by the time it reaches here.
    $range = $range ?? '7d';

    // Bars are scaled against the tallest sales column in the series so
    // a quiet week fills the canvas and a busy one still fits. The floor
    // of 1.0 keeps an all-zero series from dividing by zero.
    $chartMax = max(
        1.0,
        max(array_map(fn (array $point): float => max($point['sales'], $point['profit']), $salesProfit))
    );
@endphp

@section('content')
    {{-- ─── Page header ────────────────────────────────────────── --}}
    <header class="abo-admin-page__head">
        <div class="abo-admin-page__head-text">
            <p class="abo-admin-page__eyebrow">
                <span class="abo-admin-page__eyebrow-line" aria-hidden="true"></span>
                <span>OVERVIEW</span>
            </p>
            <h1 class="abo-admin-page__title">DASHBOARD</h1>
            <p class="abo-admin-page__date" id="abo-admin-today-date">Loading date…</p>
        </div>

        <div class="abo-admin-page__head-actions">
            {{-- The range endpoint is handed over as a data attribute rather
     than hardcoded in admin.js, so the URL lives with the route that
     serves it. `{range}` is the placeholder admin.js substitutes. --}}
<div class="abo-admin-segmented" role="tablist" aria-label="Date range" data-abo-admin-range
     data-abo-admin-range-url="{{ route('admin.dashboard.range', ['range' => '__range__']) }}"
     data-abo-admin-range-active="{{ $range }}">
                @foreach (['today' => 'Today', '7d' => '7 days', '30d' => '30 days'] as $value => $label)
                    <button type="button"
                            role="tab"
                            data-range="{{ $value }}"
                            aria-selected="{{ $range === $value ? 'true' : 'false' }}"
                            @class(['is-active' => $range === $value])>{{ $label }}</button>
                @endforeach
            </div>
            <button type="button" class="abo-admin-btn abo-admin-btn--ghost">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 3v12m0 0 4-4m-4 4-4-4M5 21h14" stroke="currentColor" stroke-width="1.6" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>
                EXPORT REPORT
            </button>
        </div>
    </header>

    {{-- ─── Needs attention strip ───────────────────────────────── --}}
    <section class="abo-admin-attention" aria-label="Needs attention">
        @foreach ($attention as $card)
            <a class="abo-admin-attention__card abo-admin-attention__card--{{ $card['tone'] }}"
               href="{{ $card['href'] }}">
                <span class="abo-admin-attention__count">{{ $card['count'] }}</span>
                <span class="abo-admin-attention__label">{{ $card['label'] }}</span>
                <span class="abo-admin-attention__arrow" aria-hidden="true">→</span>
            </a>
        @endforeach
    </section>

    {{-- ─── KPI cards ───────────────────────────────────────────── --}}
    <section class="abo-admin-kpis" aria-label="Key performance indicators">
        @foreach ($kpiCards as $k)
            <article class="abo-admin-kpi" data-abo-admin-kpi="{{ $k['key'] }}">
                <p class="abo-admin-kpi__label" data-abo-admin-kpi-label>{{ $k['label'] }}</p>
                <p class="abo-admin-kpi__value abo-admin-kpi__value--{{ $k['tone'] }}" data-abo-admin-kpi-value>{{ $k['value'] }}</p>
                <p class="abo-admin-kpi__delta" data-abo-admin-kpi-delta>
                    @if (!empty($k['delta']))
                        <span class="abo-admin-kpi__delta-arrow" aria-hidden="true">▲</span>
                        <span data-abo-admin-kpi-delta-value>{{ $k['delta'] }}</span>
                    @else
                        <span data-abo-admin-kpi-delta-value>{{ $k['sub'] }}</span>
                    @endif
                </p>
                @php $points = $sparklines[$k['key']] ?? []; @endphp
                <svg class="abo-admin-kpi__spark" viewBox="0 0 100 28" preserveAspectRatio="none" aria-hidden="true" data-abo-admin-spark>
                    <polyline class="abo-admin-kpi__spark-line" points="
                        @foreach ($points as $i => $v){{ $i * (100 / max(count($points) - 1, 1)) }},{{ 28 - $v * 24 - 2 }}@endforeach
                    " fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </article>
        @endforeach
    </section>

    {{-- ─── Sales vs Profit + Orders by Status ───────────────────── --}}
    <section class="abo-admin-row">
        <article class="abo-admin-card abo-admin-chart" aria-labelledby="abo-admin-chart-title">
            <header class="abo-admin-card__head">
                <h2 class="abo-admin-card__title" id="abo-admin-chart-title">SALES VS PROFIT <span class="abo-admin-card__title-soft">(K EGP)</span></h2>
                <ul class="abo-admin-chart__legend" aria-label="Legend">
                    <li><span class="abo-admin-chart__swatch abo-admin-chart__swatch--sales"></span>Sales</li>
                    <li><span class="abo-admin-chart__swatch abo-admin-chart__swatch--profit"></span>Profit</li>
                </ul>
            </header>

            <div class="abo-admin-chart__canvas" data-abo-admin-chart="sales-profit"
                 role="img"
                 aria-label="Bar chart comparing sales and profit across the selected range, in thousands of EGP.">
                {{-- Server-side first paint of the current range.
                     admin.js replaces this group's contents when the
                     segmented control switches range, and re-draws with
                     the identical geometry — the loop below and
                     renderSalesProfitChart() are kept in step by the
                     data-abo-admin-chart-* attributes on each node. --}}
                <svg viewBox="0 0 700 220" preserveAspectRatio="none" data-abo-admin-chart-svg>
                    <g data-abo-admin-chart-series>
                        @foreach ($salesProfit as $i => $d)
                            @php
                                // Bars are laid out on a fixed 700-unit
                                // canvas: 30px of left gutter, then each
                                // point gets an equal share of what is
                                // left. The slot width is recomputed per
                                // point so 7 days and 30 hours both fill
                                // the canvas instead of overflowing it.
                                $count = max(count($salesProfit), 1);
                                $slot = (700 - 40 - 20) / $count;
                                $x = 30 + $i * $slot;
                                $salesH = ($d['sales'] / $chartMax) * 150;
                                $profitH = ($d['profit'] / $chartMax) * 150;
                                $barClass = $d['current'] ? 'abo-admin-chart__bar--current' : 'abo-admin-chart__bar--sales';
                            @endphp
                            <rect class="abo-admin-chart__bar {{ $barClass }}" data-abo-admin-chart-bar="sales"
                                  x="{{ round($x, 2) }}" y="{{ round(170 - $salesH, 2) }}"
                                  width="{{ round(max(min($slot - 6, 34), 1), 2) }}"
                                  height="{{ round($salesH, 2) }}" rx="2"/>
                            <rect class="abo-admin-chart__bar abo-admin-chart__bar--profit" data-abo-admin-chart-bar="profit"
                                  x="{{ round($x + $slot - 6, 2) }}" y="{{ round(170 - $profitH, 2) }}"
                                  width="{{ round(max(min($slot * 0.22, 8), 1), 2) }}"
                                  height="{{ round($profitH, 2) }}" rx="1"/>
                            <text class="abo-admin-chart__label" data-abo-admin-chart-label
                                  x="{{ round($x + $slot / 2 - 3, 2) }}" y="200" text-anchor="middle">{{ $d['day'] }}</text>
                            <text class="abo-admin-chart__value" data-abo-admin-chart-value
                                  x="{{ round($x + $slot / 2 - 3, 2) }}" y="{{ round(170 - $salesH - 6, 2) }}"
                                  text-anchor="middle">{{ $d['sales'] }}</text>
                        @endforeach
                    </g>
                </svg>
            </div>
        </article>

        <article class="abo-admin-card abo-admin-status" aria-labelledby="abo-admin-status-title">
            <header class="abo-admin-card__head">
                <h2 class="abo-admin-card__title" id="abo-admin-status-title">ORDERS BY STATUS</h2>
                <p class="abo-admin-card__soft" data-abo-admin-status-total>{{ $statusTotal }} orders</p>
            </header>

            {{-- `percent` comes precomputed from the service so the
                 segment widths and the legend counts can never disagree,
                 and the five bars always total 100%. --}}
            <div class="abo-admin-status__bar" role="img" data-abo-admin-status-bar
                 aria-label="Horizontal segmented bar showing how orders split across {{ collect($statusSegments)->pluck('label')->join(', ') }}.">
                @foreach ($statusSegments as $seg)
                    <span class="abo-admin-status__seg abo-admin-status__seg--{{ $seg['hue'] }}"
                          style="--seg: {{ $seg['percent'] }}%;"
                          title="{{ $seg['label'] }}: {{ $seg['count'] }}"></span>
                @endforeach
            </div>

            <ul class="abo-admin-status__legend" data-abo-admin-status-legend>
                @foreach ($statusSegments as $seg)
                    <li>
                        <span class="abo-admin-status__dot abo-admin-status__dot--{{ $seg['hue'] }}" aria-hidden="true"></span>
                        <span class="abo-admin-status__name">{{ $seg['label'] }}</span>
                        <span class="abo-admin-status__count" data-abo-admin-status-count>{{ $seg['count'] }}</span>
                    </li>
                @endforeach
            </ul>
        </article>
    </section>

    {{-- ─── Latest orders + Low stock ───────────────────────────── --}}
    <section class="abo-admin-row">
        <article class="abo-admin-card abo-admin-orders" aria-labelledby="abo-admin-orders-title">
            <header class="abo-admin-card__head">
                <h2 class="abo-admin-card__title" id="abo-admin-orders-title">LATEST ORDERS</h2>
                <a class="abo-admin-card__more" href="{{ route('admin.orders.index') }}">View all →</a>
            </header>

            @if ($ordersEmpty)
                <p class="abo-admin-empty">No orders yet.</p>
            @else
                <div class="abo-admin-orders__wrap">
                    <table class="abo-admin-orders__table">
                        <thead>
                            <tr>
                                <th scope="col">Order</th>
                                <th scope="col">Customer</th>
                                <th scope="col">Total</th>
                                <th scope="col">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($latestOrders as $o)
                                <tr>
                                    <td><a href="{{ $o['url'] }}" class="abo-admin-orders__id">{{ $o['id'] }}</a></td>
                                    <td>{{ $o['customer'] }}</td>
                                    <td>{{ $o['total'] }}</td>
                                    <td>
                                        <span class="abo-admin-pill abo-admin-pill--{{ strtolower($o['status']) }}">{{ $o['status'] }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </article>

        <article class="abo-admin-card abo-admin-stock" aria-labelledby="abo-admin-stock-title">
            <header class="abo-admin-card__head">
                <h2 class="abo-admin-card__title" id="abo-admin-stock-title">LOW STOCK</h2>
                <a class="abo-admin-card__more" href="{{ route('admin.products.index') }}">Restock →</a>
            </header>

            @if ($stockEmpty)
                <p class="abo-admin-empty">Stock levels look good.</p>
            @else
                <ul class="abo-admin-stock__list">
                    @foreach ($lowStock as $row)
                        @php
                            $pct = max(min(($row['stock'] / $row['max']) * 100, 100), 0);
                            $tone = $pct < 20 ? 'coral' : 'amber';
                        @endphp
                        <li class="abo-admin-stock__row">
                            <div class="abo-admin-stock__text">
                                <p class="abo-admin-stock__product">{{ $row['product'] }}</p>
                                <p class="abo-admin-stock__variant">{{ $row['variant'] }}</p>
                            </div>
                            <div class="abo-admin-stock__bar" aria-hidden="true">
                                <span class="abo-admin-stock__fill abo-admin-stock__fill--{{ $tone }}" style="--w: {{ $pct }}%;"></span>
                            </div>
                            <span class="abo-admin-stock__count">{{ $row['stock'] }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </article>
    </section>
@endsection
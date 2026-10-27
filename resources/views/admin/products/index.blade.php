{{--
    Admin · Products list.

    The rows come from the products table: Admin\ProductController
    serialises the catalog into window.__PRODUCTS__ and
    public/admin/js/products.js draws it. The list used to answer
    from a hard-coded array of fourteen sample products, so it showed
    products the store could not sell and every action on them was a
    no-op.

    Page sections (top to bottom):
      1. Page header  — CATALOG eyebrow, "PRO" + "DUCTS" title,
                        Export / + ADD PRODUCT buttons
      2. Stat cards   — All / Active / Archived / Low (clickable filter)
      3. Filter bar   — search, category, status, stock, sort
      4. Bulk bar     — visible when 1+ row selected
      5. Products table
      6. Pagination
      7. Delete-confirm modal (single shared instance)
      8. Toast (single shared instance)

    Classes are reused from the existing admin shell (abo-admin-*)
    where they fit; new product-specific classes are prefixed
    abo-admin-products-*.
--}}

@extends('admin.layouts.app')

@section('title', 'Products · Catalog')
@section('description', 'ABO — admin product catalog: list, filter, bulk actions and stock overview.')

@php
    // The catalog, the categories and the option lists come from
    // Admin\ProductController::index().

    // Categories for the filter (and any future table column).
    $allCategories = $categories ?? collect();

    $categoryOptions = collect([['value' => '', 'label' => 'All categories']])
        ->merge($allCategories->map(fn ($cat) => [
            'value' => (string) $cat->id,
            'label' => $cat->name,
        ]))
        ->values()
        ->all();

    // The same categories for the bulk "change category" picker.
    $categoriesJson = $allCategories->map(fn ($cat) => [
        'id' => (string) $cat->id,
        'name' => $cat->name,
    ])->values()->all();

    $statusOptions = [
        ['value' => '',         'label' => 'All statuses'],
        ['value' => 'active',   'label' => 'Active'],
        ['value' => 'archived', 'label' => 'Archived'],
    ];

    $stockOptions = [
        ['value' => '',         'label' => 'All stock'],
        ['value' => 'in',       'label' => 'In stock'],
        ['value' => 'low',      'label' => 'Low'],
        ['value' => 'out',      'label' => 'Out'],
    ];

    $sortOptions = [
        ['value' => 'newest',        'label' => 'Newest'],
        ['value' => 'price-asc',     'label' => 'Price: low to high'],
        ['value' => 'price-desc',    'label' => 'Price: high to low'],
        ['value' => 'stock-asc',     'label' => 'Stock: low to high'],
    ];

    $payload = $payload ?? collect();
@endphp

@section('content')
    {{-- Server-rendered payload. The front-end JS hydrates from this
         single JSON blob (replacing the previous static PRODUCTS array). --}}
    <script>
        window.__PRODUCTS__ = @json($payload ?? []);
        window.__PRODUCT_CATEGORIES__ = @json($categoriesJson ?? []);
    </script>

    {{-- ─── Page header ────────────────────────────────────────── --}}
    <header class="abo-admin-page__head">
        <div class="abo-admin-page__head-text">
            <p class="abo-admin-page__eyebrow">
                <span class="abo-admin-page__eyebrow-line" aria-hidden="true"></span>
                <span>CATALOG</span>
            </p>
            <h1 class="abo-admin-page__title">
                <span>PRO</span><span class="abo-admin-products__title-lime">DUCTS</span>
            </h1>
            <p class="abo-admin-page__date" id="abo-admin-today-date">Loading date…</p>
        </div>

        <div class="abo-admin-page__head-actions">
            <a href="{{ route('admin.products.export') }}"
               class="abo-admin-btn abo-admin-btn--ghost"
               data-abo-admin-products-action="export">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M12 21V9m0 0-4 4m4-4 4 4M5 3h14"
                          stroke="currentColor" stroke-width="1.6" fill="none"
                          stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                EXPORT
            </a>
            <a href="{{ route('admin.products.create') }}"
               class="abo-admin-btn abo-admin-btn--primary">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="1.8"
                          fill="none" stroke-linecap="round"/>
                </svg>
                + ADD PRODUCT
            </a>
        </div>
    </header>

    {{-- ─── Stat cards (clickable filters) ─────────────────────── --}}
    <section class="abo-admin-attention abo-admin-products__stats"
             aria-label="Catalog summary"
             data-abo-admin-products-stats>
        <button type="button" class="abo-admin-attention__card abo-admin-attention__card--lime"
                data-abo-admin-products-stat="all">
            <span class="abo-admin-attention__count" data-abo-admin-products-stat-count="all">0</span>
            <span class="abo-admin-attention__label">All</span>
            <span class="abo-admin-attention__arrow" aria-hidden="true">→</span>
        </button>
        <button type="button" class="abo-admin-attention__card"
                data-abo-admin-products-stat="active">
            <span class="abo-admin-attention__count" data-abo-admin-products-stat-count="active">0</span>
            <span class="abo-admin-attention__label">Active</span>
            <span class="abo-admin-attention__arrow" aria-hidden="true">→</span>
        </button>
        <button type="button" class="abo-admin-attention__card"
                data-abo-admin-products-stat="archived">
            <span class="abo-admin-attention__count" data-abo-admin-products-stat-count="archived">0</span>
            <span class="abo-admin-attention__label">Archived</span>
            <span class="abo-admin-attention__arrow" aria-hidden="true">→</span>
        </button>
        <button type="button" class="abo-admin-attention__card abo-admin-attention__card--coral"
                data-abo-admin-products-stat="low">
            <span class="abo-admin-attention__count" data-abo-admin-products-stat-count="low">0</span>
            <span class="abo-admin-attention__label">Low / out of stock</span>
            <span class="abo-admin-attention__arrow" aria-hidden="true">→</span>
        </button>
    </section>

    {{-- ─── Filter bar ─────────────────────────────────────────── --}}
    <section class="abo-admin-card abo-admin-products__filters" aria-label="Filter products">
        <div class="abo-admin-products__filter-search">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" class="abo-admin-products__filter-icon">
                <path d="M21 20.3 16.7 16a8 8 0 1 0-1.4 1.4L20.3 21 21 20.3zM4 10a6 6 0 1 1 12 0 6 6 0 0 1-12 0z"
                      fill="currentColor"/>
            </svg>
            <label class="sr-only" for="abo-admin-products-search">Search products</label>
            <input id="abo-admin-products-search"
                   type="search"
                   placeholder="Search by name or SKU…"
                   autocomplete="off"
                   data-abo-admin-products-search>
        </div>

        <label class="sr-only" for="abo-admin-products-category">Category</label>
        <select id="abo-admin-products-category" class="abo-admin-products__select"
                data-abo-admin-products-filter="category">
            @foreach ($categoryOptions as $opt)
                <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
            @endforeach
        </select>

        <label class="sr-only" for="abo-admin-products-status">Status</label>
        <select id="abo-admin-products-status" class="abo-admin-products__select"
                data-abo-admin-products-filter="status">
            @foreach ($statusOptions as $opt)
                <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
            @endforeach
        </select>

        <label class="sr-only" for="abo-admin-products-stock">Stock</label>
        <select id="abo-admin-products-stock" class="abo-admin-products__select"
                data-abo-admin-products-filter="stock">
            @foreach ($stockOptions as $opt)
                <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
            @endforeach
        </select>

        <label class="sr-only" for="abo-admin-products-sort">Sort by</label>
        <select id="abo-admin-products-sort" class="abo-admin-products__select"
                data-abo-admin-products-sort>
            @foreach ($sortOptions as $opt)
                <option value="{{ $opt['value'] }}" @if ($loop->first) selected @endif>{{ $opt['label'] }}</option>
            @endforeach
        </select>
    </section>

    {{-- ─── Bulk actions bar (hidden until selection) ───────────── --}}
    <section class="abo-admin-products__bulk"
             role="region"
             aria-label="Bulk actions"
             hidden
             data-abo-admin-products-bulk>
        <p class="abo-admin-products__bulk-count">
            <span class="abo-admin-products__bulk-num" data-abo-admin-products-bulk-count>0</span>
            selected
        </p>
        <div class="abo-admin-products__bulk-actions">
            <button type="button" class="abo-admin-btn abo-admin-btn--ghost"
                    data-abo-admin-products-bulk-action="activate">
                ACTIVATE
            </button>
            <button type="button" class="abo-admin-btn abo-admin-btn--ghost"
                    data-abo-admin-products-bulk-action="archive">
                ARCHIVE
            </button>
            <button type="button" class="abo-admin-btn abo-admin-btn--ghost"
                    data-abo-admin-products-bulk-action="category">
                CHANGE CATEGORY
            </button>
            <button type="button" class="abo-admin-products__bulk-delete"
                    data-abo-admin-products-bulk-action="delete">
                DELETE
            </button>
        </div>
    </section>

    {{-- ─── Products table ─────────────────────────────────────── --}}
    <article class="abo-admin-card abo-admin-products__table-card" aria-label="Products">
        <div class="abo-admin-orders__wrap abo-admin-products__table-wrap">
            <table class="abo-admin-orders__table abo-admin-products__table">
                <thead>
                    <tr>
                        <th scope="col" class="abo-admin-products__th-check">
                            <label class="sr-only" for="abo-admin-products-check-all">Select all rows</label>
                            <input id="abo-admin-products-check-all"
                                   type="checkbox"
                                   data-abo-admin-products-check-all>
                        </th>
                        <th scope="col">PRODUCT</th>
                        <th scope="col">CATEGORY</th>
                        <th scope="col">PRICE</th>
                        <th scope="col">VARIANTS</th>
                        <th scope="col">STOCK</th>
                        <th scope="col" class="abo-admin-products__th-active">ACTIVE</th>
                        <th scope="col" class="abo-admin-products__th-actions"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody data-abo-admin-products-tbody>
                    {{-- Rows are injected by public/admin/js/products.js
                         from the PRODUCTS static array. No server-side
                         markup here, by design — every cell is generated
                         in JS so the demo is fully data-driven. --}}
                </tbody>
            </table>
        </div>

        {{-- States: skeleton / empty / no-results are mutually exclusive
             and toggled by products.js. Server-side only renders the
             no-data container as a fallback. --}}
        <div class="abo-admin-products__state" data-abo-admin-products-state="empty" hidden>
            <p class="abo-admin-empty">No products yet. Add your first product.</p>
            <a href="{{ route('admin.products.create') }}" class="abo-admin-btn abo-admin-btn--primary">
                + ADD PRODUCT
            </a>
        </div>
        <div class="abo-admin-products__state" data-abo-admin-products-state="noresults" hidden>
            <p class="abo-admin-empty">No results. Try clearing the filters above.</p>
        </div>
        <div class="abo-admin-products__state" data-abo-admin-products-state="loading" hidden>
            <div class="abo-admin-products__skeleton" aria-hidden="true">
                @for ($i = 0; $i < 6; $i++)
                    <div class="abo-admin-products__skeleton-row">
                        <span class="abo-admin-products__skeleton-cell" style="width: 22px; height: 22px;"></span>
                        <span class="abo-admin-products__skeleton-cell" style="width: 44%; height: 14px;"></span>
                        <span class="abo-admin-products__skeleton-cell" style="width: 80px; height: 14px;"></span>
                        <span class="abo-admin-products__skeleton-cell" style="width: 60px; height: 14px;"></span>
                        <span class="abo-admin-products__skeleton-cell" style="width: 90px; height: 14px;"></span>
                        <span class="abo-admin-products__skeleton-cell" style="width: 70px; height: 14px;"></span>
                    </div>
                @endfor
            </div>
        </div>
    </article>

    {{-- ─── Pagination footer ──────────────────────────────────── --}}
    <nav class="abo-admin-products__pagination" aria-label="Pagination"
         data-abo-admin-products-pagination>
        <p class="abo-admin-products__pagination-info">
            <span data-abo-admin-products-pagination-info>0 to 0 of 0</span>
        </p>
        <ul class="abo-admin-products__pagination-list">
            {{-- Buttons injected by products.js. --}}
        </ul>
    </nav>

    {{-- ─── Single shared modal + toast containers ──────────────── --}}
    <div class="abo-admin-products__modal" hidden
         role="dialog"
         aria-modal="true"
         aria-labelledby="abo-admin-products-modal-title"
         data-abo-admin-products-modal>
        <div class="abo-admin-products__modal-backdrop" data-abo-admin-products-modal-close></div>
        <div class="abo-admin-products__modal-card">
            <h2 class="abo-admin-products__modal-title" id="abo-admin-products-modal-title">DELETE PRODUCT</h2>
            <p class="abo-admin-products__modal-body"
               data-abo-admin-products-modal-body>Are you sure? This action cannot be undone.</p>
            <div class="abo-admin-products__modal-actions">
                <button type="button" class="abo-admin-btn abo-admin-btn--ghost"
                        data-abo-admin-products-modal-close>CANCEL</button>
                <button type="button" class="abo-admin-products__modal-confirm"
                        data-abo-admin-products-modal-confirm>DELETE</button>
            </div>
        </div>
    </div>

    <div class="abo-admin-products__toast" role="status" aria-live="polite" hidden
         data-abo-admin-products-toast>
        <span class="abo-admin-products__toast-dot" aria-hidden="true"></span>
        <span class="abo-admin-products__toast-text" data-abo-admin-products-toast-text>Saved</span>
    </div>
@endsection

@push('head')
    {{-- Page-local stylesheet. Loaded only on admin products pages. --}}
    <link rel="stylesheet" href="{{ asset('admin/css/products.css') }}">
@endpush

@push('scripts')
    {{-- Page-local script. Loaded only on admin products pages. --}}
    <script src="{{ asset('admin/js/products.js') }}" defer></script>
@endpush

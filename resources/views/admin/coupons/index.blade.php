{{--
    Admin · Coupons.

    Extends the existing admin layout. The page is wired to
    Admin\CouponController::index, which builds the JSON payload via
    Admin\CouponService::getCouponsPayload() and injects it into the
    @push('scripts') block below as window.__COUPONS_DATA__. Every row,
    stat card and filter is hydrated by public/admin/js/coupons.js from
    that payload.

    Page sections (top to bottom):
      1. Page header    — MARKETING eyebrow, "COU" + "PONS" title,
                           lime "+ ADD COUPON" button.
      2. Stat cards     — 3 small cards: Active, Redemptions,
                           Discount given (computed from the payload
                           by the JS).
      3. Status tabs    — All, Active, Scheduled, Expired, Disabled.
      4. Two columns    — Left: search + type select + coupons list.
                           Right: edit panel (slide-over on tablet/mobile).
      5. Modal + toast  — Delete confirm + single shared toast.

    Reuses the existing admin shell classes (abo-admin-*) — new
    coupon-specific classes are prefixed abo-admin-coupons-*.
--}}

@extends('admin.layouts.app')

@section('title', 'Coupons · Marketing')
@section('description', 'ABO — admin coupon manager: list, filter, edit, delete, slide-over on tablet/mobile.')

@section('content')
    {{-- ─── Page header ────────────────────────────────────────── --}}
    <header class="abo-admin-page__head">
        <div class="abo-admin-page__head-text">
            <p class="abo-admin-page__eyebrow">
                <span class="abo-admin-page__eyebrow-line" aria-hidden="true"></span>
                <span>MARKETING</span>
            </p>
            <h1 class="abo-admin-page__title">
                <span>COU</span><span class="abo-admin-coupons__title-lime">PONS</span>
            </h1>
            <p class="abo-admin-page__date" id="abo-admin-coupons-today-date">Loading date…</p>
        </div>

        <div class="abo-admin-page__head-actions">
            <button type="button"
                    class="abo-admin-btn abo-admin-btn--primary"
                    data-abo-admin-coupons-add>
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M12 5v14M5 12h14"
                          stroke="currentColor" stroke-width="1.8"
                          fill="none" stroke-linecap="round"/>
                </svg>
                + ADD COUPON
            </button>
        </div>
    </header>

    {{-- ─── Stat cards ─────────────────────────────────────────── --}}
    <section class="abo-admin-attention abo-admin-coupons__stats"
             aria-label="Coupon summary"
             data-abo-admin-coupons-stats>
        <div class="abo-admin-attention__card abo-admin-attention__card--lime">
            <span class="abo-admin-attention__count"
                  data-abo-admin-coupons-stat="active">0</span>
            <span class="abo-admin-attention__label">Active coupons</span>
            <span class="abo-admin-attention__arrow" aria-hidden="true">→</span>
        </div>
        <div class="abo-admin-attention__card abo-admin-attention__card--lime">
            <span class="abo-admin-attention__count"
                  data-abo-admin-coupons-stat="redemptions">0</span>
            <span class="abo-admin-attention__label">Redemptions</span>
            <span class="abo-admin-attention__arrow" aria-hidden="true">→</span>
        </div>
        <div class="abo-admin-attention__card abo-admin-attention__card--lime">
            <span class="abo-admin-attention__count"
                  data-abo-admin-coupons-stat="discount">EGP 0</span>
            <span class="abo-admin-attention__label">Discount given</span>
            <span class="abo-admin-attention__arrow" aria-hidden="true">→</span>
        </div>
    </section>

    {{-- ─── Status tabs ────────────────────────────────────────── --}}
    <nav class="abo-admin-coupons__tabs"
         role="tablist"
         aria-label="Coupon status"
         data-abo-admin-coupons-tabs>
        <button type="button"
                class="abo-admin-coupons__tab is-active"
                role="tab"
                aria-selected="true"
                aria-controls="abo-admin-coupons-tab-panel"
                data-abo-admin-coupons-tab="all">
            All <span class="abo-admin-coupons__tab-num"
                      data-abo-admin-coupons-tab-count="all">0</span>
        </button>
        <button type="button"
                class="abo-admin-coupons__tab"
                role="tab"
                aria-selected="false"
                data-abo-admin-coupons-tab="active">
            Active <span class="abo-admin-coupons__tab-num"
                          data-abo-admin-coupons-tab-count="active">0</span>
        </button>
        <button type="button"
                class="abo-admin-coupons__tab"
                role="tab"
                aria-selected="false"
                data-abo-admin-coupons-tab="scheduled">
            Scheduled <span class="abo-admin-coupons__tab-num"
                            data-abo-admin-coupons-tab-count="scheduled">0</span>
        </button>
        <button type="button"
                class="abo-admin-coupons__tab"
                role="tab"
                aria-selected="false"
                data-abo-admin-coupons-tab="expired">
            Expired <span class="abo-admin-coupons__tab-num"
                          data-abo-admin-coupons-tab-count="expired">0</span>
        </button>
        <button type="button"
                class="abo-admin-coupons__tab"
                role="tab"
                aria-selected="false"
                data-abo-admin-coupons-tab="disabled">
            Disabled <span class="abo-admin-coupons__tab-num"
                            data-abo-admin-coupons-tab-count="disabled">0</span>
        </button>
    </nav>

    {{-- ─── Two columns: list + edit panel ─────────────────────── --}}
    <div class="abo-admin-coupons__layout"
         id="abo-admin-coupons-tab-panel"
         role="tabpanel"
         aria-label="Coupons"
         data-abo-admin-coupons>

        {{-- ───── LEFT: list ───────────────────────────────────── --}}
        <div class="abo-admin-coupons__col">

            {{-- Toolbar --}}
            <div class="abo-admin-card abo-admin-coupons__toolbar">
                <div class="abo-admin-coupons__search">
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"
                         class="abo-admin-coupons__search-icon">
                        <path d="M21 20.3 16.7 16a8 8 0 1 0-1.4 1.4L20.3 21 21 20.3zM4 10a6 6 0 1 1 12 0 6 6 0 0 1-12 0z"
                              fill="currentColor"/>
                    </svg>
                    <label class="sr-only" for="abo-admin-coupons-search">Search coupons</label>
                    <input id="abo-admin-coupons-search"
                           type="search"
                           placeholder="Search by code…"
                           autocomplete="off"
                           data-abo-admin-coupons-search>
                </div>

                <label class="sr-only" for="abo-admin-coupons-type">Coupon type</label>
                <select id="abo-admin-coupons-type"
                        class="abo-admin-coupons__select"
                        data-abo-admin-coupons-type>
                    <option value="">All types</option>
                    <option value="percentage">Percentage</option>
                    <option value="fixed">Fixed amount</option>
                    <option value="shipping">Free shipping</option>
                </select>
            </div>

            {{-- List card --}}
            <article class="abo-admin-card abo-admin-coupons__list-card"
                     aria-label="Coupons">
                <ul class="abo-admin-coupons__list"
                    role="listbox"
                    aria-label="Coupons list"
                    data-abo-admin-coupons-list>
                    {{-- Rows are injected by public/admin/js/coupons.js
                         from the COUPONS static array. No server-side
                         markup here. --}}
                </ul>

                <p class="abo-admin-coupons__helper"
                   data-abo-admin-coupons-helper>
                    Coupons are applied at checkout. Expired and disabled coupons stay here for your records.
                </p>

                {{-- Results count (live region for screen readers) --}}
                <p class="abo-admin-coupons__results"
                   aria-live="polite"
                   aria-atomic="true"
                   data-abo-admin-coupons-results></p>

                {{-- States: empty / no-results / loading skeleton.
                     Mutually exclusive and toggled by coupons.js. --}}
                <div class="abo-admin-coupons__state"
                     data-abo-admin-coupons-state="empty" hidden>
                    <p class="abo-admin-empty">No coupons yet. Create your first coupon.</p>
                    <button type="button"
                            class="abo-admin-btn abo-admin-btn--primary"
                            data-abo-admin-coupons-add-empty>+ ADD COUPON</button>
                </div>

                <div class="abo-admin-coupons__state"
                     data-abo-admin-coupons-state="noresults" hidden>
                    <p class="abo-admin-empty">No coupons match these filters.</p>
                    <button type="button"
                            class="abo-admin-btn abo-admin-btn--ghost"
                            data-abo-admin-coupons-clear-filters>CLEAR FILTERS</button>
                </div>

                <div class="abo-admin-coupons__state"
                     data-abo-admin-coupons-skeleton>
                    <div class="abo-admin-coupons__skeleton" aria-hidden="true">
                        @for ($i = 0; $i < 5; $i++)
                            <div class="abo-admin-coupons__skeleton-row">
                                <span class="abo-admin-coupons__skeleton-cell"
                                      style="width: 110px; height: 16px;"></span>
                                <span class="abo-admin-coupons__skeleton-cell"
                                      style="width: 64px; height: 16px;"></span>
                                <span class="abo-admin-coupons__skeleton-cell"
                                      style="width: 90px; height: 14px;"></span>
                                <span class="abo-admin-coupons__skeleton-cell"
                                      style="width: 140px; height: 14px;"></span>
                                <span class="abo-admin-coupons__skeleton-cell"
                                      style="width: 70px; height: 14px;"></span>
                                <span class="abo-admin-coupons__skeleton-cell"
                                      style="width: 28px; height: 14px;"></span>
                            </div>
                        @endfor
                    </div>
                </div>
            </article>
        </div>

        {{-- ───── RIGHT: edit panel ───────────────────────────── --}}
        <aside class="abo-admin-coupons__col abo-admin-coupons__panel"
               aria-label="Edit coupon"
               data-abo-admin-coupons-panel>
            <div class="abo-admin-coupons__panel-overlay"
                 hidden
                 data-abo-admin-coupons-panel-overlay
                 aria-hidden="true"></div>

            {{-- "No selection" placeholder --}}
            <div class="abo-admin-coupons__panel-empty"
                 data-abo-admin-coupons-panel-no-selection>
                <p style="margin:0 0 0.4rem; color: var(--abo-admin-text); font-weight: 600;">
                    No coupon selected.
                </p>
                <p style="margin:0;">Click a row in the list, or add a new coupon.</p>
            </div>

            {{-- Form (toggled by coupons.js) --}}
            <div data-abo-admin-coupons-panel-form hidden>

                <div class="abo-admin-coupons__panel-head">
                    <h2 class="abo-admin-coupons__panel-title">
                        <span data-abo-admin-coupons-panel-title-text>EDIT COUPON</span>
                        <span class="abo-admin-coupons__dirty-dot"
                              aria-label="Unsaved changes"
                              data-abo-admin-coupons-dirty-dot></span>
                    </h2>
                    <button type="button"
                            class="abo-admin-coupons__panel-close"
                            aria-label="Close edit panel"
                            data-abo-admin-coupons-panel-close>
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M6 6l12 12M18 6 6 18"
                                  stroke="currentColor" stroke-width="1.6"
                                  fill="none" stroke-linecap="round"/>
                        </svg>
                    </button>
                </div>

                <div class="abo-admin-coupons__panel-body">

                    {{-- Code (with Generate) --}}
                    <div class="abo-admin-coupons__field">
                        <label for="abo-admin-coupons-code">Code</label>
                        <label class="sr-only" for="abo-admin-coupons-code">Coupon code</label>
                        <div class="abo-admin-coupons__code-row">
                            <input id="abo-admin-coupons-code"
                                   type="text"
                                   placeholder="e.g. SAVE-4K9X"
                                   autocomplete="off"
                                   spellcheck="false"
                                   maxlength="20"
                                   data-abo-admin-coupons-code>
                            <button type="button"
                                    class="abo-admin-coupons__generate"
                                    data-abo-admin-coupons-generate>GENERATE</button>
                        </div>
                        <p class="abo-admin-coupons__field-hint">Customers type this at checkout.</p>
                        <p class="abo-admin-coupons__field-error"
                           data-abo-admin-coupons-code-error
                           hidden>Use 4 to 20 letters or digits</p>
                    </div>

                    {{-- Type (radio chips) --}}
                    <div class="abo-admin-coupons__field">
                        <span class="abo-admin-coupons__field-label">Type</span>
                        <div class="abo-admin-coupons__chips"
                             role="radiogroup"
                             aria-label="Coupon type"
                             data-abo-admin-coupons-type-chips>
                            <button type="button"
                                    class="abo-admin-coupons__chip is-active"
                                    role="radio"
                                    aria-checked="true"
                                    data-abo-admin-coupons-type-value="percentage">
                                PERCENTAGE
                            </button>
                            <button type="button"
                                    class="abo-admin-coupons__chip"
                                    role="radio"
                                    aria-checked="false"
                                    data-abo-admin-coupons-type-value="fixed">
                                FIXED AMOUNT
                            </button>
                            <button type="button"
                                    class="abo-admin-coupons__chip"
                                    role="radio"
                                    aria-checked="false"
                                    data-abo-admin-coupons-type-value="shipping">
                                FREE SHIPPING
                            </button>
                        </div>
                    </div>

                    {{-- Value --}}
                    <div class="abo-admin-coupons__field" data-abo-admin-coupons-value-field>
                        <label for="abo-admin-coupons-value">Value</label>
                        <label class="sr-only" for="abo-admin-coupons-value">Coupon value</label>
                        <div class="abo-admin-coupons__value-row">
                            <input id="abo-admin-coupons-value"
                                   type="number"
                                   min="1"
                                   max="100"
                                   step="1"
                                   placeholder="20"
                                   data-abo-admin-coupons-value>
                            <span class="abo-admin-coupons__value-suffix"
                                  data-abo-admin-coupons-value-suffix>%</span>
                        </div>
                        <p class="abo-admin-coupons__field-hint"
                           data-abo-admin-coupons-value-hint>Percentage off the order total.</p>
                    </div>

                    {{-- Max discount cap (percentage only) --}}
                    <div class="abo-admin-coupons__field"
                         data-abo-admin-coupons-cap-field>
                        <label for="abo-admin-coupons-cap">Max discount cap (EGP)</label>
                        <label class="sr-only" for="abo-admin-coupons-cap">Max discount cap</label>
                        <div class="abo-admin-coupons__value-row">
                            <span class="abo-admin-coupons__value-prefix">EGP</span>
                            <input id="abo-admin-coupons-cap"
                                   type="number"
                                   min="0"
                                   step="1"
                                   placeholder="—"
                                   data-abo-admin-coupons-cap>
                        </div>
                        <p class="abo-admin-coupons__field-hint">Leave empty for no cap.</p>
                    </div>

                    {{-- Minimum order --}}
                    <div class="abo-admin-coupons__field">
                        <label for="abo-admin-coupons-minimum">Minimum order (EGP)</label>
                        <label class="sr-only" for="abo-admin-coupons-minimum">Minimum order</label>
                        <div class="abo-admin-coupons__value-row">
                            <span class="abo-admin-coupons__value-prefix">EGP</span>
                            <input id="abo-admin-coupons-minimum"
                                   type="number"
                                   min="0"
                                   step="1"
                                   placeholder="—"
                                   data-abo-admin-coupons-minimum>
                        </div>
                        <p class="abo-admin-coupons__field-hint">Optional.</p>
                    </div>

                    {{-- Usage limits: total + per customer --}}
                    <div class="abo-admin-coupons__grid-2">
                        <div class="abo-admin-coupons__field">
                            <label for="abo-admin-coupons-uses-total">Total uses</label>
                            <label class="sr-only" for="abo-admin-coupons-uses-total">Total uses</label>
                            <input id="abo-admin-coupons-uses-total"
                                   type="number"
                                   min="0"
                                   step="1"
                                   placeholder="Unlimited"
                                   data-abo-admin-coupons-uses-total>
                            <p class="abo-admin-coupons__field-hint">Empty means unlimited.</p>
                        </div>
                        <div class="abo-admin-coupons__field">
                            <label for="abo-admin-coupons-uses-customer">Uses per customer</label>
                            <label class="sr-only" for="abo-admin-coupons-uses-customer">Uses per customer</label>
                            <input id="abo-admin-coupons-uses-customer"
                                   type="number"
                                   min="0"
                                   step="1"
                                   placeholder="Unlimited"
                                   data-abo-admin-coupons-uses-customer>
                            <p class="abo-admin-coupons__field-hint">Empty means unlimited.</p>
                        </div>
                    </div>

                    {{-- Validity --}}
                    <div class="abo-admin-coupons__grid-2">
                        <div class="abo-admin-coupons__field">
                            <label for="abo-admin-coupons-starts">Starts</label>
                            <label class="sr-only" for="abo-admin-coupons-starts">Start date</label>
                            <input id="abo-admin-coupons-starts"
                                   type="date"
                                   data-abo-admin-coupons-starts>
                        </div>
                        <div class="abo-admin-coupons__field">
                            <label for="abo-admin-coupons-ends">Ends</label>
                            <label class="sr-only" for="abo-admin-coupons-ends">End date</label>
                            <input id="abo-admin-coupons-ends"
                                   type="date"
                                   data-abo-admin-coupons-ends>
                            <p class="abo-admin-coupons__field-error"
                               data-abo-admin-coupons-date-error
                               hidden>End date must be after the start date</p>
                        </div>
                    </div>

                    {{-- The active switch. Every other control on this
                         panel maps to a column of the coupons table. --}}
                    <div class="abo-admin-coupons__switch">
                        <div>
                            <span class="abo-admin-coupons__switch-label">Active</span>
                            <p class="abo-admin-coupons__switch-hint">Inactive coupons are ignored at checkout.</p>
                        </div>
                        <label class="abo-admin-coupons__toggle"
                               aria-label="Active">
                            <input type="checkbox"
                                   checked
                                   aria-checked="true"
                                   data-abo-admin-coupons-active>
                            <span class="abo-admin-coupons__toggle-track"></span>
                            <span class="abo-admin-coupons__toggle-thumb"></span>
                        </label>
                    </div>

                    {{-- Action buttons --}}
                    <div class="abo-admin-coupons__actions">
                        <button type="button"
                                class="abo-admin-btn abo-admin-btn--primary"
                                data-abo-admin-coupons-save>SAVE</button>
                        <button type="button"
                                class="abo-admin-btn abo-admin-btn--ghost"
                                data-abo-admin-coupons-duplicate>DUPLICATE</button>
                        <button type="button"
                                class="abo-admin-coupons__delete"
                                aria-label="Delete coupon"
                                data-abo-admin-coupons-delete>DELETE</button>
                    </div>
                    <p class="abo-admin-coupons__delete-note"
                       data-abo-admin-coupons-delete-note
                       hidden></p>

                    {{-- Live summary --}}
                    <p class="abo-admin-coupons__summary"
                       data-abo-admin-coupons-summary></p>
                </div>
            </div>
        </aside>
    </div>

    {{-- ─── Delete-confirm modal (single shared instance) ─────── --}}
    <div class="abo-admin-coupons__modal"
         role="dialog"
         aria-modal="true"
         aria-labelledby="abo-admin-coupons-modal-title"
         hidden
         data-abo-admin-coupons-modal>
        <div class="abo-admin-coupons__modal-backdrop"
             data-abo-admin-coupons-modal-close></div>
        <div class="abo-admin-coupons__modal-card">
            <h2 class="abo-admin-coupons__modal-title"
                id="abo-admin-coupons-modal-title">DELETE COUPON</h2>
            <p class="abo-admin-coupons__modal-body"
               data-abo-admin-coupons-modal-body>Are you sure? This action cannot be undone.</p>
            <div class="abo-admin-coupons__modal-actions">
                <button type="button"
                        class="abo-admin-btn abo-admin-btn--ghost"
                        data-abo-admin-coupons-modal-cancel>CANCEL</button>
                <button type="button"
                        class="abo-admin-coupons__modal-confirm"
                        data-abo-admin-coupons-modal-confirm>DELETE</button>
            </div>
        </div>
    </div>

    {{-- ─── Single shared toast ───────────────────────────────── --}}
    <div class="abo-admin-coupons__toast"
         role="status"
         aria-live="polite"
         hidden
         data-abo-admin-coupons-toast>
        <span class="abo-admin-coupons__toast-dot abo-admin-coupons__toast-dot--lime"
              aria-hidden="true"
              data-abo-admin-coupons-toast-dot></span>
        <span data-abo-admin-coupons-toast-text>Saved (demo)</span>
    </div>
@endsection

@push('head')
    {{-- Page-local stylesheet. Loaded only on admin coupons pages. --}}
    <link rel="stylesheet" href="{{ asset('admin/css/coupons.css') }}">
@endpush

@push('scripts')
    {{-- Hydrate the page from the live DB payload that Admin\CouponController::index
         prepared (see Admin\CouponService::getCouponsPayload). coupons.js reads
         window.__COUPONS_DATA__ as the source of truth instead of its old static
         COUPONS array. --}}
    <script>
        window.__COUPONS_DATA__ = {!! $couponsJson !!};
        window.__COUPON_ROUTES__ = {!! $routesJson !!};
    </script>
    <script src="{{ asset('admin/js/coupons.js') }}" defer></script>
@endpush

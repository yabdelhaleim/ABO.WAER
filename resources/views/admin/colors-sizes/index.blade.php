{{--
    Admin · Colors & Sizes (front-end only, static data).

    Extends the existing admin layout. No controller, no model.
    The colors list, the size groups and the chips are all hydrated
    from static arrays at the top of public/admin/js/colors-sizes.js.
    Every section that would need real data is marked TODO(backend).

    Page sections (top to bottom):
      1. Page header       — CATALOG eyebrow + COLORS / & SIZES title +
                              lime "ADD COLOR" CTA on the right.
      2. COLORS section    — Two-column layout. Left: toolbar + list of
                              color rows + helper text. Right: edit panel
                              (sticky on desktop, slide-over on tablet /
                              mobile) with live color preview, native
                              picker + hex text, EN/AR names, Active
                              switch, Save / Delete.
      3. SIZES section     — Sub-cards per group. Each group has a
                              visible toggle, a "+" button to add a size
                              to that group, and a row of square chips.
                              3XL is dashed with a "hidden" tag.
      4. State blocks      — Empty / no-results / loading skeleton.
      5. Modals + toast    — Shared delete modal, per-size edit modal,
                              one shared toast.
--}}

@extends('admin.layouts.app')

@section('title', 'Colors & Sizes · Catalog')
@section('description', 'ABO — admin color and size manager: list, edit panel, drag-to-reorder, group chips.')

@php
    // Real Eloquent collections are passed by Admin\ColorSizeController.
    // $payloadJson exposes the same data as a JSON script tag the front-end
    // layer can read without re-hitting the database.
    // $windowColorsJson / $windowSizeGroupsJson are assigned to window globals
    // so the page-local JS can opt into either shape without going through
    // the payload script tag.
    $payload = $payload ?? ['colors' => collect(), 'sizes' => collect()];
    $windowColorsJson = $windowColorsJson ?? '[]';
    $windowSizeGroupsJson = $windowSizeGroupsJson ?? '{}';
@endphp

@section('content')
    {{-- Server-rendered payload. The front-end JS hydrates from this
         single JSON blob (replacing the previous static arrays). --}}
    <script type="application/json" data-abo-admin-cs-payload>{!! $payloadJson ?? '{}' !!}</script>

    {{-- Window globals from the live database (set BEFORE colors-sizes.js loads).
         - window.__COLORS__         flat list ordered by sort_order / name
         - window.__SIZE_GROUPS__    object keyed by group, each value ordered
                                     by sort_order / name --}}
    <script>
        window.__COLORS__        = {!! $windowColorsJson !!};
        window.__SIZE_GROUPS__   = {!! $windowSizeGroupsJson !!};
    </script>

    {{-- ─── Page header ────────────────────────────────────────── --}}
    <header class="abo-admin-page__head">
        <div class="abo-admin-page__head-text">
            <p class="abo-admin-page__eyebrow">
                <span class="abo-admin-page__eyebrow-line" aria-hidden="true"></span>
                <span>CATALOG</span>
            </p>
            <h1 class="abo-admin-page__title">
                <span>COLORS</span><span class="abo-admin-colors-sizes__title-lime"> &amp; SIZES</span>
            </h1>
            <p class="abo-admin-page__date" id="abo-admin-today-date">Loading date…</p>
        </div>

        <div class="abo-admin-page__head-actions">
            <button type="button"
                    class="abo-admin-btn abo-admin-btn--primary"
                    data-abo-admin-cs-add-color>
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="1.8"
                          fill="none" stroke-linecap="round"/>
                </svg>
                + ADD COLOR
            </button>
        </div>
    </header>

    {{-- ─── Section 1: COLORS ──────────────────────────────────── --}}
    <section class="abo-admin-colors-sizes__section"
             aria-label="Colors"
             data-abo-admin-cs-colors>
        <div class="abo-admin-colors-sizes__section-head">
            <div class="abo-admin-colors-sizes__section-head-text">
                <p class="abo-admin-colors-sizes__section-label">COLORS</p>
                <p class="abo-admin-colors-sizes__section-helper">
                    One source of truth for every color used in the store and in the product form.
                </p>
            </div>
        </div>

        <div class="abo-admin-colors-sizes__colors-layout"
             data-abo-admin-cs>

            {{-- Left: color list --}}
            <div class="abo-admin-colors-sizes__col" aria-label="Color list">
                <div class="abo-admin-card abo-admin-colors-sizes__toolbar">
                    <label class="sr-only" for="abo-admin-cs-search">Filter colors</label>
                    <div class="abo-admin-colors-sizes__search">
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"
                             class="abo-admin-colors-sizes__search-icon">
                            <path d="M21 20.3 16.7 16a8 8 0 1 0-1.4 1.4L20.3 21 21 20.3zM4 10a6 6 0 1 1 12 0 6 6 0 0 1-12 0z"
                                  fill="currentColor"/>
                        </svg>
                        <input id="abo-admin-cs-search"
                               type="search"
                               placeholder="Search by name, Arabic, or hex…"
                               autocomplete="off"
                               data-abo-admin-cs-search>
                        <button type="button"
                                class="abo-admin-colors-sizes__search-clear"
                                aria-label="Clear search">×</button>
                    </div>
                </div>

                <article class="abo-admin-card abo-admin-colors-sizes__list-card">
                    <ul class="abo-admin-colors-sizes__list"
                        role="listbox"
                        aria-label="Colors"
                        aria-activedescendant=""
                        data-abo-admin-cs-list></ul>

                    <p class="abo-admin-colors-sizes__section-helper"
                       style="margin: 0.6rem 0.6rem 0.2rem;">
                        Drag the handle to reorder. Same order appears in the store and in the product form.
                    </p>

                    {{-- States: skeleton / empty / no-results are mutually
                         exclusive and toggled by colors-sizes.js. --}}
                    <div class="abo-admin-colors-sizes__state"
                         data-abo-admin-cs-state="empty" hidden>
                        <p>No colors yet. Add your first color.</p>
                        <button type="button"
                                class="abo-admin-btn abo-admin-btn--primary"
                                data-abo-admin-cs-add-color-empty>
                            + ADD COLOR
                        </button>
                    </div>

                    <div class="abo-admin-colors-sizes__state"
                         data-abo-admin-cs-state="noresults" hidden>
                        <p>No results. Try clearing the search above.</p>
                    </div>

                    <div class="abo-admin-colors-sizes__state"
                         data-abo-admin-cs-skeleton>
                        <div class="abo-admin-colors-sizes__skeleton" aria-hidden="true">
                            @for ($i = 0; $i < 6; $i++)
                                <div class="abo-admin-colors-sizes__skeleton-row">
                                    <span class="abo-admin-colors-sizes__skeleton-cell" style="width: 12px; height: 14px;"></span>
                                    <span class="abo-admin-colors-sizes__skeleton-cell abo-admin-colors-sizes__skeleton-cell--swatch"></span>
                                    <span class="abo-admin-colors-sizes__skeleton-cell" style="width: 70%;"></span>
                                    <span class="abo-admin-colors-sizes__skeleton-cell" style="width: 56px;"></span>
                                    <span class="abo-admin-colors-sizes__skeleton-cell abo-admin-colors-sizes__skeleton-cell--toggle"></span>
                                </div>
                            @endfor
                        </div>
                    </div>
                </article>
            </div>

            {{-- Right: edit color panel (sticky on desktop, slide-over on mobile) --}}
            <aside class="abo-admin-colors-sizes__col abo-admin-colors-sizes__panel"
                   aria-label="Edit color"
                   data-abo-admin-cs-panel>
                <div class="abo-admin-colors-sizes__panel-overlay"
                     hidden
                     data-abo-admin-cs-panel-overlay
                     aria-hidden="true"></div>

                {{-- "No selection" placeholder --}}
                <div class="abo-admin-colors-sizes__panel-empty"
                     data-abo-admin-cs-panel-no-selection>
                    <p style="margin:0 0 0.4rem;color:var(--abo-admin-text);font-weight:600;">No color selected.</p>
                    <p style="margin:0;">Click a row in the list, or add a new one.</p>
                </div>

                {{-- Form (toggled by colors-sizes.js) --}}
                <div data-abo-admin-cs-panel-form hidden>
                    <div class="abo-admin-colors-sizes__panel-head">
                        <h2 class="abo-admin-colors-sizes__panel-title">
                            <span data-abo-admin-cs-panel-title-text>EDIT COLOR</span>
                            <span class="abo-admin-colors-sizes__dirty-dot"
                                  aria-label="Unsaved changes"
                                  data-abo-admin-cs-dirty-dot></span>
                        </h2>
                        <button type="button"
                                class="abo-admin-colors-sizes__panel-close"
                                aria-label="Close edit panel"
                                data-abo-admin-cs-panel-close>
                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                <path d="M6 6l12 12M18 6 6 18"
                                      stroke="currentColor" stroke-width="1.6"
                                      fill="none" stroke-linecap="round"/>
                            </svg>
                        </button>
                    </div>

                    <div class="abo-admin-colors-sizes__panel-body">

                        {{-- Live preview of the colour being edited: the
                             swatch, its name, and how many products in the
                             catalogue use it. The card used to show a
                             made-up "Sample product · $0.00" — a product
                             that does not exist. --}}
                        <div class="abo-admin-colors-sizes__preview"
                             data-abo-admin-cs-preview>
                            <div class="abo-admin-colors-sizes__preview-img"
                                 aria-hidden="true"
                                 data-abo-admin-cs-preview-img>
                                <span>COLOR</span>
                            </div>
                            <p class="abo-admin-colors-sizes__preview-name"
                               data-abo-admin-cs-preview-name>—</p>
                            <p class="abo-admin-colors-sizes__preview-price">
                                <span class="abo-admin-colors-sizes__preview-dot"
                                      aria-hidden="true"
                                      data-abo-admin-cs-preview-dot></span>
                                <span data-abo-admin-cs-preview-usage>—</span>
                            </p>
                        </div>

                        {{-- Name (English) --}}
                        <div class="abo-admin-colors-sizes__field">
                            <label for="abo-admin-cs-name">Name (English)</label>
                            <label class="sr-only" for="abo-admin-cs-name">Color name in English</label>
                            <input id="abo-admin-cs-name"
                                   type="text"
                                   placeholder="e.g. Black"
                                   autocomplete="off"
                                   data-abo-admin-cs-name>
                        </div>

                        {{-- Name (Arabic, RTL) --}}
                        <div class="abo-admin-colors-sizes__field abo-admin-colors-sizes__field--rtl">
                            <label for="abo-admin-cs-name-ar">Name (Arabic)</label>
                            <label class="sr-only" for="abo-admin-cs-name-ar">Color name in Arabic</label>
                            <input id="abo-admin-cs-name-ar"
                                   type="text"
                                   placeholder="مثل: أسود"
                                   dir="rtl"
                                   autocomplete="off"
                                   data-abo-admin-cs-name-ar>
                        </div>

                        {{-- Hex (text input + native color picker, linked both ways) --}}
                        <div class="abo-admin-colors-sizes__field">
                            <label for="abo-admin-cs-hex">Hex code</label>
                            <div class="abo-admin-colors-sizes__hex-row">
                                <label class="sr-only" for="abo-admin-cs-hex">Hex color code</label>
                                <input id="abo-admin-cs-hex"
                                       type="text"
                                       placeholder="#000000"
                                       maxlength="7"
                                       autocomplete="off"
                                       spellcheck="false"
                                       data-abo-admin-cs-hex>
                                <input type="color"
                                       class="abo-admin-colors-sizes__hex-pick"
                                       aria-label="Color picker"
                                       value="#000000"
                                       data-abo-admin-cs-hex-pick>
                            </div>
                            <p class="abo-admin-colors-sizes__field-error"
                               data-abo-admin-cs-hex-error
                               hidden>Enter a valid hex code</p>
                        </div>

                        {{-- Active switch --}}
                        <div class="abo-admin-colors-sizes__switch">
                            <div>
                                <span class="abo-admin-colors-sizes__switch-label">Active (visible in store)</span>
                                <p class="abo-admin-colors-sizes__switch-hint">Inactive colors stay hidden in the catalog and product form.</p>
                            </div>
                            <label class="abo-admin-colors-sizes__toggle" aria-label="Active (visible in store)">
                                <input type="checkbox"
                                       checked
                                       aria-checked="true"
                                       data-abo-admin-cs-active>
                                <span class="abo-admin-colors-sizes__toggle-track"></span>
                                <span class="abo-admin-colors-sizes__toggle-thumb"></span>
                            </label>
                        </div>

                        {{-- Action buttons --}}
                        <div class="abo-admin-colors-sizes__actions">
                            <button type="button"
                                    class="abo-admin-btn abo-admin-btn--primary"
                                    data-abo-admin-cs-save>
                                SAVE
                            </button>
                            <button type="button"
                                    class="abo-admin-colors-sizes__delete"
                                    aria-label="Delete color"
                                    data-abo-admin-cs-delete>
                                DELETE
                            </button>
                        </div>
                        <p class="abo-admin-colors-sizes__delete-note"
                           data-abo-admin-cs-delete-note
                           hidden></p>
                    </div>
                </div>
            </aside>
        </div>
    </section>

    {{-- ─── Section 2: SIZES ───────────────────────────────────── --}}
    <section class="abo-admin-colors-sizes__section"
             aria-label="Sizes"
             data-abo-admin-cs-sizes-section>
        <div class="abo-admin-colors-sizes__section-head">
            <div class="abo-admin-colors-sizes__section-head-text">
                <p class="abo-admin-colors-sizes__section-label">SIZES</p>
                <p class="abo-admin-colors-sizes__section-helper">
                    Drag to reorder. The order here is the order in the store and in the product form.
                </p>
            </div>
            <div class="abo-admin-page__head-actions">
                <button type="button"
                        class="abo-admin-btn abo-admin-btn--ghost"
                        data-abo-admin-cs-add-size>
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="1.8"
                              fill="none" stroke-linecap="round"/>
                    </svg>
                    + ADD SIZE
                </button>
            </div>
        </div>

        <div class="abo-admin-colors-sizes__groups"
             data-abo-admin-cs-sizes>
            {{-- Groups are injected by colors-sizes.js from the SIZE_GROUPS
                 static array. No server-side markup here. --}}
        </div>
    </section>

    {{-- ─── Shared delete modal ────────────────────────────────── --}}
    <div class="abo-admin-colors-sizes__modal"
         role="dialog"
         aria-modal="true"
         aria-labelledby="abo-admin-cs-modal-title"
         hidden
         data-abo-admin-cs-modal>
        <div class="abo-admin-colors-sizes__modal-backdrop"
             data-abo-admin-cs-modal-close></div>
        <div class="abo-admin-colors-sizes__modal-card">
            <h2 class="abo-admin-colors-sizes__modal-title"
                id="abo-admin-cs-modal-title">CONFIRM DELETE</h2>
            <p class="abo-admin-colors-sizes__modal-body"
               data-abo-admin-cs-modal-body>Are you sure? This action cannot be undone.</p>
            <div class="abo-admin-colors-sizes__modal-actions">
                <button type="button"
                        class="abo-admin-btn abo-admin-btn--ghost"
                        data-abo-admin-cs-modal-cancel>CANCEL</button>
                <button type="button"
                        class="abo-admin-colors-sizes__modal-confirm"
                        data-abo-admin-cs-modal-confirm>DELETE</button>
            </div>
        </div>
    </div>

    {{-- ─── Size edit modal ────────────────────────────────────── --}}
    <div class="abo-admin-colors-sizes__modal"
         role="dialog"
         aria-modal="true"
         aria-labelledby="abo-admin-cs-size-modal-title"
         hidden
         data-abo-admin-cs-size-modal>
        <div class="abo-admin-colors-sizes__modal-backdrop"
             data-abo-admin-cs-size-modal-close></div>
        <div class="abo-admin-colors-sizes__modal-card">
            <h2 class="abo-admin-colors-sizes__modal-title"
                id="abo-admin-cs-size-modal-title">
                <span data-abo-admin-cs-size-modal-title-text>EDIT SIZE</span>
            </h2>
            <p class="abo-admin-colors-sizes__modal-body"
               data-abo-admin-cs-size-modal-body></p>

            <div class="abo-admin-colors-sizes__field">
                <label for="abo-admin-cs-size-label">Label</label>
                <label class="sr-only" for="abo-admin-cs-size-label">Size label</label>
                <input id="abo-admin-cs-size-label"
                       type="text"
                       placeholder="e.g. M"
                       autocomplete="off"
                       data-abo-admin-cs-size-label>
            </div>

            <div class="abo-admin-colors-sizes__field" data-abo-admin-cs-size-group-field>
                <label for="abo-admin-cs-size-group">Group</label>
                <label class="sr-only" for="abo-admin-cs-size-group">Size group</label>
                <select id="abo-admin-cs-size-group"
                        data-abo-admin-cs-size-group>
                    {{-- Options injected by colors-sizes.js from SIZE_GROUPS. --}}
                </select>
            </div>

            <div class="abo-admin-colors-sizes__switch">
                <div>
                    <span class="abo-admin-colors-sizes__switch-label">Visible</span>
                    <p class="abo-admin-colors-sizes__switch-hint">Hidden sizes stay in the data but don't show in the store or product form.</p>
                </div>
                <label class="abo-admin-colors-sizes__toggle" aria-label="Visible">
                    <input type="checkbox"
                           checked
                           aria-checked="true"
                           data-abo-admin-cs-size-visible>
                    <span class="abo-admin-colors-sizes__toggle-track"></span>
                    <span class="abo-admin-colors-sizes__toggle-thumb"></span>
                </label>
            </div>

            <div class="abo-admin-colors-sizes__actions">
                <button type="button"
                        class="abo-admin-btn abo-admin-btn--primary abo-admin-colors-sizes__modal-confirm--lime"
                        data-abo-admin-cs-size-save>
                    SAVE
                </button>
                <button type="button"
                        class="abo-admin-colors-sizes__delete"
                        aria-label="Delete size"
                        data-abo-admin-cs-size-delete>
                    DELETE
                </button>
            </div>
            <p class="abo-admin-colors-sizes__delete-note"
               data-abo-admin-cs-size-delete-note
               hidden></p>

            <div class="abo-admin-colors-sizes__modal-actions"
                 style="margin-top: 0.4rem; border-top: 1px solid var(--abo-admin-border); padding-top: 0.7rem;">
                <button type="button"
                        class="abo-admin-btn abo-admin-btn--ghost"
                        data-abo-admin-cs-size-modal-close>CLOSE</button>
            </div>
        </div>
    </div>

    {{-- ─── Single shared toast ────────────────────────────────── --}}
    <div class="abo-admin-colors-sizes__toast"
         role="status"
         aria-live="polite"
         hidden
         data-abo-admin-cs-toast>
        <span class="abo-admin-colors-sizes__toast-dot abo-admin-colors-sizes__toast-dot--lime"
              aria-hidden="true"
              data-abo-admin-cs-toast-dot></span>
        <span data-abo-admin-cs-toast-text>Saved (demo)</span>
    </div>
@endsection

@push('head')
    {{-- Page-local stylesheet. Loaded only on the colors-sizes page. --}}
    <link rel="stylesheet" href="{{ asset('admin/css/colors-sizes.css') }}">
@endpush

@push('scripts')
    {{-- Page-local script. Loaded only on the colors-sizes page. --}}
    <script src="{{ asset('admin/js/colors-sizes.js') }}" defer></script>
@endpush

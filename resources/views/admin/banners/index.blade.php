{{--
    Admin · Banners (front-end only, static data).

    Extends the existing admin layout. No controller, no model. Every
    banner row, status, edit field and preview is hydrated by
    public/admin/js/banners.js from the static BANNERS array in that
    same file. Each section that would need real data is marked
    TODO(backend).

    Page sections (top to bottom):
      1. Page header    — CONTENT eyebrow + BAN / NERS title, lime
                           "+ ADD BANNER" CTA on the right.
      2. Placement tabs — Hero slider, Promo banners, Announcement bar
                           (each with a count). Lime bottom border on
                           the active tab; horizontally scrollable on
                           mobile.
      3. Two columns    — Left: list card (rows w/ drag handle, thumb,
                           title, schedule, status, toggle). Right:
                           edit panel (slide-over on tablet/mobile).
      4. States         — Per-tab empty / loading skeleton.
      5. Modal + toast  — Single shared delete-confirm modal + toast.

    Conventions match admin/categories/index.blade.php and
    admin/coupons/index.blade.php: existing shell classes are reused
    (abo-admin-*) — new banner-specific classes are prefixed
    `abo-admin-banners-`.
--}}

@extends('admin.layouts.app')

@section('title', 'Banners · Content')
@section('description', 'ABO — admin banner manager: hero slider, promo banners, announcement bar.')

@php
    // The view carries no banner data of its own; the static BANNERS
    // array in public/admin/js/banners.js is the single source of
    // truth (hero, promo, announcement groups). See that file for the
    // shape of each record.
    //
    // TODO(backend): replace the static BANNERS array in
    // public/admin/js/banners.js with real data fetched from a banners
    // table (placement, title, subtitle, image urls, schedule,
    // linkTarget, look, active, order, etc.). The view shape is final;
    // only the data source changes.
@endphp

@section('content')
    {{-- ─── Page header ────────────────────────────────────────── --}}
    <header class="abo-admin-page__head">
        <div class="abo-admin-page__head-text">
            <p class="abo-admin-page__eyebrow">
                <span class="abo-admin-page__eyebrow-line" aria-hidden="true"></span>
                <span>CONTENT</span>
            </p>
            <h1 class="abo-admin-page__title">
                <span>BAN</span><span class="abo-admin-banners__title-lime">NERS</span>
            </h1>
            <p class="abo-admin-page__date" id="abo-admin-banners-today-date">Loading date…</p>
        </div>

        <div class="abo-admin-page__head-actions">
            <button type="button"
                    class="abo-admin-btn abo-admin-btn--primary"
                    data-abo-admin-banners-add
                    aria-label="Add banner to current placement">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="1.8"
                          fill="none" stroke-linecap="round"/>
                </svg>
                + ADD BANNER
            </button>
        </div>
    </header>

    {{-- ─── Placement tabs ─────────────────────────────────────── --}}
    <nav class="abo-admin-banners__tabs"
         role="tablist"
         aria-label="Banner placement"
         data-abo-admin-banners-tabs>
        <button type="button"
                class="abo-admin-banners__tab is-active"
                role="tab"
                aria-selected="true"
                aria-controls="abo-admin-banners-list"
                data-abo-admin-banners-tab="hero">
            Hero slider
            <span class="abo-admin-banners__tab-num"
                  data-abo-admin-banners-tab-count="hero">0</span>
        </button>
        <button type="button"
                class="abo-admin-banners__tab"
                role="tab"
                aria-selected="false"
                aria-controls="abo-admin-banners-list"
                data-abo-admin-banners-tab="promo">
            Promo banners
            <span class="abo-admin-banners__tab-num"
                  data-abo-admin-banners-tab-count="promo">0</span>
        </button>
        <button type="button"
                class="abo-admin-banners__tab"
                role="tab"
                aria-selected="false"
                aria-controls="abo-admin-banners-list"
                data-abo-admin-banners-tab="announcement">
            Announcement bar
            <span class="abo-admin-banners__tab-num"
                  data-abo-admin-banners-tab-count="announcement">0</span>
        </button>
    </nav>

    {{-- ─── Two columns: list + edit panel ─────────────────────── --}}
    <div class="abo-admin-banners__layout"
         id="abo-admin-banners-list"
         role="tabpanel"
         aria-label="Banners list"
         data-abo-admin-banners>

        {{-- ───── LEFT: list card ──────────────────────────────── --}}
        <section class="abo-admin-banners__col" aria-label="Banner list">
            <article class="abo-admin-card abo-admin-banners__list-card">

                {{-- The list itself. Rows are injected by banners.js
                     from the BANNERS static array. role="listbox" with
                     role="option" rows for accessibility. --}}
                <ul class="abo-admin-banners__list"
                    role="listbox"
                    aria-label="Banners"
                    data-abo-admin-banners-list></ul>

                {{-- Per-tab empty state --}}
                <div class="abo-admin-banners__state"
                     data-abo-admin-banners-state="empty"
                     hidden>
                    <p class="abo-admin-empty">No banners here yet. Add your first one.</p>
                    <button type="button"
                            class="abo-admin-btn abo-admin-btn--primary"
                            data-abo-admin-banners-add-empty>+ ADD BANNER</button>
                </div>

                {{-- Loading skeleton (default until JS hydrates) --}}
                <div class="abo-admin-banners__state"
                     data-abo-admin-banners-state="loading"
                     data-abo-admin-banners-skeleton>
                    <div class="abo-admin-banners__skeleton" aria-hidden="true">
                        @for ($i = 0; $i < 4; $i++)
                            <div class="abo-admin-banners__skeleton-row">
                                <span class="abo-admin-banners__skeleton-cell"
                                      style="width: 16px; height: 16px;"></span>
                                <span class="abo-admin-banners__skeleton-cell"
                                      style="width: 96px; height: 54px;"></span>
                                <span class="abo-admin-banners__skeleton-cell"
                                      style="width: 70%;"></span>
                                <span class="abo-admin-banners__skeleton-cell"
                                      style="width: 120px;"></span>
                                <span class="abo-admin-banners__skeleton-cell"
                                      style="width: 70px;"></span>
                                <span class="abo-admin-banners__skeleton-cell"
                                      style="width: 28px; height: 14px;"></span>
                            </div>
                        @endfor
                    </div>
                </div>

                {{-- Hero-only counter under the list --}}
                <p class="abo-admin-banners__counter"
                   data-abo-admin-banners-counter
                   aria-live="polite"
                   hidden></p>

                {{-- Helper text under the list --}}
                <p class="abo-admin-banners__helper"
                   data-abo-admin-banners-helper>
                    Drag the handle to reorder. Only Live banners appear in the store, in this order.
                </p>
            </article>
        </section>

        {{-- ───── RIGHT: edit panel ───────────────────────────── --}}
        <aside class="abo-admin-banners__col abo-admin-banners__panel"
               aria-label="Edit banner"
               data-abo-admin-banners-panel>
            {{-- Slide-over backdrop on tablet/mobile --}}
            <div class="abo-admin-banners__panel-overlay"
                 hidden
                 data-abo-admin-banners-panel-overlay
                 aria-hidden="true"></div>

            {{-- "No selection" placeholder --}}
            <div class="abo-admin-banners__panel-empty"
                 data-abo-admin-banners-panel-no-selection>
                <p style="margin:0 0 0.4rem; color: var(--abo-admin-text); font-weight: 600;">
                    No banner selected.
                </p>
                <p style="margin:0;">Click a row in the list, or add a new banner.</p>
            </div>

            {{-- Form (toggled by banners.js once a row is selected
                 or the Add button is pressed) --}}
            <div data-abo-admin-banners-panel-form hidden>

                <div class="abo-admin-banners__panel-head">
                    <h2 class="abo-admin-banners__panel-title">
                        <span data-abo-admin-banners-panel-title-text>EDIT BANNER</span>
                        <span class="abo-admin-banners__dirty-dot"
                              aria-label="Unsaved changes"
                              data-abo-admin-banners-dirty-dot></span>
                    </h2>
                    <button type="button"
                            class="abo-admin-banners__panel-close"
                            aria-label="Close edit panel"
                            data-abo-admin-banners-panel-close>
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M6 6l12 12M18 6 6 18"
                                  stroke="currentColor" stroke-width="1.6"
                                  fill="none" stroke-linecap="round"/>
                        </svg>
                    </button>
                </div>

                <div class="abo-admin-banners__panel-body">

                    {{-- Placement select --}}
                    <div class="abo-admin-banners__field">
                        <label for="abo-admin-banners-placement">Placement</label>
                        <label class="sr-only" for="abo-admin-banners-placement">Placement</label>
                        <select id="abo-admin-banners-placement"
                                data-abo-admin-banners-placement>
                            <option value="hero">Hero slider</option>
                            <option value="promo">Promo banners</option>
                            <option value="announcement">Announcement bar</option>
                        </select>
                    </div>

                    {{-- Images (Desktop + Mobile) — hidden on announcement --}}
                    <div class="abo-admin-banners__images"
                         data-abo-admin-banners-images>

                        <div class="abo-admin-banners__image"
                             data-abo-admin-banners-image="desktop">
                            <label class="abo-admin-banners__image-label">Desktop image</label>
                            <div class="abo-admin-banners__image-frame abo-admin-banners__image-frame--desktop"
                                 aria-hidden="true"
                                 data-abo-admin-banners-desktop-preview>
                                <span class="abo-admin-banners__image-placeholder">
                                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                        <path d="M4 5h16v14H4z" fill="none" stroke="currentColor"
                                              stroke-width="1.4"/>
                                        <circle cx="9" cy="10" r="1.5" fill="currentColor"/>
                                        <path d="M5 17 10 12l4 4 3-3 3 4" fill="none" stroke="currentColor"
                                              stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                    <span>1600 × 700</span>
                                </span>
                            </div>
                            <div class="abo-admin-banners__image-actions">
                                <input type="file"
                                       accept="image/jpeg,image/png,image/webp"
                                       class="abo-admin-banners__image-file"
                                       data-abo-admin-banners-desktop-file>
                                <button type="button"
                                        class="abo-admin-banners__cover-btn"
                                        data-abo-admin-banners-desktop-change>CHANGE</button>
                                <button type="button"
                                        class="abo-admin-banners__cover-btn abo-admin-banners__cover-btn--danger"
                                        data-abo-admin-banners-desktop-remove
                                        disabled>REMOVE</button>
                            </div>
                            <p class="abo-admin-banners__field-error"
                               data-abo-admin-banners-desktop-error
                               hidden>Use a JPG, PNG or WebP under 2 MB</p>
                        </div>

                        <div class="abo-admin-banners__image"
                             data-abo-admin-banners-image="mobile">
                            <label class="abo-admin-banners__image-label">Mobile image</label>
                            <div class="abo-admin-banners__image-frame abo-admin-banners__image-frame--mobile"
                                 aria-hidden="true"
                                 data-abo-admin-banners-mobile-preview>
                                <span class="abo-admin-banners__image-placeholder">
                                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                        <path d="M4 5h16v14H4z" fill="none" stroke="currentColor"
                                              stroke-width="1.4"/>
                                        <circle cx="9" cy="10" r="1.5" fill="currentColor"/>
                                        <path d="M5 17 10 12l4 4 3-3 3 4" fill="none" stroke="currentColor"
                                              stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                    <span>800 × 1000</span>
                                </span>
                            </div>
                            <div class="abo-admin-banners__image-actions">
                                <input type="file"
                                       accept="image/jpeg,image/png,image/webp"
                                       class="abo-admin-banners__image-file"
                                       data-abo-admin-banners-mobile-file>
                                <button type="button"
                                        class="abo-admin-banners__cover-btn"
                                        data-abo-admin-banners-mobile-change>CHANGE</button>
                                <button type="button"
                                        class="abo-admin-banners__cover-btn abo-admin-banners__cover-btn--danger"
                                        data-abo-admin-banners-mobile-remove
                                        disabled>REMOVE</button>
                            </div>
                            <p class="abo-admin-banners__field-hint">
                                If empty, the desktop image is used.
                            </p>
                            <p class="abo-admin-banners__field-error"
                               data-abo-admin-banners-mobile-error
                               hidden>Use a JPG, PNG or WebP under 2 MB</p>
                        </div>
                    </div>

                    {{-- Alt text (with EN/AR switch) --}}
                    <div class="abo-admin-banners__field"
                         data-abo-admin-banners-alt-field>
                        <label for="abo-admin-banners-alt">Alt text</label>
                        <div class="abo-admin-banners__lang"
                             role="tablist"
                             aria-label="Alt text language"
                             data-abo-admin-banners-alt-lang>
                            <button type="button"
                                    class="is-active"
                                    role="tab"
                                    aria-selected="true"
                                    aria-pressed="true"
                                    data-lang="en">EN</button>
                            <button type="button"
                                    role="tab"
                                    aria-selected="false"
                                    aria-pressed="false"
                                    data-lang="ar">AR</button>
                        </div>
                        <label class="sr-only" for="abo-admin-banners-alt">Alt text</label>
                        <input id="abo-admin-banners-alt"
                               type="text"
                               placeholder="Describe the image for screen readers."
                               autocomplete="off"
                               data-abo-admin-banners-alt>
                        <p class="abo-admin-banners__field-hint">
                            Describe the image for screen readers.
                        </p>
                        <p class="abo-admin-banners__field-error"
                           data-abo-admin-banners-alt-error
                           hidden>Alt text is required</p>
                    </div>

                    {{-- Title (with EN/AR switch) — for hero/promo it's
                         the headline; for announcements it's the
                         message text. --}}
                    <div class="abo-admin-banners__field">
                        <label for="abo-admin-banners-title"
                               data-abo-admin-banners-title-label>Title</label>
                        <div class="abo-admin-banners__lang"
                             role="tablist"
                             aria-label="Title language"
                             data-abo-admin-banners-title-lang>
                            <button type="button"
                                    class="is-active"
                                    role="tab"
                                    aria-selected="true"
                                    aria-pressed="true"
                                    data-lang="en">EN</button>
                            <button type="button"
                                    role="tab"
                                    aria-selected="false"
                                    aria-pressed="false"
                                    data-lang="ar">AR</button>
                        </div>
                        <label class="sr-only" for="abo-admin-banners-title">Title</label>
                        <input id="abo-admin-banners-title"
                               type="text"
                               placeholder="e.g. Winter drop — 25% off select sets"
                               autocomplete="off"
                               data-abo-admin-banners-title>
                    </div>

                    {{-- Subtitle — hero only --}}
                    <div class="abo-admin-banners__field"
                         data-abo-admin-banners-subtitle-field>
                        <label for="abo-admin-banners-subtitle">Subtitle</label>
                        <div class="abo-admin-banners__lang"
                             role="tablist"
                             aria-label="Subtitle language"
                             data-abo-admin-banners-subtitle-lang>
                            <button type="button"
                                    class="is-active"
                                    role="tab"
                                    aria-selected="true"
                                    aria-pressed="true"
                                    data-lang="en">EN</button>
                            <button type="button"
                                    role="tab"
                                    aria-selected="false"
                                    aria-pressed="false"
                                    data-lang="ar">AR</button>
                        </div>
                        <label class="sr-only" for="abo-admin-banners-subtitle">Subtitle</label>
                        <input id="abo-admin-banners-subtitle"
                               type="text"
                               placeholder="A short description shown under the title."
                               autocomplete="off"
                               data-abo-admin-banners-subtitle>
                    </div>

                    {{-- Button label — hero only --}}
                    <div class="abo-admin-banners__field"
                         data-abo-admin-banners-button-field>
                        <label for="abo-admin-banners-button">Button label</label>
                        <label class="sr-only" for="abo-admin-banners-button">Button label</label>
                        <input id="abo-admin-banners-button"
                               type="text"
                               placeholder="e.g. Shop now"
                               autocomplete="off"
                               maxlength="32"
                               data-abo-admin-banners-button>
                        <p class="abo-admin-banners__field-hint">
                            Shown on the slide button (hero only).
                        </p>
                    </div>

                    {{-- Link --}}
                    <div class="abo-admin-banners__field">
                        <label for="abo-admin-banners-link-type">Link to</label>
                        <label class="sr-only" for="abo-admin-banners-link-type">Link to</label>
                        <select id="abo-admin-banners-link-type"
                                data-abo-admin-banners-link-type>
                            <option value="category">Category</option>
                            <option value="product">Product</option>
                            <option value="coupon">Coupon page</option>
                            <option value="url">Custom URL</option>
                            <option value="none">None</option>
                        </select>
                    </div>
                    <div class="abo-admin-banners__field"
                         data-abo-admin-banners-link-category>
                        <label for="abo-admin-banners-link-category-value">Category</label>
                        <label class="sr-only" for="abo-admin-banners-link-category-value">Category</label>
                        <select id="abo-admin-banners-link-category-value"
                                data-abo-admin-banners-link-category-value>
                            {{-- Options injected by banners.js --}}
                        </select>
                    </div>
                    <div class="abo-admin-banners__field"
                         data-abo-admin-banners-link-product
                         hidden>
                        <label for="abo-admin-banners-link-product-value">Product</label>
                        <label class="sr-only" for="abo-admin-banners-link-product-value">Product</label>
                        <select id="abo-admin-banners-link-product-value"
                                data-abo-admin-banners-link-product-value>
                            {{-- Options injected by banners.js --}}
                        </select>
                    </div>
                    <div class="abo-admin-banners__field"
                         data-abo-admin-banners-link-url
                         hidden>
                        <label for="abo-admin-banners-link-url-value">Custom URL</label>
                        <label class="sr-only" for="abo-admin-banners-link-url-value">Custom URL</label>
                        <input id="abo-admin-banners-link-url-value"
                               type="text"
                               placeholder="https://example.com/page or /shop/sets"
                               autocomplete="off"
                               data-abo-admin-banners-link-url-value>
                        <p class="abo-admin-banners__field-error"
                           data-abo-admin-banners-link-url-error
                           hidden>Enter a valid link</p>
                    </div>

                    {{-- Look (hero + promo only) --}}
                    <div class="abo-admin-banners__field"
                         data-abo-admin-banners-look>
                        <span class="abo-admin-banners__field-label">Look</span>
                        <div class="abo-admin-banners__look-row">
                            <div class="abo-admin-banners__look-cell">
                                <span class="abo-admin-banners__look-cell-label">Text color</span>
                                <div class="abo-admin-segmented abo-admin-banners__segmented"
                                     role="radiogroup"
                                     aria-label="Text color"
                                     data-abo-admin-banners-text-color>
                                    <button type="button"
                                            class="is-active"
                                            role="radio"
                                            aria-checked="true"
                                            data-value="light">LIGHT</button>
                                    <button type="button"
                                            role="radio"
                                            aria-checked="false"
                                            data-value="dark">DARK</button>
                                </div>
                            </div>
                            <div class="abo-admin-banners__look-cell">
                                <span class="abo-admin-banners__look-cell-label">Text position</span>
                                <div class="abo-admin-segmented abo-admin-banners__segmented"
                                     role="radiogroup"
                                     aria-label="Text position"
                                     data-abo-admin-banners-text-position>
                                    <button type="button"
                                            class="is-active"
                                            role="radio"
                                            aria-checked="true"
                                            data-value="left">LEFT</button>
                                    <button type="button"
                                            role="radio"
                                            aria-checked="false"
                                            data-value="center">CENTER</button>
                                    <button type="button"
                                            role="radio"
                                            aria-checked="false"
                                            data-value="right">RIGHT</button>
                                </div>
                            </div>
                            <div class="abo-admin-banners__look-cell">
                                <span class="abo-admin-banners__look-cell-label">Image overlay</span>
                                <div class="abo-admin-segmented abo-admin-banners__segmented"
                                     role="radiogroup"
                                     aria-label="Image overlay"
                                     data-abo-admin-banners-overlay>
                                    <button type="button"
                                            class="is-active"
                                            role="radio"
                                            aria-checked="true"
                                            data-value="none">NONE</button>
                                    <button type="button"
                                            role="radio"
                                            aria-checked="false"
                                            data-value="light">LIGHT</button>
                                    <button type="button"
                                            role="radio"
                                            aria-checked="false"
                                            data-value="dark">DARK</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Schedule --}}
                    <div class="abo-admin-banners__field">
                        <span class="abo-admin-banners__field-label">Schedule</span>
                        <div class="abo-admin-banners__schedule-row">
                            <div class="abo-admin-banners__field">
                                <label for="abo-admin-banners-starts">Starts</label>
                                <label class="sr-only" for="abo-admin-banners-starts">Starts</label>
                                <input id="abo-admin-banners-starts"
                                       type="date"
                                       data-abo-admin-banners-starts>
                            </div>
                            <div class="abo-admin-banners__field">
                                <label for="abo-admin-banners-ends">Ends</label>
                                <label class="sr-only" for="abo-admin-banners-ends">Ends</label>
                                <input id="abo-admin-banners-ends"
                                       type="date"
                                       data-abo-admin-banners-ends>
                                <p class="abo-admin-banners__field-error"
                                   data-abo-admin-banners-date-error
                                   hidden>End date must be after the start date</p>
                            </div>
                        </div>
                    </div>

                    {{-- Active switch --}}
                    <div class="abo-admin-banners__switch"
                         data-abo-admin-banners-switch="active">
                        <div>
                            <span class="abo-admin-banners__switch-label">Active</span>
                            <p class="abo-admin-banners__switch-hint">Inactive banners are kept as drafts and never appear in the store.</p>
                        </div>
                        <label class="abo-admin-banners__toggle"
                               aria-label="Active">
                            <input type="checkbox"
                                   checked
                                   aria-checked="true"
                                   data-abo-admin-banners-active>
                            <span class="abo-admin-banners__toggle-track"></span>
                            <span class="abo-admin-banners__toggle-thumb"></span>
                        </label>
                    </div>

                    {{-- Live preview --}}
                    <div class="abo-admin-banners__preview"
                         data-abo-admin-banners-preview-wrap
                         aria-hidden="true">
                        <div class="abo-admin-banners__preview-head">
                            <span class="abo-admin-banners__preview-label">PREVIEW</span>
                            <span class="sr-only">Preview only</span>
                            <div class="abo-admin-segmented abo-admin-banners__segmented abo-admin-banners__preview-toggle"
                                 role="radiogroup"
                                 aria-label="Preview device"
                                 data-abo-admin-banners-preview-device>
                                <button type="button"
                                        class="is-active"
                                        role="radio"
                                        aria-checked="true"
                                        data-value="desktop">DESKTOP</button>
                                <button type="button"
                                        role="radio"
                                        aria-checked="false"
                                        data-value="mobile">MOBILE</button>
                            </div>
                        </div>
                        <div class="abo-admin-banners__preview-frame"
                             data-abo-admin-banners-preview-frame>
                            <div class="abo-admin-banners__preview-banner"
                                 data-abo-admin-banners-preview-banner>
                                <div class="abo-admin-banners__preview-overlay"
                                     data-abo-admin-banners-preview-overlay></div>
                                <div class="abo-admin-banners__preview-text"
                                     data-abo-admin-banners-preview-text></div>
                                <span class="abo-admin-banners__preview-btn"
                                      data-abo-admin-banners-preview-btn>SHOP NOW</span>
                            </div>
                        </div>
                    </div>

                    {{-- Action buttons --}}
                    <div class="abo-admin-banners__actions">
                        <button type="button"
                                class="abo-admin-btn abo-admin-btn--primary"
                                data-abo-admin-banners-save>SAVE</button>
                        <button type="button"
                                class="abo-admin-btn abo-admin-btn--ghost"
                                data-abo-admin-banners-duplicate>DUPLICATE</button>
                        <button type="button"
                                class="abo-admin-banners__delete"
                                aria-label="Delete banner"
                                data-abo-admin-banners-delete>DELETE</button>
                    </div>
                    <p class="abo-admin-banners__delete-note"
                       data-abo-admin-banners-delete-note
                       hidden></p>
                </div>
            </div>
        </aside>
    </div>

    {{-- ─── Delete-confirm modal (single shared instance) ─────── --}}
    <div class="abo-admin-banners__modal"
         role="dialog"
         aria-modal="true"
         aria-labelledby="abo-admin-banners-modal-title"
         hidden
         data-abo-admin-banners-modal>
        <div class="abo-admin-banners__modal-backdrop"
             data-abo-admin-banners-modal-close></div>
        <div class="abo-admin-banners__modal-card">
            <h2 class="abo-admin-banners__modal-title"
                id="abo-admin-banners-modal-title">DELETE BANNER</h2>
            <p class="abo-admin-banners__modal-body"
               data-abo-admin-banners-modal-body>Delete this banner? This can't be undone.</p>
            <div class="abo-admin-banners__modal-actions">
                <button type="button"
                        class="abo-admin-btn abo-admin-btn--ghost"
                        data-abo-admin-banners-modal-cancel>CANCEL</button>
                <button type="button"
                        class="abo-admin-banners__modal-confirm"
                        data-abo-admin-banners-modal-confirm>DELETE</button>
            </div>
        </div>
    </div>

    {{-- ─── Single shared toast ──────────────────────────────── --}}
    <div class="abo-admin-banners__toast"
         role="status"
         aria-live="polite"
         hidden
         data-abo-admin-banners-toast>
        <span class="abo-admin-banners__toast-dot abo-admin-banners__toast-dot--lime"
              aria-hidden="true"
              data-abo-admin-banners-toast-dot></span>
        <span data-abo-admin-banners-toast-text>Saved (demo)</span>
    </div>
@endsection

@push('head')
    {{-- Page-local stylesheet. Loaded only on /admin/banners. --}}
    <link rel="stylesheet" href="{{ asset('admin/css/banners.css') }}">
@endpush

@push('scripts')
    {{-- Server-supplied banner list, hydrated into the front-end
         BANNERS array by banners.js. See resources/views/admin/banners/index.blade.php. --}}
    <script>
        window.__BANNERS__ = {!! $bannersJson !!};
    </script>

    {{-- Page-local script. Loaded only on /admin/banners. --}}
    <script src="{{ asset('admin/js/banners.js') }}" defer></script>
@endpush

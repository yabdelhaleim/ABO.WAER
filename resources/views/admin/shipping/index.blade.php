{{--
    Admin · Shipping (DB-driven).

    Extends the existing admin layout. Rendered by
    App\Http\Controllers\Admin\ShippingController, which reads the
    shipping_zones / governorates tables via ShippingService and
    serialises the zone tree and the global settings for
    public/admin/js/shipping.js (see the @push('scripts') block at
    the bottom of this file).

    Page sections (top to bottom):
      1. Page header     — STORE eyebrow + SHIP / PING title +
                            lime "ADD ZONE" CTA on the right.
      2. Global settings — full-width card (free shipping, COD fee,
                            default fee, shipping note + EN/AR).
      3. Two columns     — Left: toolbar + tree + helper. Right: edit
                            panel (zone mode / governorate mode /
                            "no selection" placeholder).
      4. States          — Empty / no-results / loading skeleton.
      5. Delete modal    — one shared instance.
      6. Toast           — one shared instance.

    Conventions match admin/categories/index.blade.php: classes
    reuse the existing shell (abo-admin-*) where they fit; new
    shipping-only classes are prefixed `abo-admin-shipping-`.
--}}

@extends('admin.layouts.app')

@section('title', 'Shipping · Store')
@section('description', 'ABO — admin shipping manager: zones, governorates, fees, delivery times.')

@section('content')
    {{-- ─── Page header ────────────────────────────────────────── --}}
    <header class="abo-admin-page__head">
        <div class="abo-admin-page__head-text">
            <p class="abo-admin-page__eyebrow">
                <span class="abo-admin-page__eyebrow-line" aria-hidden="true"></span>
                <span>STORE</span>
            </p>
            <h1 class="abo-admin-page__title">
                <span>SHIP</span><span class="abo-admin-shipping__title-lime">PING</span>
            </h1>
        </div>

        <div class="abo-admin-page__head-actions">
            <button type="button"
                    class="abo-admin-btn abo-admin-btn--primary"
                    data-abo-admin-shipping-add>
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="1.8"
                          fill="none" stroke-linecap="round"/>
                </svg>
                + ADD ZONE
            </button>
        </div>
    </header>

    {{-- ─── Global settings card ────────────────────────────────── --}}
    <section class="abo-admin-card abo-admin-shipping__settings"
             aria-label="Global shipping settings"
             data-abo-admin-shipping-settings>
        <div class="abo-admin-shipping__settings-head">
            <h2 class="abo-admin-shipping__settings-title">
                <span>GLOBAL SETTINGS</span>
                <span class="abo-admin-shipping__dirty-dot"
                      aria-label="Unsaved settings changes"
                      data-abo-admin-shipping-settings-dirty-dot></span>
            </h2>
            <button type="button"
                    class="abo-admin-btn abo-admin-btn--primary abo-admin-shipping__settings-save"
                    data-abo-admin-shipping-settings-save>
                SAVE SETTINGS
            </button>
        </div>

        <div class="abo-admin-shipping__settings-grid">

            {{-- Free shipping toggle + threshold (EGP) --}}
            <div class="abo-admin-shipping__settings-cell">
                <div class="abo-admin-shipping__settings-row">
                    <div class="abo-admin-shipping__settings-label">
                        <label for="abo-admin-shipping-free-shipping">Free shipping</label>
                        <p class="abo-admin-shipping__settings-hint">Offer free shipping above a cart total.</p>
                    </div>
                    <label class="abo-admin-shipping__toggle" aria-label="Free shipping">
                        <input id="abo-admin-shipping-free-shipping"
                               type="checkbox"
                               checked
                               aria-checked="true"
                               data-abo-admin-shipping-free-shipping>
                        <span class="abo-admin-shipping__toggle-track"></span>
                        <span class="abo-admin-shipping__toggle-thumb"></span>
                    </label>
                </div>
                <div class="abo-admin-shipping__settings-row abo-admin-shipping__settings-row--inline"
                     data-abo-admin-shipping-threshold-wrap>
                    <label for="abo-admin-shipping-threshold"
                           class="abo-admin-shipping__settings-sublabel">Free shipping above</label>
                    <div class="abo-admin-shipping__currency">
                        <span class="abo-admin-shipping__currency-prefix" aria-hidden="true">EGP</span>
                        <input id="abo-admin-shipping-threshold"
                               type="text"
                               inputmode="numeric"
                               value="1500"
                               autocomplete="off"
                               class="abo-admin-shipping__number"
                               data-abo-admin-shipping-threshold>
                    </div>
                    <p class="abo-admin-shipping__field-error"
                       data-abo-admin-shipping-threshold-error
                       hidden>Enter a valid amount</p>
                </div>
            </div>

            {{-- Cash on delivery fee --}}
            <div class="abo-admin-shipping__settings-cell">
                <div class="abo-admin-shipping__settings-row">
                    <div class="abo-admin-shipping__settings-label">
                        <label for="abo-admin-shipping-cod-fee">Cash on delivery fee</label>
                        <p class="abo-admin-shipping__settings-hint">Extra fee added at checkout for COD orders.</p>
                    </div>
                    <div class="abo-admin-shipping__currency">
                        <span class="abo-admin-shipping__currency-prefix" aria-hidden="true">EGP</span>
                        <input id="abo-admin-shipping-cod-fee"
                               type="text"
                               inputmode="numeric"
                               value="20"
                               autocomplete="off"
                               class="abo-admin-shipping__number"
                               data-abo-admin-shipping-cod-fee>
                    </div>
                </div>
                <p class="abo-admin-shipping__field-error"
                   data-abo-admin-shipping-cod-fee-error
                   hidden>Enter a valid amount</p>
            </div>

            {{-- Default shipping fee --}}
            <div class="abo-admin-shipping__settings-cell">
                <div class="abo-admin-shipping__settings-row">
                    <div class="abo-admin-shipping__settings-label">
                        <label for="abo-admin-shipping-default-fee">Default shipping fee</label>
                        <p class="abo-admin-shipping__settings-hint">Used for any governorate without its own fee.</p>
                    </div>
                    <div class="abo-admin-shipping__currency">
                        <span class="abo-admin-shipping__currency-prefix" aria-hidden="true">EGP</span>
                        <input id="abo-admin-shipping-default-fee"
                               type="text"
                               inputmode="numeric"
                               value="75"
                               autocomplete="off"
                               class="abo-admin-shipping__number"
                               data-abo-admin-shipping-default-fee>
                    </div>
                </div>
                <p class="abo-admin-shipping__field-error"
                   data-abo-admin-shipping-default-fee-error
                   hidden>Enter a valid amount</p>
            </div>

            {{-- Shipping note + EN/AR switch --}}
            <div class="abo-admin-shipping__settings-cell">
                <div class="abo-admin-shipping__settings-label">
                    <label for="abo-admin-shipping-note">Shipping note shown at checkout</label>
                    <p class="abo-admin-shipping__settings-hint">Short message customers see under the shipping options.</p>
                </div>
                <div class="abo-admin-shipping__lang"
                     role="tablist"
                     aria-label="Language"
                     data-abo-admin-shipping-note-lang>
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
                <textarea id="abo-admin-shipping-note"
                          placeholder="e.g. Delivery within 2 to 5 working days."
                          data-abo-admin-shipping-note>Delivery within 2 to 5 working days.</textarea>
                <p class="abo-admin-shipping__settings-hint abo-admin-shipping__settings-hint--soft"
                   data-abo-admin-shipping-note-hint>
                    Showing the English text at checkout.
                </p>
            </div>

        </div>
    </section>

    {{-- ─── Two-column layout ───────────────────────────────────── --}}
    <div class="abo-admin-shipping__layout"
         data-abo-admin-shipping
         aria-label="Shipping zones and governorates">

        {{-- Left: tree + toolbar --}}
        <section class="abo-admin-shipping__col" aria-label="Zones and governorates">
            <div class="abo-admin-card abo-admin-shipping__toolbar">
                <label class="sr-only" for="abo-admin-shipping-search">Filter zones</label>
                <div class="abo-admin-shipping__search">
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"
                         class="abo-admin-shipping__search-icon">
                        <path d="M21 20.3 16.7 16a8 8 0 1 0-1.4 1.4L20.3 21 21 20.3zM4 10a6 6 0 1 1 12 0 6 6 0 0 1-12 0z"
                              fill="currentColor"/>
                    </svg>
                    <input id="abo-admin-shipping-search"
                           type="search"
                           placeholder="Search by zone or governorate…"
                           autocomplete="off"
                           data-abo-admin-shipping-search>
                </div>

                <button type="button"
                        class="abo-admin-btn abo-admin-btn--ghost"
                        aria-pressed="false"
                        data-abo-admin-shipping-expand-all>
                    EXPAND ALL
                </button>
            </div>

            <article class="abo-admin-card abo-admin-shipping__tree-card"
                     aria-label="Zones and governorates">
                {{-- The tree is rendered by public/admin/js/shipping.js
                     from the ZONES static array. Every <li role="treeitem">
                     gets full keyboard support (Arrow keys, Enter, Space). --}}
                <ul class="abo-admin-shipping__tree"
                    role="tree"
                    aria-label="Zones and governorates"
                    data-abo-admin-shipping-tree></ul>

                {{-- Helper text under the tree (shown only when a tree is visible). --}}
                <p class="abo-admin-shipping__helper"
                   data-abo-admin-shipping-helper>
                    Each governorate uses its zone fee unless you set its own fee.
                </p>

                {{-- States: skeleton / empty / no-results are mutually exclusive
                     and toggled by shipping.js. The skeleton is the default. --}}
                <div class="abo-admin-shipping__state" data-abo-admin-shipping-state="empty" hidden>
                    <p>No shipping zones yet. Add your first zone.</p>
                    <button type="button"
                            class="abo-admin-btn abo-admin-btn--primary"
                            data-abo-admin-shipping-add-empty>
                        + ADD ZONE
                    </button>
                </div>

                <div class="abo-admin-shipping__state" data-abo-admin-shipping-state="noresults" hidden>
                    <p>No results. Try clearing the search above.</p>
                </div>

                <div class="abo-admin-shipping__state" data-abo-admin-shipping-state="loading"
                     data-abo-admin-shipping-skeleton>
                    <div class="abo-admin-shipping__skeleton" aria-hidden="true">
                        @for ($i = 0; $i < 4; $i++)
                            <div class="abo-admin-shipping__skeleton-row">
                                <span class="abo-admin-shipping__skeleton-cell" style="width: 18px; height: 18px;"></span>
                                <span class="abo-admin-shipping__skeleton-cell" style="width: 60%;"></span>
                                <span class="abo-admin-shipping__skeleton-cell" style="width: 70px;"></span>
                                <span class="abo-admin-shipping__skeleton-cell" style="width: 60px;"></span>
                                <span class="abo-admin-shipping__skeleton-cell abo-admin-shipping__skeleton-cell--toggle"></span>
                            </div>
                        @endfor
                    </div>
                </div>
            </article>
        </section>

        {{-- Right: edit panel (full card on desktop, slide-over on tablet/mobile) --}}
        <aside class="abo-admin-shipping__col abo-admin-shipping__panel"
               aria-label="Edit zone or governorate"
               data-abo-admin-shipping-panel>

            {{-- Slide-over backdrop on tablet/mobile --}}
            <div class="abo-admin-shipping__panel-overlay"
                 hidden
                 data-abo-admin-shipping-panel-overlay
                 aria-hidden="true"></div>

            {{-- "No selection" state --}}
            <div class="abo-admin-shipping__panel-empty"
                 data-abo-admin-shipping-panel-no-selection>
                <p style="margin:0 0 0.4rem;color:var(--abo-admin-text);font-weight:600;">No zone or governorate selected.</p>
                <p style="margin:0;">Click a row on the left, or add a new zone.</p>
            </div>

            {{-- Form (toggled by shipping.js once a row is selected) --}}
            <div data-abo-admin-shipping-panel-form hidden>

                <div class="abo-admin-shipping__panel-head">
                    <h2 class="abo-admin-shipping__panel-title">
                        <span data-abo-admin-shipping-panel-title-text>EDIT ZONE</span>
                        <span class="abo-admin-shipping__dirty-dot"
                              aria-label="Unsaved changes"
                              data-abo-admin-shipping-dirty-dot></span>
                    </h2>
                    <button type="button"
                            class="abo-admin-shipping__panel-close"
                            aria-label="Close edit panel"
                            data-abo-admin-shipping-panel-close>
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M6 6l12 12M18 6 6 18"
                                  stroke="currentColor" stroke-width="1.6"
                                  fill="none" stroke-linecap="round"/>
                        </svg>
                    </button>
                </div>

                <div class="abo-admin-shipping__panel-body">

                    {{-- Zone name (English + Arabic + lang pill) --}}
                    <div class="abo-admin-shipping__field" data-abo-admin-shipping-field="zone-name">
                        <div class="abo-admin-shipping__field-head">
                            <label for="abo-admin-shipping-zone-name-en">Zone name</label>
                            <div class="abo-admin-shipping__lang"
                                 role="tablist"
                                 aria-label="Zone name language"
                                 data-abo-admin-shipping-zone-lang>
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
                        </div>
                        <label class="sr-only" for="abo-admin-shipping-zone-name-en">Zone name (English)</label>
                        <input id="abo-admin-shipping-zone-name-en"
                               type="text"
                               placeholder="e.g. Greater Cairo"
                               autocomplete="off"
                               data-abo-admin-shipping-zone-name
                               data-lang="en">
                        <label class="sr-only" for="abo-admin-shipping-zone-name-ar">Zone name (Arabic)</label>
                        <input id="abo-admin-shipping-zone-name-ar"
                               type="text"
                               placeholder="مثال: القاهرة الكبرى"
                               autocomplete="off"
                               dir="rtl"
                               data-abo-admin-shipping-zone-name
                               data-lang="ar"
                               hidden>
                    </div>

                    {{-- Shipping fee (EGP) --}}
                    <div class="abo-admin-shipping__field" data-abo-admin-shipping-field="zone-fee">
                        <label for="abo-admin-shipping-zone-fee">Shipping fee</label>
                        <div class="abo-admin-shipping__currency">
                            <span class="abo-admin-shipping__currency-prefix" aria-hidden="true">EGP</span>
                            <input id="abo-admin-shipping-zone-fee"
                                   type="text"
                                   inputmode="numeric"
                                   value="50"
                                   autocomplete="off"
                                   class="abo-admin-shipping__number"
                                   data-abo-admin-shipping-zone-fee>
                        </div>
                        <p class="abo-admin-shipping__field-error"
                           data-abo-admin-shipping-zone-fee-error
                           hidden>Enter a valid amount</p>
                    </div>

                    {{-- Delivery time (From / To days) --}}
                    <div class="abo-admin-shipping__field" data-abo-admin-shipping-field="zone-time">
                        <label>Delivery time</label>
                        <div class="abo-admin-shipping__range">
                            <div class="abo-admin-shipping__range-cell">
                                <span class="abo-admin-shipping__range-label">From</span>
                                <input type="text"
                                       inputmode="numeric"
                                       value="1"
                                       autocomplete="off"
                                       class="abo-admin-shipping__number"
                                       data-abo-admin-shipping-time-from
                                       aria-label="Delivery from days">
                                <span class="abo-admin-shipping__range-suffix">days</span>
                            </div>
                            <div class="abo-admin-shipping__range-cell">
                                <span class="abo-admin-shipping__range-label">To</span>
                                <input type="text"
                                       inputmode="numeric"
                                       value="2"
                                       autocomplete="off"
                                       class="abo-admin-shipping__number"
                                       data-abo-admin-shipping-time-to
                                       aria-label="Delivery to days">
                                <span class="abo-admin-shipping__range-suffix">days</span>
                            </div>
                        </div>
                        <p class="abo-admin-shipping__field-error"
                           data-abo-admin-shipping-time-error
                           hidden>From must be less than To</p>
                    </div>

                    {{-- Active switch (zone) --}}
                    <div class="abo-admin-shipping__switch"
                         data-abo-admin-shipping-switch="zone-active">
                        <div>
                            <span class="abo-admin-shipping__switch-label">Active</span>
                            <p class="abo-admin-shipping__switch-hint">Inactive zones skip their governorates during checkout.</p>
                        </div>
                        <label class="abo-admin-shipping__toggle" aria-label="Active">
                            <input type="checkbox"
                                   checked
                                   aria-checked="true"
                                   data-abo-admin-shipping-zone-active>
                            <span class="abo-admin-shipping__toggle-track"></span>
                            <span class="abo-admin-shipping__toggle-thumb"></span>
                        </label>
                    </div>

                    {{-- Governorates list (zone mode only) --}}
                    <div class="abo-admin-shipping__field" data-abo-admin-shipping-field="zone-chips">
                        <label>Governorates in this zone</label>
                        <ul class="abo-admin-shipping__chips"
                            data-abo-admin-shipping-zone-chips></ul>
                    </div>

                    {{-- Governorate read-only header --}}
                    <div class="abo-admin-shipping__field" data-abo-admin-shipping-field="gov-header" hidden>
                        <label>Governorate</label>
                        <p class="abo-admin-shipping__gov-name"
                           data-abo-admin-shipping-gov-name>—</p>
                    </div>

                    {{-- Zone select (governorate mode only) --}}
                    <div class="abo-admin-shipping__field" data-abo-admin-shipping-field="gov-zone" hidden>
                        <label for="abo-admin-shipping-gov-zone-select">Zone</label>
                        <label class="sr-only" for="abo-admin-shipping-gov-zone-select">Move to zone</label>
                        <select id="abo-admin-shipping-gov-zone-select"
                                data-abo-admin-shipping-gov-zone></select>
                    </div>

                    {{-- Fee override (governorate mode only) --}}
                    <div class="abo-admin-shipping__field" data-abo-admin-shipping-field="gov-fee" hidden>
                        <label>Fee</label>
                        <div class="abo-admin-shipping__radios">
                            <label class="abo-admin-shipping__radio">
                                <input type="radio"
                                       name="abo-admin-shipping-gov-fee-mode"
                                       value="inherit"
                                       checked
                                       data-abo-admin-shipping-gov-fee-mode>
                                <span class="abo-admin-shipping__radio-mark" aria-hidden="true"></span>
                                <span class="abo-admin-shipping__radio-text"
                                      data-abo-admin-shipping-gov-fee-inherit>Use zone fee (EGP 50)</span>
                            </label>
                            <label class="abo-admin-shipping__radio">
                                <input type="radio"
                                       name="abo-admin-shipping-gov-fee-mode"
                                       value="custom"
                                       data-abo-admin-shipping-gov-fee-mode>
                                <span class="abo-admin-shipping__radio-mark" aria-hidden="true"></span>
                                <span class="abo-admin-shipping__radio-text">Custom fee</span>
                            </label>
                        </div>
                        <div class="abo-admin-shipping__currency"
                             data-abo-admin-shipping-gov-fee-wrap
                             hidden>
                            <span class="abo-admin-shipping__currency-prefix" aria-hidden="true">EGP</span>
                            <input type="text"
                                   inputmode="numeric"
                                   value=""
                                   autocomplete="off"
                                   placeholder="0"
                                   class="abo-admin-shipping__number"
                                   aria-label="Custom shipping fee"
                                   data-abo-admin-shipping-gov-fee>
                        </div>
                        <p class="abo-admin-shipping__field-error"
                           data-abo-admin-shipping-gov-fee-error
                           hidden>Enter a valid amount</p>
                    </div>

                    {{-- Delivery time override (governorate mode only) --}}
                    <div class="abo-admin-shipping__field" data-abo-admin-shipping-field="gov-time" hidden>
                        <label>Delivery time override</label>
                        <div class="abo-admin-shipping__range">
                            <div class="abo-admin-shipping__range-cell">
                                <span class="abo-admin-shipping__range-label">From</span>
                                <input type="text"
                                       inputmode="numeric"
                                       value=""
                                       autocomplete="off"
                                       placeholder="—"
                                       class="abo-admin-shipping__number"
                                       aria-label="Override from days"
                                       data-abo-admin-shipping-gov-time-from>
                                <span class="abo-admin-shipping__range-suffix">days</span>
                            </div>
                            <div class="abo-admin-shipping__range-cell">
                                <span class="abo-admin-shipping__range-label">To</span>
                                <input type="text"
                                       inputmode="numeric"
                                       value=""
                                       autocomplete="off"
                                       placeholder="—"
                                       class="abo-admin-shipping__number"
                                       aria-label="Override to days"
                                       data-abo-admin-shipping-gov-time-to>
                                <span class="abo-admin-shipping__range-suffix">days</span>
                            </div>
                        </div>
                        <p class="abo-admin-shipping__field-error"
                           data-abo-admin-shipping-gov-time-error
                           hidden>From must be less than To</p>
                    </div>

                    {{-- COD switch (governorate mode only) --}}
                    <div class="abo-admin-shipping__switch"
                         data-abo-admin-shipping-switch="gov-cod"
                         hidden>
                        <div>
                            <span class="abo-admin-shipping__switch-label">Cash on delivery available</span>
                            <p class="abo-admin-shipping__switch-hint">Allow COD for customers in this governorate.</p>
                        </div>
                        <label class="abo-admin-shipping__toggle" aria-label="Cash on delivery available">
                            <input type="checkbox"
                                   checked
                                   aria-checked="true"
                                   data-abo-admin-shipping-gov-cod>
                            <span class="abo-admin-shipping__toggle-track"></span>
                            <span class="abo-admin-shipping__toggle-thumb"></span>
                        </label>
                    </div>

                    {{-- Active switch (governorate mode) --}}
                    <div class="abo-admin-shipping__switch"
                         data-abo-admin-shipping-switch="gov-active"
                         hidden>
                        <div>
                            <span class="abo-admin-shipping__switch-label">Active</span>
                            <p class="abo-admin-shipping__switch-hint">Inactive governorates are skipped at checkout.</p>
                        </div>
                        <label class="abo-admin-shipping__toggle" aria-label="Governorate active">
                            <input type="checkbox"
                                   checked
                                   aria-checked="true"
                                   data-abo-admin-shipping-gov-active>
                            <span class="abo-admin-shipping__toggle-track"></span>
                            <span class="abo-admin-shipping__toggle-thumb"></span>
                        </label>
                    </div>

                    {{-- Action buttons --}}
                    <div class="abo-admin-shipping__actions">
                        <button type="button"
                                class="abo-admin-btn abo-admin-btn--primary"
                                data-abo-admin-shipping-save>
                            SAVE
                        </button>
                        <button type="button"
                                class="abo-admin-shipping__delete"
                                aria-label="Delete zone"
                                data-abo-admin-shipping-delete>
                            DELETE
                        </button>
                    </div>
                    <p class="abo-admin-shipping__delete-note"
                       data-abo-admin-shipping-delete-note
                       hidden></p>
                </div>
            </div>
        </aside>
    </div>

    {{-- ─── Single shared delete modal ──────────────────────────── --}}
    <div class="abo-admin-shipping__modal"
         role="dialog"
         aria-modal="true"
         aria-labelledby="abo-admin-shipping-modal-title"
         hidden
         data-abo-admin-shipping-modal>
        <div class="abo-admin-shipping__modal-backdrop"
             data-abo-admin-shipping-modal-close></div>
        <div class="abo-admin-shipping__modal-card">
            <h2 class="abo-admin-shipping__modal-title"
                id="abo-admin-shipping-modal-title">DELETE ZONE</h2>
            <p class="abo-admin-shipping__modal-body"
               data-abo-admin-shipping-modal-body>Are you sure? This action cannot be undone.</p>
            <div class="abo-admin-shipping__modal-actions">
                <button type="button"
                        class="abo-admin-btn abo-admin-btn--ghost"
                        data-abo-admin-shipping-modal-cancel>CANCEL</button>
                <button type="button"
                        class="abo-admin-shipping__modal-confirm"
                        data-abo-admin-shipping-modal-confirm>DELETE</button>
            </div>
        </div>
    </div>

    {{-- ─── Single shared toast ────────────────────────────────── --}}
    <div class="abo-admin-shipping__toast"
         role="status"
         aria-live="polite"
         hidden
         data-abo-admin-shipping-toast>
        <span class="abo-admin-shipping__toast-dot abo-admin-shipping__toast-dot--lime"
              aria-hidden="true"
              data-abo-admin-shipping-toast-dot></span>
        <span data-abo-admin-shipping-toast-text>Saved (demo)</span>
    </div>
@endsection

@push('head')
    {{-- Page-local stylesheet. Loaded only on the shipping page. --}}
    <link rel="stylesheet" href="{{ asset('admin/css/shipping.css') }}">
@endpush

@push('scripts')
    {{-- Server-supplied zone tree and global settings, hydrated into the
         ZONES array and settings object by shipping.js. The inline script
         runs before the deferred page script, so the globals are always
         defined by the time shipping.js boots. --}}
    <script>
        window.__SHIPPING_ZONES__ = {!! $zonesJson !!};
        window.__SHIPPING_SETTINGS__ = {!! $settingsJson !!};
    </script>

    {{-- Page-local script. Loaded only on the shipping page. --}}
    <script src="{{ asset('admin/js/shipping.js') }}" defer></script>
@endpush
{{--
    Admin · Settings (front-end only, static data).

    Page sections (sub-nav on the left, active panel on the right):
      1. store-info       — name, logo & favicon, contact, social
      2. orders           — minimum order, auto-cancel, guest checkout, prefix
      3. inventory        — low-stock threshold, hide out-of-stock
      4. cancellation     — drag-reorderable reasons (EN/AR), active toggle
      5. receipts         — logo toggle, header + footer text, fields to show,
                            paper size, live receipt preview
      6. notifications    — bell item toggles + browser sound
      7. danger-zone      — maintenance mode (inside Store info, as a banner card)

    Layout: two columns (220px sticky sub-nav + content). On mobile the
    sub-nav collapses into a horizontal scrollable tab strip kept just
    under the page header. The sticky bottom bar appears whenever the
    user edits any field, with Discard + SAVE.

    Save is visual only — settings.js shows a "Saved (demo)" toast and
    the bottom bar vanishes. No backend / network call exists.

    Reuses the existing admin shell: .abo-admin-page, .abo-admin-card,
    .abo-admin-btn, .abo-admin-segmented, .abo-admin-attention__card,
    the brand tokens, and the sitewide :focus-visible lime ring.
    New classes are prefixed abo-admin-settings-.
--}}

@extends('admin.layouts.app')

@section('title', 'Settings')
@section('description', 'ABO — admin settings: store info, orders, inventory, cancellation reasons, receipts, notifications.')

@php
    // TODO(backend): replace every value below with data fetched
    // server-side from the database. The view shape is final; only
    // the data source changes. The JS hydrates the values into the
    // DOM at boot so the form can show real values from day one.

    $storeInfo = [
        'name'    => 'ABO',
        'phone'   => '+20 100 123 4567',
        'whatsapp'=> '+20 100 123 4567',
        'email'   => 'hello@abo.store',
        'address' => '15 El Horreya St., Cairo, Egypt',
        'social'  => [
            'instagram' => 'instagram.com/abo.store',
            'facebook'  => 'facebook.com/abo.store',
            'tiktok'    => 'tiktok.com/@abo.store',
        ],
        'maintenance' => false,
    ];

    $orders = [
        'minimum'        => 500,
        'autoCancelHours'=> 24,
        'guestCheckout'  => true,
        'prefix'         => 'ABO-',
        'nextNumber'     => 1043,
    ];

    $inventory = [
        'lowStockThreshold'  => 5,
        'hideOutOfStock'     => false,
    ];

    $cancellationReasons = [
        ['id' => 'r1', 'en' => 'Changed my mind',      'ar' => 'غيّرت رأيي',          'active' => true],
        ['id' => 'r2', 'en' => 'Wrong size ordered',   'ar' => 'مقاس خاطئ',           'active' => true],
        ['id' => 'r3', 'en' => 'Found a better price', 'ar' => 'وجدت سعراً أفضل',     'active' => true],
        ['id' => 'r4', 'en' => 'Delivery too slow',    'ar' => 'التسليم بطيء',         'active' => true],
        ['id' => 'r5', 'en' => 'Other',                'ar' => 'سبب آخر',              'active' => true],
    ];

    $receipts = [
        'showLogo'       => true,
        'headerText'     => 'ABO Store',
        'footerNote'     => 'Thank you for shopping with us — returns within 14 days.',
        'fields'         => [
            'orderNumber'   => true,
            'date'          => true,
            'customerPhone' => true,
            'address'       => false,
            'paymentMethod' => true,
            'shippingFee'   => true,
        ],
        'paper'          => 'a4', // 'a4' | 'thermal'
    ];

    $notifications = [
        'newOrder'        => true,
        'lowStock'        => true,
        'returnRequest'   => true,
        'shipmentFailed'  => false,
        'couponExpiring'  => false,
        'browserSound'    => true,
    ];

    $sections = [
        ['id' => 'store-info',      'label' => 'STORE INFO',      'num' => '01'],
        ['id' => 'orders',          'label' => 'ORDERS',          'num' => '02'],
        ['id' => 'inventory',       'label' => 'INVENTORY',       'num' => '03'],
        ['id' => 'cancellation',    'label' => 'CANCELLATION',    'num' => '04'],
        ['id' => 'receipts',        'label' => 'RECEIPTS',        'num' => '05'],
        ['id' => 'notifications',   'label' => 'NOTIFICATIONS',   'num' => '06'],
    ];
@endphp

@section('content')
    {{-- ─── Page header ──────────────────────────────────────────── --}}
    <header class="abo-admin-page__head">
        <div class="abo-admin-page__head-text">
            <p class="abo-admin-page__eyebrow">
                <span class="abo-admin-page__eyebrow-line" aria-hidden="true"></span>
                <span>SYSTEM</span>
            </p>
            <h1 class="abo-admin-page__title">
                <span>SET</span><span style="color: var(--abo-lime); margin-left: 0.15em;">TINGS</span>
            </h1>
            <p class="abo-admin-page__date abo-admin-settings__sub" id="abo-admin-today-date">Loading date…</p>
        </div>

        <div class="abo-admin-page__head-actions">
            <button type="button"
                    class="abo-admin-btn abo-admin-btn--ghost"
                    data-abo-admin-settings-action="restore">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M3 12a9 9 0 1 0 3-6.7M3 4v5h5"
                          stroke="currentColor" stroke-width="1.6" fill="none"
                          stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                RESTORE DEFAULTS
            </button>
        </div>
    </header>

    {{-- ─── Loading skeleton (replaced by JS on boot) ────────────── --}}
    <section class="abo-admin-settings__skeleton" data-abo-admin-settings-skeleton aria-hidden="true">
        <div class="abo-admin-settings__skeleton-grid">
            <div class="abo-admin-settings__skeleton-cell"></div>
            <div class="abo-admin-settings__skeleton-cell"></div>
            <div class="abo-admin-settings__skeleton-cell"></div>
            <div class="abo-admin-settings__skeleton-cell abo-admin-settings__skeleton-cell--lg"></div>
            <div class="abo-admin-settings__skeleton-cell"></div>
            <div class="abo-admin-settings__skeleton-cell"></div>
        </div>
    </section>

    {{-- ─── Settings shell (visible after hydration) ─────────────── --}}
    <section class="abo-admin-settings"
             hidden
             data-abo-admin-settings-root
             data-store-info='@json($storeInfo)'
             data-orders='@json($orders)'
             data-inventory='@json($inventory)'
             data-reasons='@json($cancellationReasons)'
             data-receipts='@json($receipts)'
             data-notifications='@json($notifications)'>

        {{-- ─── Left: vertical sub-nav (becomes horizontal on mobile) --}}
        <nav class="abo-admin-settings__nav"
             aria-label="Settings sections"
             data-abo-admin-settings-nav>
            <p class="abo-admin-settings__nav-label">SECTIONS</p>
            <ul class="abo-admin-settings__nav-list" role="tablist" orientation="vertical">
                @foreach ($sections as $i => $sec)
                    <li>
                        <a href="#{{ $sec['id'] }}"
                           id="abo-admin-settings-nav-tab-{{ $sec['id'] }}"
                           class="abo-admin-settings__nav-item @if ($i === 0) is-active @endif"
                           role="tab"
                           aria-selected="{{ $i === 0 ? 'true' : 'false' }}"
                           aria-controls="abo-admin-settings-panel-{{ $sec['id'] }}"
                           data-abo-admin-settings-tab="{{ $sec['id'] }}">
                            <span class="abo-admin-settings__nav-num">{{ $sec['num'] }}</span>
                            <span class="abo-admin-settings__nav-text">{{ $sec['label'] }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>

            <p class="abo-admin-settings__nav-meta">
                <span aria-hidden="true">●</span>
                <span data-abo-admin-settings-status>Everything is up to date</span>
            </p>
        </nav>

        {{-- ─── Right: stacked section panels (one is visible at a time) --}}
        <div class="abo-admin-settings__panels">

            {{-- 1. STORE INFO ─────────────────────────────────────────── --}}
            <article class="abo-admin-card abo-admin-settings__panel is-active"
                     id="abo-admin-settings-panel-store-info"
                     role="tabpanel"
                     aria-labelledby="abo-admin-settings-nav-tab-store-info"
                     data-abo-admin-settings-panel="store-info">
                <header class="abo-admin-card__head">
                    <div>
                        <p class="abo-admin-settings__eyebrow">
                            <span class="abo-admin-settings__eyebrow-line" aria-hidden="true"></span>
                            <span>01 — IDENTITY</span>
                        </p>
                        <h2 class="abo-admin-card__title">STORE INFO</h2>
                    </div>
                    <p class="abo-admin-card__soft">Visible on the storefront, receipts and invoices.</p>
                </header>

                <div class="abo-admin-settings__grid">
                    <div class="abo-admin-settings__field">
                        <label for="abo-admin-settings-store-name">Store name</label>
                        <input id="abo-admin-settings-store-name"
                               type="text"
                               required
                               autocomplete="off"
                               data-abo-admin-settings-field="store.name">
                        <p class="abo-admin-settings__error" hidden>Required.</p>
                    </div>

                    <div class="abo-admin-settings__field">
                        <label for="abo-admin-settings-phone">Phone</label>
                        <input id="abo-admin-settings-phone"
                               type="tel"
                               inputmode="tel"
                               autocomplete="off"
                               data-abo-admin-settings-field="store.phone">
                    </div>

                    <div class="abo-admin-settings__field">
                        <label for="abo-admin-settings-whatsapp">WhatsApp</label>
                        <input id="abo-admin-settings-whatsapp"
                               type="tel"
                               inputmode="tel"
                               autocomplete="off"
                               data-abo-admin-settings-field="store.whatsapp">
                    </div>

                    <div class="abo-admin-settings__field">
                        <label for="abo-admin-settings-email">Email</label>
                        <input id="abo-admin-settings-email"
                               type="email"
                               autocomplete="off"
                               data-abo-admin-settings-field="store.email">
                    </div>

                    <div class="abo-admin-settings__field abo-admin-settings__field--full">
                        <label for="abo-admin-settings-address">Address</label>
                        <textarea id="abo-admin-settings-address"
                                  rows="2"
                                  data-abo-admin-settings-field="store.address"></textarea>
                    </div>
                </div>

                <hr class="abo-admin-settings__rule">

                <h3 class="abo-admin-settings__subhead">BRANDING</h3>

                <div class="abo-admin-settings__grid">
                    <div class="abo-admin-settings__field">
                        <label for="abo-admin-settings-store-logo">Store logo</label>
                        <label class="abo-admin-settings__file" for="abo-admin-settings-store-logo">
                            <span class="abo-admin-settings__file-preview" data-abo-admin-settings-preview="store.logo" aria-hidden="true">ABO</span>
                            <span class="abo-admin-settings__file-meta">
                                <span class="abo-admin-settings__file-name" data-abo-admin-settings-filename="store.logo">No file selected</span>
                                <span class="abo-admin-settings__file-hint">PNG, JPG or SVG · up to 2 MB</span>
                            </span>
                            <span class="abo-admin-settings__file-btn">CHANGE</span>
                        </label>
                        <input id="abo-admin-settings-store-logo"
                               type="file"
                               accept="image/png,image/jpeg,image/svg+xml"
                               hidden
                               data-abo-admin-settings-file="store.logo">
                    </div>

                    <div class="abo-admin-settings__field">
                        <label for="abo-admin-settings-store-favicon">Favicon</label>
                        <label class="abo-admin-settings__file" for="abo-admin-settings-store-favicon">
                            <span class="abo-admin-settings__file-preview abo-admin-settings__file-preview--square" data-abo-admin-settings-preview="store.favicon" aria-hidden="true">A</span>
                            <span class="abo-admin-settings__file-meta">
                                <span class="abo-admin-settings__file-name" data-abo-admin-settings-filename="store.favicon">No file selected</span>
                                <span class="abo-admin-settings__file-hint">ICO, PNG · square, 32×32 or 64×64</span>
                            </span>
                            <span class="abo-admin-settings__file-btn">CHANGE</span>
                        </label>
                        <input id="abo-admin-settings-store-favicon"
                               type="file"
                               accept="image/png,image/x-icon,image/svg+xml"
                               hidden
                               data-abo-admin-settings-file="store.favicon">
                    </div>
                </div>

                <hr class="abo-admin-settings__rule">

                <h3 class="abo-admin-settings__subhead">SOCIAL LINKS</h3>

                <div class="abo-admin-settings__grid">
                    <div class="abo-admin-settings__field">
                        <label for="abo-admin-settings-social-instagram">Instagram</label>
                        <input id="abo-admin-settings-social-instagram"
                               type="url"
                               autocomplete="off"
                               placeholder="instagram.com/your-store"
                               data-abo-admin-settings-field="store.social.instagram">
                    </div>
                    <div class="abo-admin-settings__field">
                        <label for="abo-admin-settings-social-facebook">Facebook</label>
                        <input id="abo-admin-settings-social-facebook"
                               type="url"
                               autocomplete="off"
                               placeholder="facebook.com/your-store"
                               data-abo-admin-settings-field="store.social.facebook">
                    </div>
                    <div class="abo-admin-settings__field">
                        <label for="abo-admin-settings-social-tiktok">TikTok</label>
                        <input id="abo-admin-settings-social-tiktok"
                               type="url"
                               autocomplete="off"
                               placeholder="tiktok.com/@your-store"
                               data-abo-admin-settings-field="store.social.tiktok">
                    </div>
                </div>

                <hr class="abo-admin-settings__rule">

                {{-- ─── Danger zone (inside Store info, per spec) ──────── --}}
                <article class="abo-admin-settings__danger"
                         aria-labelledby="abo-admin-settings-danger-title">
                    <header class="abo-admin-settings__danger-head">
                        <h3 id="abo-admin-settings-danger-title" class="abo-admin-settings__danger-title">DANGER ZONE</h3>
                        <p class="abo-admin-settings__danger-soft">Actions here affect the live storefront.</p>
                    </header>

                    <div class="abo-admin-settings__danger-body" data-abo-admin-settings-maintenance
                         data-active="{{ $storeInfo['maintenance'] ? 'true' : 'false' }}">
                        <div>
                            <p class="abo-admin-settings__danger-label">Maintenance mode</p>
                            <p class="abo-admin-settings__danger-helper">
                                Hides the storefront from customers and shows a one-line maintenance page.
                                Orders keep coming in through the admin.
                            </p>
                        </div>
                        <label class="abo-admin-settings__toggle"
                               aria-label="Toggle maintenance mode">
                            <input type="checkbox"
                                   role="switch"
                                   aria-checked="{{ $storeInfo['maintenance'] ? 'true' : 'false' }}"
                                   @checked($storeInfo['maintenance'])
                                   data-abo-admin-settings-field="store.maintenance"
                                   data-abo-admin-settings-confirm="maintenance">
                            <span class="abo-admin-settings__toggle-track" aria-hidden="true"></span>
                            <span class="abo-admin-settings__toggle-thumb" aria-hidden="true"></span>
                        </label>
                    </div>

                    <p class="abo-admin-settings__banner abo-admin-settings__banner--coral"
                       hidden
                       data-abo-admin-settings-banner="maintenance">
                        <span class="abo-admin-settings__banner-dot" aria-hidden="true"></span>
                        <span>Maintenance mode is ON. The storefront is hidden from customers.</span>
                    </p>
                </article>
            </article>

            {{-- 2. ORDERS ─────────────────────────────────────────────── --}}
            <article class="abo-admin-card abo-admin-settings__panel"
                     id="abo-admin-settings-panel-orders"
                     role="tabpanel"
                     aria-labelledby="abo-admin-settings-nav-tab-orders"
                     data-abo-admin-settings-panel="orders"
                     hidden>
                <header class="abo-admin-card__head">
                    <div>
                        <p class="abo-admin-settings__eyebrow">
                            <span class="abo-admin-settings__eyebrow-line" aria-hidden="true"></span>
                            <span>02 — CHECKOUT</span>
                        </p>
                        <h2 class="abo-admin-card__title">ORDERS</h2>
                    </div>
                    <p class="abo-admin-card__soft">Rules applied at checkout and to new orders.</p>
                </header>

                <div class="abo-admin-settings__grid">
                    <div class="abo-admin-settings__field">
                        <label for="abo-admin-settings-orders-min">Minimum order value</label>
                        <div class="abo-admin-settings__input-suffix">
                            <input id="abo-admin-settings-orders-min"
                                   type="number"
                                   min="0"
                                   step="1"
                                   inputmode="numeric"
                                   data-abo-admin-settings-field="orders.minimum">
                            <span>EGP</span>
                        </div>
                        <p class="abo-admin-settings__helper">Customers below this see a notice and can’t check out.</p>
                    </div>

                    <div class="abo-admin-settings__field">
                        <label for="abo-admin-settings-orders-cancel">Auto-cancel unpaid orders</label>
                        <div class="abo-admin-settings__input-suffix">
                            <input id="abo-admin-settings-orders-cancel"
                                   type="number"
                                   min="1"
                                   step="1"
                                   inputmode="numeric"
                                   data-abo-admin-settings-field="orders.autoCancelHours">
                            <span>h</span>
                        </div>
                        <p class="abo-admin-settings__helper">Unpaid orders are cancelled automatically after this many hours.</p>
                    </div>

                    <div class="abo-admin-settings__field">
                        <label>Allow guest checkout</label>
                        <label class="abo-admin-settings__toggle"
                               aria-label="Toggle guest checkout">
                            <input type="checkbox"
                                   role="switch"
                                   aria-checked="true"
                                   data-abo-admin-settings-field="orders.guestCheckout">
                            <span class="abo-admin-settings__toggle-track" aria-hidden="true"></span>
                            <span class="abo-admin-settings__toggle-thumb" aria-hidden="true"></span>
                        </label>
                    </div>

                    <div class="abo-admin-settings__field">
                        <label for="abo-admin-settings-orders-prefix">Order number prefix</label>
                        <input id="abo-admin-settings-orders-prefix"
                               type="text"
                               autocomplete="off"
                               maxlength="10"
                               data-abo-admin-settings-field="orders.prefix">
                        <p class="abo-admin-settings__helper">Preview · the next order will be <code class="abo-admin-settings__code" data-abo-admin-settings-preview="orders.nextNumber">ABO-1043</code></p>
                    </div>
                </div>
            </article>

            {{-- 3. INVENTORY ──────────────────────────────────────────── --}}
            <article class="abo-admin-card abo-admin-settings__panel"
                     id="abo-admin-settings-panel-inventory"
                     role="tabpanel"
                     aria-labelledby="abo-admin-settings-nav-tab-inventory"
                     data-abo-admin-settings-panel="inventory"
                     hidden>
                <header class="abo-admin-card__head">
                    <div>
                        <p class="abo-admin-settings__eyebrow">
                            <span class="abo-admin-settings__eyebrow-line" aria-hidden="true"></span>
                            <span>03 — STOCK</span>
                        </p>
                        <h2 class="abo-admin-card__title">INVENTORY</h2>
                    </div>
                    <p class="abo-admin-card__soft">Thresholds that drive stock pills and store visibility.</p>
                </header>

                <div class="abo-admin-settings__grid">
                    <div class="abo-admin-settings__field">
                        <label for="abo-admin-settings-inv-threshold">Low-stock threshold</label>
                        <input id="abo-admin-settings-inv-threshold"
                               type="number"
                               min="0"
                               step="1"
                               inputmode="numeric"
                               data-abo-admin-settings-field="inventory.lowStockThreshold">
                        <p class="abo-admin-settings__helper">Products at or below this appear as Low stock and trigger notifications.</p>
                    </div>

                    <div class="abo-admin-settings__field">
                        <label>Hide out-of-stock products in the store</label>
                        <label class="abo-admin-settings__toggle"
                               aria-label="Toggle hide out-of-stock products">
                            <input type="checkbox"
                                   role="switch"
                                   aria-checked="false"
                                   data-abo-admin-settings-field="inventory.hideOutOfStock">
                            <span class="abo-admin-settings__toggle-track" aria-hidden="true"></span>
                            <span class="abo-admin-settings__toggle-thumb" aria-hidden="true"></span>
                        </label>
                    </div>
                </div>
            </article>

            {{-- 4. CANCELLATION REASONS ───────────────────────────────── --}}
            <article class="abo-admin-card abo-admin-settings__panel"
                     id="abo-admin-settings-panel-cancellation"
                     role="tabpanel"
                     aria-labelledby="abo-admin-settings-nav-tab-cancellation"
                     data-abo-admin-settings-panel="cancellation"
                     hidden>
                <header class="abo-admin-card__head">
                    <div>
                        <p class="abo-admin-settings__eyebrow">
                            <span class="abo-admin-settings__eyebrow-line" aria-hidden="true"></span>
                            <span>04 — RETURNS</span>
                        </p>
                        <h2 class="abo-admin-card__title">CANCELLATION REASONS</h2>
                    </div>
                    <p class="abo-admin-card__soft">Shown to customers when they request a cancellation. Drag to reorder.</p>
                </header>

                <div class="abo-admin-settings__reason-head" aria-hidden="true">
                    <span></span>
                    <span>ENGLISH</span>
                    <span>ARABIC</span>
                    <span>ACTIVE</span>
                    <span></span>
                </div>

                <ul class="abo-admin-settings__reasons" data-abo-admin-settings-reasons>
                    {{-- Reasons are injected by settings.js from the static
                         $cancellationReasons array so add / delete / drag
                         reorder can mutate the live DOM. --}}
                </ul>

                <button type="button"
                        class="abo-admin-settings__add-row"
                        data-abo-admin-settings-action="add-reason">
                    <span aria-hidden="true">+</span>
                    <span>Add reason</span>
                </button>
            </article>

            {{-- 5. RECEIPTS ───────────────────────────────────────────── --}}
            <article class="abo-admin-card abo-admin-settings__panel"
                     id="abo-admin-settings-panel-receipts"
                     role="tabpanel"
                     aria-labelledby="abo-admin-settings-nav-tab-receipts"
                     data-abo-admin-settings-panel="receipts"
                     hidden>
                <header class="abo-admin-card__head">
                    <div>
                        <p class="abo-admin-settings__eyebrow">
                            <span class="abo-admin-settings__eyebrow-line" aria-hidden="true"></span>
                            <span>05 — PRINT</span>
                        </p>
                        <h2 class="abo-admin-card__title">RECEIPTS</h2>
                    </div>
                    <p class="abo-admin-card__soft">Customer print-outs and packing slips.</p>
                </header>

                <div class="abo-admin-settings__receipts">
                    {{-- LEFT: form --}}
                    <div class="abo-admin-settings__receipts-form">
                        <div class="abo-admin-settings__grid">
                            <div class="abo-admin-settings__field">
                                <label>Show store logo on receipt</label>
                                <label class="abo-admin-settings__toggle"
                                       aria-label="Toggle show store logo on receipt">
                                    <input type="checkbox"
                                           role="switch"
                                           aria-checked="true"
                                           data-abo-admin-settings-field="receipts.showLogo">
                                    <span class="abo-admin-settings__toggle-track" aria-hidden="true"></span>
                                    <span class="abo-admin-settings__toggle-thumb" aria-hidden="true"></span>
                                </label>
                            </div>

                            <div class="abo-admin-settings__field">
                                <label for="abo-admin-settings-receipts-header">Header text</label>
                                <input id="abo-admin-settings-receipts-header"
                                       type="text"
                                       maxlength="40"
                                       autocomplete="off"
                                       data-abo-admin-settings-field="receipts.headerText">
                            </div>

                            <div class="abo-admin-settings__field abo-admin-settings__field--full">
                                <label for="abo-admin-settings-receipts-footer">Footer note</label>
                                <textarea id="abo-admin-settings-receipts-footer"
                                          rows="2"
                                          maxlength="160"
                                          data-abo-admin-settings-field="receipts.footerNote"></textarea>
                            </div>
                        </div>

                        <h3 class="abo-admin-settings__subhead">FIELDS TO SHOW</h3>

                        <div class="abo-admin-settings__checks" role="group" aria-label="Fields to show on the receipt">
                            @foreach ([
                                ['key' => 'orderNumber',   'label' => 'Order number'],
                                ['key' => 'date',          'label' => 'Date'],
                                ['key' => 'customerPhone', 'label' => 'Customer phone'],
                                ['key' => 'address',       'label' => 'Address'],
                                ['key' => 'paymentMethod', 'label' => 'Payment method'],
                                ['key' => 'shippingFee',   'label' => 'Shipping fee'],
                            ] as $f)
                                <label class="abo-admin-settings__check">
                                    <input type="checkbox"
                                           role="switch"
                                           aria-checked="{{ ($receipts['fields'][$f['key']] ?? false) ? 'true' : 'false' }}"
                                           @checked($receipts['fields'][$f['key']] ?? false)
                                           data-abo-admin-settings-field="receipts.fields.{{ $f['key'] }}">
                                    <span class="abo-admin-settings__check-track" aria-hidden="true"></span>
                                    <span class="abo-admin-settings__check-label">{{ $f['label'] }}</span>
                                </label>
                            @endforeach
                        </div>

                        <h3 class="abo-admin-settings__subhead">PAPER SIZE</h3>

                        <div class="abo-admin-settings__paper"
                             role="radiogroup"
                             aria-label="Receipt paper size">
                            @foreach ([
                                ['value' => 'a4',      'label' => 'A4',         'sub' => 'Printable invoice'],
                                ['value' => 'thermal', 'label' => '80 mm',      'sub' => 'Thermal printer'],
                            ] as $p)
                                <label class="abo-admin-settings__paper-option @if (($receipts['paper'] ?? 'a4') === $p['value']) is-active @endif">
                                    <input type="radio"
                                           name="abo-admin-settings-receipts-paper"
                                           value="{{ $p['value'] }}"
                                           @checked(($receipts['paper'] ?? 'a4') === $p['value'])
                                           data-abo-admin-settings-field="receipts.paper">
                                    <span class="abo-admin-settings__paper-label">{{ $p['label'] }}</span>
                                    <span class="abo-admin-settings__paper-sub">{{ $p['sub'] }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    {{-- RIGHT: live preview --}}
                    <aside class="abo-admin-settings__receipt-preview"
                           aria-label="Live receipt preview"
                           data-abo-admin-settings-receipt-preview>
                        <div class="abo-admin-settings__receipt-paper
                                    @if (($receipts['paper'] ?? 'a4') === 'thermal') abo-admin-settings__receipt-paper--thermal @endif">
                            <div class="abo-admin-settings__receipt-head">
                                <div class="abo-admin-settings__receipt-logo" data-abo-admin-settings-receipt-logo>ABO</div>
                                <p class="abo-admin-settings__receipt-store" data-abo-admin-settings-receipt-store>{{ $receipts['headerText'] }}</p>
                            </div>
                            <hr class="abo-admin-settings__rule abo-admin-settings__rule--inset">
                            <dl class="abo-admin-settings__receipt-rows">
                                @foreach ([
                                    ['key' => 'orderNumber',   'label' => 'Order',    'value' => 'ABO-1043'],
                                    ['key' => 'date',          'label' => 'Date',     'value' => '16 Oct 2026'],
                                    ['key' => 'customerPhone', 'label' => 'Phone',    'value' => '+20 100 123 4567'],
                                    ['key' => 'address',       'label' => 'Address',  'value' => '15 El Horreya St., Cairo'],
                                    ['key' => 'paymentMethod', 'label' => 'Payment',  'value' => 'Cash on delivery'],
                                    ['key' => 'shippingFee',   'label' => 'Shipping', 'value' => '60 EGP'],
                                ] as $row)
                                    <div class="abo-admin-settings__receipt-row" data-abo-admin-settings-receipt-row="{{ $row['key'] }}" @if (! ($receipts['fields'][$row['key']] ?? false)) hidden @endif>
                                        <dt>{{ $row['label'] }}</dt>
                                        <dd>{{ $row['value'] }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                            <hr class="abo-admin-settings__rule abo-admin-settings__rule--inset">
                            <p class="abo-admin-settings__receipt-footer" data-abo-admin-settings-receipt-footer>{{ $receipts['footerNote'] }}</p>
                        </div>
                        <p class="abo-admin-settings__preview-note">Preview · updates as you edit.</p>
                    </aside>
                </div>
            </article>

            {{-- 6. NOTIFICATIONS ──────────────────────────────────────── --}}
            <article class="abo-admin-card abo-admin-settings__panel"
                     id="abo-admin-settings-panel-notifications"
                     role="tabpanel"
                     aria-labelledby="abo-admin-settings-nav-tab-notifications"
                     data-abo-admin-settings-panel="notifications"
                     hidden>
                <header class="abo-admin-card__head">
                    <div>
                        <p class="abo-admin-settings__eyebrow">
                            <span class="abo-admin-settings__eyebrow-line" aria-hidden="true"></span>
                            <span>06 — BELL</span>
                        </p>
                        <h2 class="abo-admin-card__title">NOTIFICATIONS</h2>
                    </div>
                    <p class="abo-admin-card__soft">Which events show up in the admin bell.</p>
                </header>

                <ul class="abo-admin-settings__toggles" role="list">
                    @foreach ([
                        ['key' => 'newOrder',       'label' => 'New order',         'sub' => 'A new order comes in.'],
                        ['key' => 'lowStock',       'label' => 'Low stock',         'sub' => 'A product drops to or below the low-stock threshold.'],
                        ['key' => 'returnRequest',  'label' => 'Return request',    'sub' => 'A customer asks for a return or exchange.'],
                        ['key' => 'shipmentFailed', 'label' => 'Shipment failed',   'sub' => 'A courier reports a failed delivery attempt.'],
                        ['key' => 'couponExpiring', 'label' => 'Coupon expiring',   'sub' => 'A coupon is about to expire or did expire.'],
                    ] as $t)
                        <li class="abo-admin-settings__toggle-row">
                            <div>
                                <p class="abo-admin-settings__toggle-label">{{ $t['label'] }}</p>
                                <p class="abo-admin-settings__toggle-sub">{{ $t['sub'] }}</p>
                            </div>
                            <label class="abo-admin-settings__toggle"
                                   aria-label="Toggle {{ $t['label'] }} notifications">
                                <input type="checkbox"
                                       role="switch"
                                       aria-checked="{{ ($notifications[$t['key']] ?? false) ? 'true' : 'false' }}"
                                       @checked($notifications[$t['key']] ?? false)
                                       data-abo-admin-settings-field="notifications.{{ $t['key'] }}">
                                <span class="abo-admin-settings__toggle-track" aria-hidden="true"></span>
                                <span class="abo-admin-settings__toggle-thumb" aria-hidden="true"></span>
                            </label>
                        </li>
                    @endforeach

                    <li class="abo-admin-settings__toggle-row abo-admin-settings__toggle-row--accent">
                        <div>
                            <p class="abo-admin-settings__toggle-label">Browser sound</p>
                            <p class="abo-admin-settings__toggle-sub">Play a short tone when a new notification arrives.</p>
                        </div>
                        <label class="abo-admin-settings__toggle"
                               aria-label="Toggle browser sound">
                            <input type="checkbox"
                                   role="switch"
                                   aria-checked="{{ $notifications['browserSound'] ? 'true' : 'false' }}"
                                   @checked($notifications['browserSound'])
                                   data-abo-admin-settings-field="notifications.browserSound">
                            <span class="abo-admin-settings__toggle-track" aria-hidden="true"></span>
                            <span class="abo-admin-settings__toggle-thumb" aria-hidden="true"></span>
                        </label>
                    </li>
                </ul>
            </article>
        </div>
    </section>

    {{-- ─── Sticky bottom save bar ─────────────────────────────────── --}}
    <section class="abo-admin-settings__savebar"
             hidden
             role="region"
             aria-label="Unsaved changes"
             data-abo-admin-settings-savebar>
        <p class="abo-admin-settings__savebar-text">
            <span class="abo-admin-settings__savebar-dot" aria-hidden="true"></span>
            <span><strong>Unsaved changes</strong> — last edited <span data-abo-admin-settings-savebar-when>a moment ago</span>.</span>
        </p>
        <div class="abo-admin-settings__savebar-actions">
            <button type="button"
                    class="abo-admin-btn abo-admin-btn--ghost"
                    data-abo-admin-settings-action="discard">
                DISCARD
            </button>
            <button type="button"
                    class="abo-admin-btn abo-admin-btn--primary"
                    data-abo-admin-settings-action="save">
                SAVE
            </button>
        </div>
    </section>

    {{-- ─── Confirm modal (maintenance + delete-reason) ───────────── --}}
    <div class="abo-admin-settings__modal"
         hidden
         role="dialog"
         aria-modal="true"
         aria-labelledby="abo-admin-settings-modal-title"
         data-abo-admin-settings-modal>
        <div class="abo-admin-settings__modal-backdrop" data-abo-admin-settings-modal-close></div>
        <div class="abo-admin-settings__modal-card">
            <h2 class="abo-admin-settings__modal-title" id="abo-admin-settings-modal-title">CONFIRM</h2>
            <p class="abo-admin-settings__modal-body" data-abo-admin-settings-modal-body>Are you sure?</p>
            <div class="abo-admin-settings__modal-actions">
                <button type="button"
                        class="abo-admin-btn abo-admin-btn--ghost"
                        data-abo-admin-settings-modal-close>CANCEL</button>
                <button type="button"
                        class="abo-admin-settings__modal-confirm"
                        data-abo-admin-settings-modal-confirm>CONFIRM</button>
            </div>
        </div>
    </div>

    {{-- ─── Toast (Saved (demo)) ──────────────────────────────────── --}}
    <div class="abo-admin-settings__toast"
         role="status"
         aria-live="polite"
         hidden
         data-abo-admin-settings-toast>
        <span class="abo-admin-settings__toast-dot" aria-hidden="true"></span>
        <span class="abo-admin-settings__toast-text" data-abo-admin-settings-toast-text>Saved (demo)</span>
    </div>
@endsection

@push('head')
    {{-- Page-local stylesheet. Loaded only on admin/settings. --}}
    <link rel="stylesheet" href="{{ asset('admin/css/settings.css') }}">
@endpush

@push('scripts')
    {{-- Page-local script. Loaded only on admin/settings. --}}
    <script src="{{ asset('admin/js/settings.js') }}" defer></script>
@endpush

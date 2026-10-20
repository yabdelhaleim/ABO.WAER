{{--
    Admin · Size Guides (front-end only, static data).

    Extends the existing admin layout. No controller, no model.
    All data is hard-coded in the $guides array at the top of this
    view, and exposed to the page-local script via a single
    <script type="application/json"> tag with the same shape.

    Page structure (top to bottom):
      1. Page header  — CATALOG eyebrow, "SIZE" + " GUIDES" title,
                        date + lime "+ ADD GUIDE" button
      2. Two columns  — Left: search + list of guides (single card,
                        one row per guide). Right: edit panel that
                        becomes a slide-over on tablet / mobile.
      3. Preview modal — how the storefront will see the guide.
      4. Delete confirm modal — one shared instance.
      5. Toast        — one shared instance.

    Conventions match admin/categories/index.blade.php: classes
    reuse the existing shell (abo-admin-*) where they fit;
    new size-guides-only classes are prefixed `abo-admin-size-guides-`.
--}}

@extends('admin.layouts.app')

@section('title', 'Size guides · Catalog')
@section('description', 'ABO — admin size guides: per-guide chart, sizes, measurements, unit switch and customer note.')

@php
    // TODO(backend): replace the static $guides array below with
    // data fetched server-side from the size_guides table (or a
    // similar relation). The shape is final; only the source
    // changes. A future server-side implementation can keep the
    // PHP<->JSON bridge and just change how the array is built.

    // The full size palette (per the Colors & Sizes page). The
    // "+ Add size row" select in the editor lists only sizes that
    // are not already in the current guide's sizes list.
    $sizePalette = [
        'letter' => ['XS', 'S', 'M', 'L', 'XL', 'XXL', '3XL'],
        'pants'  => ['28', '30', '32', '34', '36', '38'],
        'one'    => ['One size'],
    ];
    $allSizes = array_merge($sizePalette['letter'], $sizePalette['pants'], $sizePalette['one']);

    $guides = [
        [
            'id'              => 'sg-hoodies',
            'name'            => 'Hoodies and Jackets',
            'name_ar'         => 'هودييز و جاكيتس',
            'sizes'           => ['XS', 'S', 'M', 'L', 'XL', 'XXL'],
            'measurements'    => ['Chest', 'Length', 'Shoulder', 'Sleeve'],
            'rows'            => [
                ['52', '68', '44', '62'],
                ['54', '70', '45', '63'],
                ['56', '72', '46', '64'],
                ['58', '74', '47', '65'],
                ['60', '76', '48', '66'],
                ['62', '78', '49', '67'],
            ],
            'note'            => 'Measurements are in cm. If you are between sizes, choose the larger one.',
            'note_ar'         => 'القياسات بالسنتيمتر. إذا كنت بين مقاسين، اختر المقاس الأكبر.',
            'unit'            => 'cm',
            'how_to'          => 'Lay the garment flat. Measure across the chest just under the armhole. For length, measure from the highest point of the shoulder to the hem.',
            'how_to_ar'       => 'ضع القطعة على سطح مستوٍ. قس عبر الصدر أسفل فتحة الذراع مباشرة. للطول، قس من أعلى نقطة في الكتف إلى الحافة السفلية.',
            'image'           => null,
            'product_count'   => 24,
            'active'          => true,
        ],
        [
            'id'              => 'sg-tshirts',
            'name'            => 'T-shirts',
            'name_ar'         => 'تي شيرتات',
            'sizes'           => ['S', 'M', 'L', 'XL', 'XXL'],
            'measurements'    => ['Chest', 'Length', 'Shoulder'],
            'rows'            => [
                ['52', '70', '44'],
                ['54', '72', '45'],
                ['56', '74', '46'],
                ['58', '76', '47'],
                ['60', '78', '48'],
            ],
            'note'            => 'Measurements are in cm. The fit is regular.',
            'note_ar'         => 'القياسات بالسنتيمتر. القصة عادية.',
            'unit'            => 'cm',
            'how_to'          => 'Lay the garment flat. Measure across the chest 1 cm below the armhole.',
            'how_to_ar'       => 'ضع القطعة على سطح مستوٍ. قس عبر الصدر على بعد 1 سم أسفل فتحة الذراع.',
            'image'           => null,
            'product_count'   => 18,
            'active'          => true,
        ],
        [
            'id'              => 'sg-pants',
            'name'            => 'Pants',
            'name_ar'         => 'بناطيل',
            'sizes'           => ['28', '30', '32', '34', '36', '38'],
            'measurements'    => ['Waist', 'Hip', 'Inseam', 'Thigh'],
            'rows'            => [
                ['72', '92', '76', '52'],
                ['76', '96', '77', '54'],
                ['80', '100', '78', '56'],
                ['84', '104', '79', '58'],
                ['88', '108', '80', '60'],
                ['92', '112', '81', '62'],
            ],
            'note'            => 'Measurements are in cm. Pants are cut a little slim — size up if you prefer a relaxed fit.',
            'note_ar'         => 'القياسات بالسنتيمتر. البناطيل بقصة ضيقة بعض الشيء — اختر مقاساً أكبر إذا كنت تفضل قصة مريحة.',
            'unit'            => 'cm',
            'how_to'          => 'Measure across the elastic waist laid flat (then double it), and around the widest part of the hip.',
            'how_to_ar'       => 'قس عبر الخصر المرن وهو مفرود على سطح مستوٍ (ثم اضرب في اثنين)، وحول أوسع جزء في الورك.',
            'image'           => null,
            'product_count'   => 12,
            'active'          => true,
        ],
        [
            'id'              => 'sg-accessories',
            'name'            => 'Accessories',
            'name_ar'         => 'إكسسوارات',
            'sizes'           => ['One size'],
            'measurements'    => [],
            'rows'            => [],
            'note'            => 'One size fits most. No measurements needed for this guide.',
            'note_ar'         => 'مقاس واحد يناسب معظم الأشخاص. لا حاجة للقياسات.',
            'unit'            => 'cm',
            'how_to'          => '',
            'how_to_ar'       => '',
            'image'           => null,
            'product_count'   => 0,
            'active'          => false,
        ],
    ];

    // The first guide is the default selection. The JS keeps the
    // selection in sync with the row clicks and the keyboard.
    $firstId = $guides[0]['id'] ?? null;
@endphp

@section('content')
    {{-- ─── Page header ────────────────────────────────────────── --}}
    <header class="abo-admin-page__head">
        <div class="abo-admin-page__head-text">
            <p class="abo-admin-page__eyebrow">
                <span class="abo-admin-page__eyebrow-line" aria-hidden="true"></span>
                <span>CATALOG</span>
            </p>
            <h1 class="abo-admin-page__title">
                <span>SIZE</span><span class="abo-admin-size-guides__title-lime"> GUIDES</span>
            </h1>
            <p class="abo-admin-page__date" id="abo-admin-today-date">Loading date…</p>
        </div>

        <div class="abo-admin-page__head-actions">
            <button type="button"
                    class="abo-admin-btn abo-admin-btn--primary"
                    data-abo-admin-size-guides-add>
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="1.8"
                          fill="none" stroke-linecap="round"/>
                </svg>
                + ADD GUIDE
            </button>
        </div>
    </header>

    {{-- ─── Two-column layout ───────────────────────────────────── --}}
    <div class="abo-admin-size-guides__layout"
         data-abo-admin-size-guides
         aria-label="Size guides">

        {{-- LEFT: search + list --}}
        <section class="abo-admin-size-guides__col" aria-label="Guide list">
            <div class="abo-admin-card abo-admin-size-guides__toolbar">
                <label class="sr-only" for="abo-admin-size-guides-search">Filter size guides</label>
                <div class="abo-admin-size-guides__search">
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"
                         class="abo-admin-size-guides__search-icon">
                        <path d="M21 20.3 16.7 16a8 8 0 1 0-1.4 1.4L20.3 21 21 20.3zM4 10a6 6 0 1 1 12 0 6 6 0 0 1-12 0z"
                              fill="currentColor"/>
                    </svg>
                    <input id="abo-admin-size-guides-search"
                           type="search"
                           placeholder="Search guides…"
                           autocomplete="off"
                           data-abo-admin-size-guides-search>
                </div>
            </div>

            <article class="abo-admin-card abo-admin-size-guides__list-card"
                     aria-label="Size guides">
                {{-- The list is rendered by public/admin/js/size-guides.js
                     from the $guides JSON payload. Every
                     [data-abo-admin-size-guides-row] gets full
                     keyboard support (Arrow keys + Enter). --}}
                <ul class="abo-admin-size-guides__list"
                    role="listbox"
                    aria-label="Size guides"
                    data-abo-admin-size-guides-list></ul>

                {{-- Helper text under the list (always shown). --}}
                <p class="abo-admin-size-guides__helper">
                    A guide is shown to customers on the product page. Assign it to products in the product form.
                </p>

                {{-- States: empty / no-results / loading skeleton. --}}
                <div class="abo-admin-size-guides__state" data-abo-admin-size-guides-state="empty" hidden>
                    <p>No size guides yet. Add your first guide.</p>
                    <button type="button"
                            class="abo-admin-btn abo-admin-btn--primary"
                            data-abo-admin-size-guides-add-empty>
                        + ADD GUIDE
                    </button>
                </div>

                <div class="abo-admin-size-guides__state" data-abo-admin-size-guides-state="noresults" hidden>
                    <p>No results. Try clearing the search above.</p>
                </div>

                <div class="abo-admin-size-guides__state"
                     data-abo-admin-size-guides-state="loading"
                     data-abo-admin-size-guides-skeleton>
                    <div class="abo-admin-size-guides__skeleton" aria-hidden="true">
                        @for ($i = 0; $i < 4; $i++)
                            <div class="abo-admin-size-guides__skeleton-row">
                                <span class="abo-admin-size-guides__skeleton-cell" style="width: 60%;"></span>
                                <span class="abo-admin-size-guides__skeleton-cell" style="width: 40%;"></span>
                                <span class="abo-admin-size-guides__skeleton-cell abo-admin-size-guides__skeleton-cell--toggle"></span>
                            </div>
                        @endfor
                    </div>
                </div>
            </article>
        </section>

        {{-- RIGHT: edit panel (slide-over on tablet / mobile) --}}
        <aside class="abo-admin-size-guides__col abo-admin-size-guides__panel"
               aria-label="Edit guide"
               data-abo-admin-size-guides-panel>

            {{-- Slide-over backdrop on tablet / mobile. Hidden until
                 the panel is opened on a small viewport. --}}
            <div class="abo-admin-size-guides__panel-overlay"
                 hidden
                 data-abo-admin-size-guides-panel-overlay
                 aria-hidden="true"></div>

            {{-- "No selection" state --}}
            <div class="abo-admin-size-guides__panel-empty"
                 data-abo-admin-size-guides-panel-no-selection>
                <p>No guide selected. Click a row, or add a new one.</p>
            </div>

            {{-- Form (toggled by size-guides.js once a row is selected) --}}
            <div data-abo-admin-size-guides-panel-form hidden>

                <div class="abo-admin-size-guides__panel-head">
                    <h2 class="abo-admin-size-guides__panel-title">
                        <span data-abo-admin-size-guides-panel-title-text>EDIT GUIDE</span>
                        <span class="abo-admin-size-guides__dirty-dot"
                              aria-label="Unsaved changes"
                              data-abo-admin-size-guides-dirty-dot></span>
                    </h2>
                    <button type="button"
                            class="abo-admin-size-guides__panel-close"
                            aria-label="Close edit panel"
                            data-abo-admin-size-guides-panel-close>
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M6 6l12 12M18 6 6 18"
                                  stroke="currentColor" stroke-width="1.6"
                                  fill="none" stroke-linecap="round"/>
                        </svg>
                    </button>
                </div>

                <div class="abo-admin-size-guides__panel-body">

                    {{-- Guide name (English) --}}
                    <div class="abo-admin-size-guides__field">
                        <label for="abo-admin-size-guides-name">Guide name (English)</label>
                        <input id="abo-admin-size-guides-name"
                               type="text"
                               placeholder="e.g. Hoodies and Jackets"
                               autocomplete="off"
                               dir="ltr"
                               data-abo-admin-size-guides-name>
                    </div>

                    {{-- Guide name (Arabic) --}}
                    <div class="abo-admin-size-guides__field">
                        <label for="abo-admin-size-guides-name-ar">Guide name (Arabic)</label>
                        <input id="abo-admin-size-guides-name-ar"
                               type="text"
                               placeholder="مثال: هودييز و جاكيتس"
                               autocomplete="off"
                               dir="rtl"
                               data-abo-admin-size-guides-name-ar>
                    </div>

                    {{-- Note for customers (with EN/AR switch) --}}
                    <div class="abo-admin-size-guides__field">
                        <label for="abo-admin-size-guides-note">Note for customers</label>
                        <div class="abo-admin-size-guides__lang"
                             role="tablist"
                             aria-label="Note language"
                             data-abo-admin-size-guides-note-lang>
                            <button type="button" role="tab" aria-selected="true"
                                    class="is-active" data-abo-admin-size-guides-note-lang-btn="en">EN</button>
                            <button type="button" role="tab" aria-selected="false"
                                    data-abo-admin-size-guides-note-lang-btn="ar">AR</button>
                        </div>
                        <label class="sr-only" for="abo-admin-size-guides-note">Note for customers</label>
                        <textarea id="abo-admin-size-guides-note"
                                  placeholder="e.g. Measurements are in cm. If you are between sizes, choose the larger one."
                                  dir="ltr"
                                  data-abo-admin-size-guides-note></textarea>
                        <textarea id="abo-admin-size-guides-note-ar"
                                  placeholder="مثال: القياسات بالسنتيمتر. إذا كنت بين مقاسين، اختر المقاس الأكبر."
                                  dir="rtl"
                                  data-abo-admin-size-guides-note-ar
                                  hidden></textarea>
                    </div>

                    {{-- Unit switch (cm / inch) --}}
                    <div class="abo-admin-size-guides__unit"
                         role="tablist"
                         aria-label="Measurement unit"
                         data-abo-admin-size-guides-unit>
                        <button type="button" role="tab" aria-selected="true"
                                class="abo-admin-size-guides__unit-btn is-active"
                                data-abo-admin-size-guides-unit-btn="cm">cm</button>
                        <button type="button" role="tab" aria-selected="false"
                                class="abo-admin-size-guides__unit-btn"
                                data-abo-admin-size-guides-unit-btn="inch">inch</button>
                    </div>

                    {{-- Size chart editor --}}
                    <div class="abo-admin-size-guides__chart">
                        <div class="abo-admin-size-guides__chart-head">
                            <h3 class="abo-admin-size-guides__chart-title">SIZE CHART</h3>
                            <p class="abo-admin-size-guides__chart-hint"
                               data-abo-admin-size-guides-empty-note hidden>
                                Fill all cells before saving.
                            </p>
                        </div>

                        <div class="abo-admin-size-guides__table-wrap"
                             data-abo-admin-size-guides-table-wrap>
                            <table class="abo-admin-size-guides__table"
                                   data-abo-admin-size-guides-table>
                                {{-- The view builds the table from the
                                     currently selected guide. JS keeps
                                     it in sync when the selection or
                                     the unit changes. --}}
                            </table>
                        </div>

                        <div class="abo-admin-size-guides__chart-actions">
                            <div class="abo-admin-size-guides__add-size-wrap">
                                <button type="button"
                                        class="abo-admin-btn abo-admin-btn--ghost"
                                        data-abo-admin-size-guides-add-size>
                                    + ADD SIZE ROW
                                </button>
                                {{-- The select opens next to the button when
                                     triggered. Filled by JS with the
                                     remaining sizes from the palette. --}}
                                <select class="abo-admin-size-guides__select abo-admin-size-guides__add-size-select"
                                        hidden
                                        data-abo-admin-size-guides-add-size-select
                                        aria-label="Pick a size to add">
                                </select>
                            </div>
                            <button type="button"
                                    class="abo-admin-btn abo-admin-btn--ghost"
                                    data-abo-admin-size-guides-add-measurement>
                                + ADD MEASUREMENT
                            </button>
                        </div>
                    </div>

                    {{-- Empty table state --}}
                    <p class="abo-admin-size-guides__empty-table"
                       data-abo-admin-size-guides-empty-table
                       hidden>
                        No sizes yet. Add a size row to start.
                    </p>

                    {{-- "How to measure" block --}}
                    <div class="abo-admin-size-guides__howto">
                        <div class="abo-admin-size-guides__howto-head">
                            <h3 class="abo-admin-size-guides__chart-title">HOW TO MEASURE</h3>
                            <div class="abo-admin-size-guides__lang"
                                 role="tablist"
                                 aria-label="How to measure language"
                                 data-abo-admin-size-guides-howto-lang>
                                <button type="button" role="tab" aria-selected="true"
                                        class="is-active" data-abo-admin-size-guides-howto-lang-btn="en">EN</button>
                                <button type="button" role="tab" aria-selected="false"
                                        data-abo-admin-size-guides-howto-lang-btn="ar">AR</button>
                            </div>
                        </div>

                        <label class="sr-only" for="abo-admin-size-guides-howto">How to measure (English)</label>
                        <textarea id="abo-admin-size-guides-howto"
                                  class="abo-admin-size-guides__howto-text"
                                  placeholder="Explain how to measure chest, waist and so on"
                                  dir="ltr"
                                  data-abo-admin-size-guides-howto></textarea>
                        <label class="sr-only" for="abo-admin-size-guides-howto-ar">How to measure (Arabic)</label>
                        <textarea id="abo-admin-size-guides-howto-ar"
                                  class="abo-admin-size-guides__howto-text"
                                  placeholder="اشرح كيفية قياس الصدر والخصر وغيرهما"
                                  dir="rtl"
                                  hidden
                                  data-abo-admin-size-guides-howto-ar></textarea>

                        <div class="abo-admin-size-guides__howto-image">
                            <div class="abo-admin-size-guides__howto-preview"
                                 data-abo-admin-size-guides-howto-preview
                                 aria-hidden="true">
                                <span class="abo-admin-size-guides__howto-letter">IMG</span>
                            </div>
                            <input type="file"
                                   accept="image/*"
                                   class="abo-admin-size-guides__cover-file"
                                   data-abo-admin-size-guides-howto-file>
                            <button type="button"
                                    class="abo-admin-btn abo-admin-btn--ghost abo-admin-size-guides__image-change"
                                    data-abo-admin-size-guides-howto-change>CHANGE</button>
                            <button type="button"
                                    class="abo-admin-btn abo-admin-btn--ghost abo-admin-size-guides__image-remove"
                                    data-abo-admin-size-guides-howto-image-remove
                                    disabled>REMOVE</button>
                        </div>
                    </div>

                    {{-- Active toggle --}}
                    <div class="abo-admin-size-guides__switch"
                         data-abo-admin-size-guides-switch="active">
                        <div>
                            <span class="abo-admin-size-guides__switch-label">Active (visible in store)</span>
                            <p class="abo-admin-size-guides__switch-hint">Inactive guides stay hidden in the catalog.</p>
                        </div>
                        <label class="abo-admin-size-guides__toggle" aria-label="Active">
                            <input type="checkbox"
                                   checked
                                   aria-checked="true"
                                   data-abo-admin-size-guides-active>
                            <span class="abo-admin-size-guides__toggle-track"></span>
                            <span class="abo-admin-size-guides__toggle-thumb"></span>
                        </label>
                    </div>

                    {{-- Action buttons --}}
                    <div class="abo-admin-size-guides__actions">
                        <button type="button"
                                class="abo-admin-btn abo-admin-btn--primary abo-admin-size-guides__save"
                                data-abo-admin-size-guides-save>SAVE</button>
                        <button type="button"
                                class="abo-admin-btn abo-admin-btn--ghost abo-admin-size-guides__duplicate"
                                data-abo-admin-size-guides-duplicate>DUPLICATE</button>
                        <button type="button"
                                class="abo-admin-size-guides__delete"
                                data-abo-admin-size-guides-delete>DELETE</button>
                    </div>
                    <p class="abo-admin-size-guides__delete-note"
                       data-abo-admin-size-guides-delete-note
                       hidden>Remove it from its products first.</p>

                    <button type="button"
                            class="abo-admin-btn abo-admin-btn--ghost abo-admin-size-guides__preview"
                            data-abo-admin-size-guides-preview>PREVIEW</button>
                </div>
            </div>
        </aside>
    </div>

    {{-- ─── Preview modal (how the storefront will render the guide) --}}
    <div class="abo-admin-size-guides__modal"
         role="dialog"
         aria-modal="true"
         aria-labelledby="abo-admin-size-guides-preview-modal-title"
         hidden
         data-abo-admin-size-guides-preview-modal>
        <div class="abo-admin-size-guides__modal-backdrop"
             data-abo-admin-size-guides-preview-modal-close></div>
        <div class="abo-admin-size-guides__modal-card abo-admin-size-guides__modal-card--wide">
            <header class="abo-admin-size-guides__modal-head">
                <h2 class="abo-admin-size-guides__modal-title"
                    id="abo-admin-size-guides-preview-modal-title"
                    data-abo-admin-size-guides-preview-modal-title>GUIDE PREVIEW</h2>
                <button type="button"
                        class="abo-admin-size-guides__panel-close"
                        aria-label="Close preview"
                        data-abo-admin-size-guides-preview-modal-close>
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="M6 6l12 12M18 6 6 18"
                              stroke="currentColor" stroke-width="1.6"
                              fill="none" stroke-linecap="round"/>
                    </svg>
                </button>
            </header>
            <div class="abo-admin-size-guides__modal-body"
                 data-abo-admin-size-guides-preview-modal-body>
                {{-- Body injected by size-guides.js from the current editor state. --}}
            </div>
        </div>
    </div>

    {{-- ─── Single shared delete modal ──────────────────────────── --}}
    <div class="abo-admin-size-guides__modal"
         role="dialog"
         aria-modal="true"
         aria-labelledby="abo-admin-size-guides-modal-title"
         hidden
         data-abo-admin-size-guides-modal>
        <div class="abo-admin-size-guides__modal-backdrop"
             data-abo-admin-size-guides-modal-close></div>
        <div class="abo-admin-size-guides__modal-card">
            <h2 class="abo-admin-size-guides__modal-title"
                id="abo-admin-size-guides-modal-title">DELETE GUIDE</h2>
            <p class="abo-admin-size-guides__modal-body"
               data-abo-admin-size-guides-modal-body>Are you sure? This action cannot be undone.</p>
            <div class="abo-admin-size-guides__modal-actions">
                <button type="button"
                        class="abo-admin-btn abo-admin-btn--ghost abo-admin-size-guides__modal-cancel"
                        data-abo-admin-size-guides-modal-cancel>CANCEL</button>
                <button type="button"
                        class="abo-admin-size-guides__modal-confirm"
                        data-abo-admin-size-guides-modal-confirm>DELETE</button>
            </div>
        </div>
    </div>

    {{-- ─── Single shared toast ────────────────────────────────── --}}
    <div class="abo-admin-size-guides__toast"
         role="status"
         aria-live="polite"
         hidden
         data-abo-admin-size-guides-toast>
        <span class="abo-admin-size-guides__toast-dot abo-admin-size-guides__toast-dot--lime"
              aria-hidden="true"></span>
        <span data-abo-admin-size-guides-toast-text>Saved (demo)</span>
    </div>

    {{-- ─── Static data → JSON for the page-local script ────────── --}}
    {{-- Same shape on both ends. JS never re-types this; it just
         reads the data off the tag and keeps an in-memory copy. --}}
    <script type="application/json"
            data-abo-admin-size-guides-data>{!! json_encode($guides, JSON_UNESCAPED_UNICODE) !!}</script>
    <script type="application/json"
            data-abo-admin-size-guides-sizes>{!! json_encode($allSizes, JSON_UNESCAPED_UNICODE) !!}</script>
@endsection

@push('head')
    {{-- Page-local stylesheet. Loaded only on the size-guides page. --}}
    <link rel="stylesheet" href="{{ asset('admin/css/size-guides.css') }}">
@endpush

@push('scripts')
    {{-- Page-local script. Loaded only on the size-guides page. --}}
    <script src="{{ asset('admin/js/size-guides.js') }}" defer></script>
@endpush

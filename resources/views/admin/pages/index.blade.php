{{--
    Admin · Pages — DB-driven.

    Extends the existing admin layout. The page list is rendered by
    Admin\PageController, which builds the dataset from the `pages`
    table and hands it over as JSON. The dataset is consumed by
    public/admin/js/pages.js, which renders the list (search + status
    filter + keyboard nav), the editor (title EN/AR switch, slug,
    content toolbar with EN/AR switch, published/draft switch), the
    slide-over (tablet / mobile), the preview modal and the shared
    toast from that one array.

    The editor is aligned to the columns that actually exist on the
    `pages` table:

      title.en / title.ar    → pages.title / pages.title_ar
      content.en / content.ar → pages.content / pages.content_ar
      status (published/draft) → pages.is_active
      slug                    → pages.slug (read-only for system pages)

    There is deliberately NO SEO block, NO contact-details block and
    NO "show in footer" switch: the table has no column to store them,
    so offering them in the UI would silently drop whatever the admin
    typed. Adding them back needs a migration first — see the notes in
    Admin\PageController::index.

    Reuses the brand tokens (--abo-lime, --abo-bg,
    --abo-admin-panel/border/text/coral/gray), the page-header /
    eyebrow / title classes, .abo-admin-card, .abo-admin-btn,
    .abo-admin-empty and .sr-only. The page-local stylesheet and
    script are loaded only on this page via @stack('head') and
    @stack('scripts'). All pages-specific classes are prefixed
    `abo-admin-pages-`.
--}}

@extends('admin.layouts.app')

@section('title', 'Pages · Content')
@section('description', 'ABO — admin pages manager: the five storefront pages, EN/AR editor, published / draft, preview.')

@section('content')
    {{-- ─── Page header ────────────────────────────────────────── --}}
    <header class="abo-admin-page__head">
        <div class="abo-admin-page__head-text">
            <p class="abo-admin-page__eyebrow">
                <span class="abo-admin-page__eyebrow-line" aria-hidden="true"></span>
                <span>CONTENT</span>
            </p>
            <h1 class="abo-admin-page__title">
                <span>PA</span><span class="abo-admin-pages__title-lime">GES</span>
            </h1>
        </div>
    </header>

    {{-- ─── Two-column layout (list + editor) ──────────────────── --}}
    <div class="abo-admin-pages__layout">

        {{-- ─── LEFT: page list ────────────────────────────────── --}}
        <section class="abo-admin-pages__list-pane" aria-label="Pages list">
            <div class="abo-admin-card abo-admin-pages__toolbar" aria-label="Filter pages">
                <div class="abo-admin-pages__filter-search">
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" class="abo-admin-pages__filter-icon">
                        <path d="M21 20.3 16.7 16a8 8 0 1 0-1.4 1.4L20.3 21 21 20.3zM4 10a6 6 0 1 1 12 0 6 6 0 0 1-12 0z"
                              fill="currentColor"/>
                    </svg>
                    <label class="sr-only" for="abo-admin-pages-search">Search pages</label>
                    <input id="abo-admin-pages-search"
                           type="search"
                           placeholder="Search by title…"
                           autocomplete="off"
                           data-abo-admin-pages-search>
                </div>

                <label class="sr-only" for="abo-admin-pages-filter-status">Status filter</label>
                <select id="abo-admin-pages-filter-status"
                        class="abo-admin-pages__select"
                        data-abo-admin-pages-filter="status">
                    <option value="">All statuses</option>
                    <option value="published">Published</option>
                    <option value="draft">Draft</option>
                </select>
            </div>

            <article class="abo-admin-card abo-admin-pages__list-card" aria-label="Pages">
                <p class="sr-only" aria-live="polite" data-abo-admin-pages-result-count>0 pages</p>

                <div class="abo-admin-pages__list"
                     role="listbox"
                     aria-label="Pages"
                     tabindex="0"
                     data-abo-admin-pages-list>
                    {{-- Rows are injected by pages.js from the JSON
                         dataset below. No server-side rows here. --}}
                </div>

                <div class="abo-admin-pages__state" data-abo-admin-pages-state="noresults" hidden>
                    <p class="abo-admin-empty">No results. Try clearing the filters above.</p>
                    <button type="button" class="abo-admin-btn abo-admin-btn--ghost"
                            data-abo-admin-pages-clear>
                        CLEAR FILTERS
                    </button>
                </div>
                <div class="abo-admin-pages__state" data-abo-admin-pages-state="loading" hidden>
                    <div class="abo-admin-pages__skeleton" aria-hidden="true">
                        @for ($i = 0; $i < 5; $i++)
                            <div class="abo-admin-pages__skeleton-row">
                                <span class="abo-admin-pages__skeleton-cell" style="width: 28px; height: 28px;"></span>
                                <span class="abo-admin-pages__skeleton-cell" style="width: 60%; height: 14px;"></span>
                                <span class="abo-admin-pages__skeleton-cell" style="width: 80%; height: 14px;"></span>
                            </div>
                        @endfor
                    </div>
                </div>
                <div class="abo-admin-pages__state" data-abo-admin-pages-state="empty" hidden>
                    <p class="abo-admin-empty">No pages yet.</p>
                </div>

                <p class="abo-admin-pages__list-note">
                    System pages are required by the store and cannot be deleted.
                </p>
            </article>
        </section>

        {{-- ─── RIGHT: editor ─ ─────────────────────────────────── --}}
        <section class="abo-admin-pages__editor-pane" aria-label="Page editor"
                 data-abo-admin-pages-editor-pane>
            <article class="abo-admin-card abo-admin-pages__editor" aria-label="Editor"
                     data-abo-admin-pages-editor hidden>
                <header class="abo-admin-pages__editor-head">
                    <div>
                        <p class="abo-admin-pages__editor-eyebrow">
                            <span data-abo-admin-pages-editor-mode>EDIT PAGE</span>
                            <span class="abo-admin-pages__dirty"
                                  data-abo-admin-pages-editor-dirty
                                  hidden aria-label="Unsaved changes"></span>
                        </p>
                        <h2 class="abo-admin-pages__editor-title" data-abo-admin-pages-editor-title>—</h2>
                    </div>
                    <button type="button"
                            class="abo-admin-pages__editor-close"
                            aria-label="Close editor (mobile / tablet)"
                            data-abo-admin-pages-editor-close>
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M6 6l12 12M18 6 6 18" stroke="currentColor" stroke-width="1.6"
                                  fill="none" stroke-linecap="round"/>
                        </svg>
                    </button>
                </header>

                <div class="abo-admin-pages__editor-body">

                    {{-- Title with EN/AR switch --}}
                    <section class="abo-admin-pages__block" aria-label="Title">
                        <header class="abo-admin-pages__block-head">
                            <h3 class="abo-admin-pages__block-label">TITLE</h3>
                            <div class="abo-admin-pages__lang-switch" role="tablist" aria-label="Title language">
                                <button type="button" role="tab" aria-selected="true"  data-abo-admin-pages-lang="title" data-abo-admin-pages-lang-value="en" class="is-active">EN</button>
                                <button type="button" role="tab" aria-selected="false" data-abo-admin-pages-lang="title" data-abo-admin-pages-lang-value="ar">AR</button>
                            </div>
                        </header>
                        <label class="sr-only" for="abo-admin-pages-title-en">Title (English)</label>
                        <input id="abo-admin-pages-title-en"
                               class="abo-admin-pages__field-input"
                               type="text"
                               autocomplete="off"
                               data-abo-admin-pages-title-lang="en"
                               data-abo-admin-pages-field="title">
                        <label class="sr-only" for="abo-admin-pages-title-ar">Title (Arabic)</label>
                        <input id="abo-admin-pages-title-ar"
                               class="abo-admin-pages__field-input abo-admin-pages__field-input--rtl"
                               type="text"
                               dir="rtl"
                               lang="ar"
                               autocomplete="off"
                               hidden
                               data-abo-admin-pages-title-lang="ar"
                               data-abo-admin-pages-field="title">
                    </section>

                    {{-- Slug --}}
                    <section class="abo-admin-pages__block" aria-label="URL slug">
                        <h3 class="abo-admin-pages__block-label">URL SLUG
                            <span class="abo-admin-pages__lock-icon"
                                  data-abo-admin-pages-slug-lock
                                  hidden aria-hidden="true">
                                <svg viewBox="0 0 24 24" focusable="false">
                                    <path d="M6 10V8a6 6 0 0 1 12 0v2M5 10h14v10H5z"
                                          stroke="currentColor" stroke-width="1.4"
                                          fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </span>
                        </h3>
                        <div class="abo-admin-pages__slug-row">
                            <span class="abo-admin-pages__slug-prefix">/</span>
                            <label class="sr-only" for="abo-admin-pages-slug">URL slug</label>
                            <input id="abo-admin-pages-slug"
                                   class="abo-admin-pages__field-input abo-admin-pages__field-input--slug"
                                   type="text"
                                   autocomplete="off"
                                   data-abo-admin-pages-slug
                                   data-abo-admin-pages-field="slug">
                        </div>
                        <p class="abo-admin-pages__field-note"
                           data-abo-admin-pages-slug-note>Auto-generated from the English title. You can edit it.</p>
                    </section>

                    {{-- Content editor with EN/AR switch and toolbar --}}
                    <section class="abo-admin-pages__block" aria-label="Content">
                        <header class="abo-admin-pages__block-head">
                            <h3 class="abo-admin-pages__block-label">CONTENT</h3>
                            <div class="abo-admin-pages__lang-switch" role="tablist" aria-label="Content language">
                                <button type="button" role="tab" aria-selected="true"  data-abo-admin-pages-lang="content" data-abo-admin-pages-lang-value="en" class="is-active">EN</button>
                                <button type="button" role="tab" aria-selected="false" data-abo-admin-pages-lang="content" data-abo-admin-pages-lang-value="ar">AR</button>
                            </div>
                        </header>

                        <div class="abo-admin-pages__toolbar-strip" role="toolbar" aria-label="Formatting">
                            <button type="button" class="abo-admin-pages__tb-btn" data-abo-admin-pages-tb="bold"          aria-label="Bold"><strong>B</strong></button>
                            <button type="button" class="abo-admin-pages__tb-btn" data-abo-admin-pages-tb="italic"        aria-label="Italic"><em>I</em></button>
                            <button type="button" class="abo-admin-pages__tb-btn" data-abo-admin-pages-tb="formatBlock"   data-abo-admin-pages-tb-arg="H2" aria-label="Heading">H</button>
                            <button type="button" class="abo-admin-pages__tb-btn" data-abo-admin-pages-tb="insertUnorderedList" aria-label="Bullet list">•</button>
                            <button type="button" class="abo-admin-pages__tb-btn" data-abo-admin-pages-tb="insertOrderedList"   aria-label="Numbered list">1.</button>
                            <button type="button" class="abo-admin-pages__tb-btn" data-abo-admin-pages-tb="createLink"     aria-label="Insert link">↗</button>
                        </div>

                        <label class="sr-only" for="abo-admin-pages-content-en">Content (English)</label>
                        <div id="abo-admin-pages-content-en"
                             class="abo-admin-pages__content"
                             contenteditable="true"
                             role="textbox"
                             aria-multiline="true"
                             aria-label="Content (English)"
                             data-abo-admin-pages-content-lang="en"></div>
                        <label class="sr-only" for="abo-admin-pages-content-ar">Content (Arabic)</label>
                        <div id="abo-admin-pages-content-ar"
                             class="abo-admin-pages__content abo-admin-pages__content--rtl"
                             contenteditable="true"
                             role="textbox"
                             aria-multiline="true"
                             aria-label="Content (Arabic)"
                             dir="rtl"
                             lang="ar"
                             hidden
                             data-abo-admin-pages-content-lang="ar"></div>
                    </section>

                    {{-- Settings (Published) --}}
                    <section class="abo-admin-pages__block" aria-label="Settings">
                        <h3 class="abo-admin-pages__block-label">SETTINGS</h3>

                        <div class="abo-admin-pages__switch-row">
                            <div>
                                <p class="abo-admin-pages__switch-label">Published</p>
                                <p class="abo-admin-pages__switch-note">Off means <strong>Draft</strong> — the page returns 404 on the storefront.</p>
                            </div>
                            <label class="abo-admin-pages__switch">
                                <input type="checkbox"
                                       data-abo-admin-pages-field="status"
                                       data-abo-admin-pages-field-type="bool"
                                       data-abo-admin-pages-bool-true="published"
                                       data-abo-admin-pages-bool-false="draft">
                                <span class="abo-admin-pages__switch-track" aria-hidden="true">
                                    <span class="abo-admin-pages__switch-thumb"></span>
                                </span>
                            </label>
                        </div>
                        <p class="abo-admin-pages__field-note abo-admin-pages__field-note--coral"
                           data-abo-admin-pages-system-warning
                           hidden></p>
                    </section>
                </div>

                <footer class="abo-admin-pages__editor-foot">
                    <div class="abo-admin-pages__editor-foot-right">
                        <button type="button" class="abo-admin-btn abo-admin-btn--ghost"
                                data-abo-admin-pages-action="preview">
                            PREVIEW
                        </button>
                        <button type="button" class="abo-admin-btn abo-admin-btn--primary"
                                data-abo-admin-pages-action="save">
                            SAVE
                        </button>
                    </div>
                </footer>
            </article>

            {{-- Empty editor placeholder --}}
            <div class="abo-admin-pages__editor-placeholder"
                 data-abo-admin-pages-editor-placeholder>
                <p class="abo-admin-pages__placeholder-title">Select a page to edit</p>
                <p class="abo-admin-pages__placeholder-body">
                    Pick one of the pages on the left to edit its title and content.
                </p>
            </div>
        </section>
    </div>

    {{-- ─── Preview modal (store-like frame) ──────────────────── --}}
    <div class="abo-admin-pages__modal"
         role="dialog"
         aria-modal="true"
         aria-labelledby="abo-admin-pages-preview-title"
         hidden
         data-abo-admin-pages-preview>
        <div class="abo-admin-pages__modal-backdrop" data-abo-admin-pages-preview-close></div>
        <div class="abo-admin-pages__modal-card abo-admin-pages__modal-card--wide">
            <header class="abo-admin-pages__modal-head">
                <h2 class="abo-admin-pages__modal-title" id="abo-admin-pages-preview-title">PREVIEW</h2>
                <button type="button" class="abo-admin-pages__drawer-close"
                        aria-label="Close preview"
                        data-abo-admin-pages-preview-close>
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="M6 6l12 12M18 6 6 18" stroke="currentColor" stroke-width="1.6"
                              fill="none" stroke-linecap="round"/>
                    </svg>
                </button>
            </header>
            <div class="abo-admin-pages__preview-frame" data-abo-admin-pages-preview-frame>
                {{-- Preview content injected by pages.js. --}}
            </div>
        </div>
    </div>

    {{-- ─── Single shared toast ──────────────────────────────── --}}
    <div class="abo-admin-pages__toast" role="status" aria-live="polite" hidden
         data-abo-admin-pages-toast>
        <span class="abo-admin-pages__toast-dot" aria-hidden="true"></span>
        <span data-abo-admin-pages-toast-text>Saved</span>
    </div>

    {{-- Dataset generated in PHP at the top of this view and
         consumed by public/admin/js/pages.js. --}}
    <script type="application/json" id="abo-admin-pages-data">{!! $pagesJson !!}</script>
@endsection

@push('head')
    {{-- Page-local stylesheet. Loaded only on the pages page. --}}
    <link rel="stylesheet" href="{{ asset('admin/css/pages.css') }}">
@endpush

@push('scripts')
    {{-- Where the editor sends the admin when the session is gone.
         The `admin` middleware answers a signed-out admin with a
         redirect to this route. --}}
    <script>
        window.__PAGES_LOGIN_URL__ = @json(route('admin.login'));
    </script>

    {{-- Page-local script. Loaded only on the pages page. --}}
    <script src="{{ asset('admin/js/pages.js') }}" defer></script>
@endpush

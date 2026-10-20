{{--
    Admin · Categories (front-end only, static data).

    Extends the existing admin layout. No controller, no model.
    The tree and the edit panel are hydrated from a single static
    array at the top of public/admin/js/categories.js. Every
    section that would need real data is marked TODO(backend).

    Page sections (top to bottom):
      1. Page header    — CATALOG eyebrow + CATE / GORIES title +
                           lime "ADD CATEGORY" CTA on the right.
      2. Two columns    — Left: toolbar + tree + helper. Right: edit
                           panel (or "no selection" placeholder).
      3. States         — Empty / no-results / loading skeleton.
      4. Delete modal   — one shared instance.
      5. Toast          — one shared instance.

    Conventions match admin/products/index.blade.php: classes
    reuse the existing shell (abo-admin-*) where they fit;
    new categories-only classes are prefixed `abo-admin-categories-`.
--}}

@extends('admin.layouts.app')

@section('title', 'Categories · Catalog')
@section('description', 'ABO — admin category manager: tree, edit panel, drag-to-reorder, search.')

@php
    // TODO(backend): replace the static CATEGORIES array in
    // public/admin/js/categories.js with data fetched from the
    // categories table. The view shape (left tree, right panel)
    // is final; only the data source changes.
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
                <span>CATE</span><span class="abo-admin-categories__title-lime">GORIES</span>
            </h1>
            <p class="abo-admin-page__date" id="abo-admin-today-date">Loading date…</p>
        </div>

        <div class="abo-admin-page__head-actions">
            <button type="button"
                    class="abo-admin-btn abo-admin-btn--primary"
                    data-abo-admin-categories-add>
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="1.8"
                          fill="none" stroke-linecap="round"/>
                </svg>
                + ADD CATEGORY
            </button>
        </div>
    </header>

    {{-- ─── Two-column layout ───────────────────────────────────── --}}
    <div class="abo-admin-categories__layout"
         data-abo-admin-categories
         aria-label="Categories">

        {{-- Left: tree + toolbar --}}
        <section class="abo-admin-categories__col" aria-label="Category tree">
            <div class="abo-admin-card abo-admin-categories__toolbar">
                <label class="sr-only" for="abo-admin-categories-search">Filter categories</label>
                <div class="abo-admin-categories__search">
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"
                         class="abo-admin-categories__search-icon">
                        <path d="M21 20.3 16.7 16a8 8 0 1 0-1.4 1.4L20.3 21 21 20.3zM4 10a6 6 0 1 1 12 0 6 6 0 0 1-12 0z"
                              fill="currentColor"/>
                    </svg>
                    <input id="abo-admin-categories-search"
                           type="search"
                           placeholder="Search categories…"
                           autocomplete="off"
                           data-abo-admin-categories-search>
                    <button type="button"
                            class="abo-admin-categories__search-clear"
                            aria-label="Clear search">
                        ×
                    </button>
                </div>

                <button type="button"
                        class="abo-admin-btn abo-admin-btn--ghost"
                        aria-pressed="false"
                        data-abo-admin-categories-expand-all>
                    EXPAND ALL
                </button>
            </div>

            <article class="abo-admin-card abo-admin-categories__tree-card"
                     aria-label="Categories">
                {{-- The tree is rendered by public/admin/js/categories.js
                     from the CATEGORIES static array. Every <li role="treeitem">
                     gets full keyboard support (Arrow keys, Enter, Space). --}}
                <ul class="abo-admin-categories__tree"
                    role="tree"
                    aria-label="Categories"
                    data-abo-admin-categories-tree></ul>

                {{-- Helper text under the tree (shown only when a tree is visible). --}}
                <p class="abo-admin-categories__helper"
                   data-abo-admin-categories-helper>
                    Drag the handle to reorder. Same order appears in the store menu.
                </p>

                {{-- States: skeleton / empty / no-results are mutually exclusive
                     and toggled by categories.js. The skeleton is the default. --}}
                <div class="abo-admin-categories__state" data-abo-admin-categories-state="empty" hidden>
                    <p>No categories yet. Add your first category.</p>
                    <button type="button"
                            class="abo-admin-btn abo-admin-btn--primary"
                            data-abo-admin-categories-add-empty>
                        + ADD CATEGORY
                    </button>
                </div>

                <div class="abo-admin-categories__state" data-abo-admin-categories-state="noresults" hidden>
                    <p>No results. Try clearing the search above.</p>
                </div>

                <div class="abo-admin-categories__state" data-abo-admin-categories-state="loading"
                     data-abo-admin-categories-skeleton>
                    <div class="abo-admin-categories__skeleton" aria-hidden="true">
                        @for ($i = 0; $i < 5; $i++)
                            <div class="abo-admin-categories__skeleton-row">
                                <span class="abo-admin-categories__skeleton-cell" style="width: 12px; height: 14px;"></span>
                                <span class="abo-admin-categories__skeleton-cell" style="width: 12px; height: 14px;"></span>
                                <span class="abo-admin-categories__skeleton-cell abo-admin-categories__skeleton-cell--thumb"></span>
                                <span class="abo-admin-categories__skeleton-cell" style="width: 70%;"></span>
                                <span class="abo-admin-categories__skeleton-cell" style="width: 60px;"></span>
                                <span class="abo-admin-categories__skeleton-cell abo-admin-categories__skeleton-cell--toggle"></span>
                            </div>
                        @endfor
                    </div>
                </div>
            </article>
        </section>

        {{-- Right: edit panel (full card on desktop, slide-over on tablet/mobile) --}}
        <aside class="abo-admin-categories__col abo-admin-categories__panel"
               aria-label="Edit category"
               data-abo-admin-categories-panel>

            {{-- Slide-over backdrop on tablet/mobile --}}
            <div class="abo-admin-categories__panel-overlay"
                 hidden
                 data-abo-admin-categories-panel-overlay
                 aria-hidden="true"></div>

            {{-- "No selection" state --}}
            <div class="abo-admin-categories__panel-empty"
                 data-abo-admin-categories-panel-no-selection>
                <p style="margin:0 0 0.4rem;color:var(--abo-admin-text);font-weight:600;">No category selected.</p>
                <p style="margin:0;">Click a row in the tree, or add a new one.</p>
            </div>

            {{-- Form (toggled by categories.js once a row is selected) --}}
            <div data-abo-admin-categories-panel-form hidden>

                <div class="abo-admin-categories__panel-head">
                    <h2 class="abo-admin-categories__panel-title">
                        <span data-abo-admin-categories-panel-title-text>EDIT CATEGORY</span>
                        <span class="abo-admin-categories__dirty-dot"
                              aria-label="Unsaved changes"
                              data-abo-admin-categories-dirty-dot></span>
                    </h2>
                    <button type="button"
                            class="abo-admin-categories__panel-close"
                            aria-label="Close edit panel"
                            data-abo-admin-categories-panel-close>
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M6 6l12 12M18 6 6 18"
                                  stroke="currentColor" stroke-width="1.6"
                                  fill="none" stroke-linecap="round"/>
                        </svg>
                    </button>
                </div>

                <div class="abo-admin-categories__panel-body">

                    {{-- Cover image (64x64 preview) --}}
                    <div class="abo-admin-categories__cover">
                        <div class="abo-admin-categories__cover-preview"
                             aria-hidden="true"
                             data-abo-admin-categories-cover-preview>
                            <span class="abo-admin-categories__cover-letter">·</span>
                        </div>
                        <div class="abo-admin-categories__cover-actions">
                            <input type="file"
                                   accept="image/*"
                                   class="abo-admin-categories__cover-file"
                                   data-abo-admin-categories-cover-file>
                            <button type="button"
                                    class="abo-admin-categories__cover-btn"
                                    data-abo-admin-categories-cover-change>
                                CHANGE
                            </button>
                            <button type="button"
                                    class="abo-admin-categories__cover-btn abo-admin-categories__cover-btn--danger"
                                    disabled
                                    data-abo-admin-categories-cover-remove>
                                REMOVE
                            </button>
                        </div>
                    </div>

                    {{-- Name (with Arabic / English switch) --}}
                    <div class="abo-admin-categories__field">
                        <label for="abo-admin-categories-name">Name</label>
                        <div class="abo-admin-categories__lang"
                             role="tablist"
                             aria-label="Language"
                             data-abo-admin-categories-lang>
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
                        <label class="sr-only" for="abo-admin-categories-name">Category name</label>
                        <input id="abo-admin-categories-name"
                               type="text"
                               placeholder="e.g. Hoodies"
                               autocomplete="off"
                               data-abo-admin-categories-name>
                    </div>

                    {{-- Slug --}}
                    <div class="abo-admin-categories__field">
                        <label for="abo-admin-categories-slug">Slug</label>
                        <input id="abo-admin-categories-slug"
                               type="text"
                               placeholder="e.g. hoodies"
                               autocomplete="off"
                               data-abo-admin-categories-slug>
                    </div>

                    {{-- Parent --}}
                    <div class="abo-admin-categories__field">
                        <label for="abo-admin-categories-parent">Parent</label>
                        <label class="sr-only" for="abo-admin-categories-parent">Parent category</label>
                        <select id="abo-admin-categories-parent"
                                data-abo-admin-categories-parent>
                            {{-- Options injected by categories.js from the live CATEGORIES array.
                                 Each row holds either "None (top level)" or one of the top-level
                                 categories that is not the row currently being edited. --}}
                        </select>
                    </div>

                    {{-- Description --}}
                    <div class="abo-admin-categories__field">
                        <label for="abo-admin-categories-description">Description</label>
                        <label class="sr-only" for="abo-admin-categories-description">Category description</label>
                        <textarea id="abo-admin-categories-description"
                                  placeholder="Short description shown on the category page."
                                  data-abo-admin-categories-description></textarea>
                    </div>

                    {{-- Switches --}}
                    <div class="abo-admin-categories__switch"
                         data-abo-admin-categories-switch="visible">
                        <div>
                            <span class="abo-admin-categories__switch-label">Visible in store</span>
                            <p class="abo-admin-categories__switch-hint">Show this category on the storefront.</p>
                        </div>
                        <label class="abo-admin-categories__toggle" aria-label="Visible in store">
                            <input type="checkbox"
                                   checked
                                   aria-checked="true"
                                   data-abo-admin-categories-visible>
                            <span class="abo-admin-categories__toggle-track"></span>
                            <span class="abo-admin-categories__toggle-thumb"></span>
                        </label>
                    </div>

                    <div class="abo-admin-categories__switch"
                         data-abo-admin-categories-switch="active">
                        <div>
                            <span class="abo-admin-categories__switch-label">Active</span>
                            <p class="abo-admin-categories__switch-hint">Inactive categories stay hidden in the catalog.</p>
                        </div>
                        <label class="abo-admin-categories__toggle" aria-label="Active">
                            <input type="checkbox"
                                   checked
                                   aria-checked="true"
                                   data-abo-admin-categories-switch-active>
                            <span class="abo-admin-categories__toggle-track"></span>
                            <span class="abo-admin-categories__toggle-thumb"></span>
                        </label>
                    </div>

                    <div class="abo-admin-categories__switch"
                         data-abo-admin-categories-switch="homepage">
                        <div>
                            <span class="abo-admin-categories__switch-label">Show in homepage strip</span>
                            <p class="abo-admin-categories__switch-hint">Surface this category in the top strip on the home page.</p>
                        </div>
                        <label class="abo-admin-categories__toggle" aria-label="Show in homepage strip">
                            <input type="checkbox"
                                   aria-checked="false"
                                   data-abo-admin-categories-homepage>
                            <span class="abo-admin-categories__toggle-track"></span>
                            <span class="abo-admin-categories__toggle-thumb"></span>
                        </label>
                    </div>

                    {{-- Action buttons --}}
                    <div class="abo-admin-categories__actions">
                        <button type="button"
                                class="abo-admin-btn abo-admin-btn--primary"
                                data-abo-admin-categories-save>
                            SAVE
                        </button>
                        <button type="button"
                                class="abo-admin-categories__delete"
                                aria-label="Delete category"
                                data-abo-admin-categories-delete>
                            DELETE
                        </button>
                    </div>
                    <p class="abo-admin-categories__delete-note"
                       data-abo-admin-categories-delete-note
                       hidden></p>
                </div>
            </div>
        </aside>
    </div>

    {{-- ─── Single shared delete modal ──────────────────────────── --}}
    <div class="abo-admin-categories__modal"
         role="dialog"
         aria-modal="true"
         aria-labelledby="abo-admin-categories-modal-title"
         hidden
         data-abo-admin-categories-modal>
        <div class="abo-admin-categories__modal-backdrop"
             data-abo-admin-categories-modal-close></div>
        <div class="abo-admin-categories__modal-card">
            <h2 class="abo-admin-categories__modal-title"
                id="abo-admin-categories-modal-title">DELETE CATEGORY</h2>
            <p class="abo-admin-categories__modal-body"
               data-abo-admin-categories-modal-body>Are you sure? This action cannot be undone.</p>
            <div class="abo-admin-categories__modal-actions">
                <button type="button"
                        class="abo-admin-btn abo-admin-btn--ghost"
                        data-abo-admin-categories-modal-cancel>CANCEL</button>
                <button type="button"
                        class="abo-admin-categories__modal-confirm"
                        data-abo-admin-categories-modal-confirm>DELETE</button>
            </div>
        </div>
    </div>

    {{-- ─── Single shared toast ────────────────────────────────── --}}
    <div class="abo-admin-categories__toast"
         role="status"
         aria-live="polite"
         hidden
         data-abo-admin-categories-toast>
        <span class="abo-admin-categories__toast-dot abo-admin-categories__toast-dot--lime"
              aria-hidden="true"
              data-abo-admin-categories-toast-dot></span>
        <span data-abo-admin-categories-toast-text>Saved (demo)</span>
    </div>
@endsection

@push('head')
    {{-- Page-local stylesheet. Loaded only on the categories page. --}}
    <link rel="stylesheet" href="{{ asset('admin/css/categories.css') }}">
@endpush

@push('scripts')
    {{-- Injected server data from DB --}}
    <script>
        window.__CATEGORIES__ = @json($categoriesJson ?? []);
    </script>
    {{-- Page-local script. Loaded only on the categories page. --}}
    <script src="{{ asset('admin/js/categories.js') }}" defer></script>
@endpush

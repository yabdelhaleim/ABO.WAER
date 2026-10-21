{{--
    Admin · Product form — shared create + edit (front-end only).

    Used by both /admin/products/create and /admin/products/{id}/edit.
    The mode and the prefilled product are passed in by the route
    closure. In create mode the inputs are empty; in edit mode the
    static array in products.js is used to hydrate the fields via
    data-attribute (so the same view serves both routes without
    server-side lookups).

    Layout (two columns on desktop, stacked on tablet/mobile):
      LEFT (1.8fr):
        1. Basic info (name, SKU, description, language switch)
        2. Images (up to 8 slots, drag to reorder, colour attach)
        3. Variants (colours + sizes chips, generated matrix table)
      RIGHT (1fr):
        1. Status (segmented Active / Draft)
        2. Pricing (price, compare-at, cost, live margin)
        3. Organize (category, size guide, tags)
        4. Store preview (live mini product card)

    Top action bar: ← Products, Discard, Save as draft, SAVE AND PUBLISH.
    Buttons are visual only — clicking the lime button shows the
    "Saved (demo)" toast wired by products.js.

    Reuses abo-admin-card, abo-admin-btn, abo-admin-segmented,
    abo-admin-pill, etc. New classes are prefixed abo-admin-products-.
--}}

@extends('admin.layouts.app')

@section('title', ($mode === 'edit' ? 'Edit product' : 'Add product') . ' · Catalog')
@section('description', 'ABO — admin product form: details, images, variants, pricing and store preview.')

@php
    // $mode is 'create' or 'edit', $product / $categories / $colors / $sizes
    // are passed by Admin\ProductController::create() / edit() or fallback routes.
    $isEdit = $mode === 'edit';
    $isModel = $product instanceof \App\Models\Product;
    $productKey = $isModel ? $product->getRouteKey() : (is_array($product) ? ($product['id'] ?? null) : null);
    $formAction = ($isEdit && $isModel)
        ? route('admin.products.update', $product)
        : route('admin.products.store');

    $colorsList = $colors ?? collect();
    $sizesList = $sizes ?? collect();
    $categoriesList = $categories ?? collect();
@endphp

@section('content')
    {{-- Real Eloquent payload — colours + sizes the JS can pick from. --}}
    @php
        $formPayload = [
            'colors' => $colorsList->map(fn ($c) => [
                'id' => (string) $c->id,
                'name' => $c->name,
                'hex' => $c->hex_code ?? '#000000',
            ])->values(),
            'sizes' => $sizesList->map(fn ($s) => [
                'id' => (string) $s->id,
                'name' => $s->name,
                'group' => $s->group ?? '',
            ])->values(),
            'product' => ($isEdit && $isModel) ? [
                'id' => (string) $product->id,
                'name' => $product->name,
                'nameAr' => $product->name_ar ?? '',
                'categoryId' => $product->category_id ? (string) $product->category_id : null,
                'description' => $product->description ?? '',
                'archived' => (bool) $product->is_archived,
                'featured' => (bool) $product->is_featured,
                'colors' => $product->colors ? $product->colors->pluck('id')->map(fn ($id) => (string) $id)->all() : [],
                'variants' => $product->variants ? $product->variants->map(fn ($v) => [
                    'colorId' => (string) $v->color_id,
                    'sizeId' => (string) $v->size_id,
                    'price' => (float) $v->price,
                    'cost' => (float) $v->cost,
                    'discountPrice' => $v->discount_price !== null ? (float) $v->discount_price : null,
                    'quantity' => (int) $v->quantity,
                ])->values() : [],
            ] : null,
        ];
    @endphp
    <script type="application/json"
            data-abo-admin-products-form-payload>{!! json_encode($formPayload, JSON_THROW_ON_ERROR) !!}</script>

    {{-- ─── Top action bar ─────────────────────────────────────── --}}
    <header class="abo-admin-products__formbar">
        <a href="{{ route('admin.products.index') }}" class="abo-admin-products__formbar-back">
            <span aria-hidden="true">←</span> Products
        </a>

        <h1 class="abo-admin-products__formbar-title">
            {{ $isEdit ? 'EDIT PRODUCT' : 'ADD PRODUCT' }}
        </h1>

        <div class="abo-admin-products__formbar-actions">
            <button type="button" class="abo-admin-btn abo-admin-btn--ghost"
                    data-abo-admin-products-form-action="discard">
                DISCARD
            </button>
            <button type="button" class="abo-admin-btn abo-admin-btn--ghost"
                    data-abo-admin-products-form-action="draft">
                SAVE AS DRAFT
            </button>
            <button type="button" class="abo-admin-btn abo-admin-btn--primary"
                    data-abo-admin-products-form-action="publish">
                SAVE AND PUBLISH
            </button>
        </div>
    </header>

    <form class="abo-admin-products__form"
          method="post"
          action="{{ $formAction }}"
          data-abo-admin-products-form
          data-mode="{{ $mode }}"
          @if ($isEdit) data-product-id="{{ $isModel ? $product->id : (is_array($product) ? ($product['id'] ?? '') : ($product->id ?? '')) }}" @endif
          enctype="multipart/form-data">
        @csrf
        @if ($isEdit)
            @method('PUT')
        @endif

        <div class="abo-admin-products__form-grid">
            {{-- ─── LEFT COLUMN ──────────────────────────────────── --}}
            <div class="abo-admin-products__form-col">
                {{-- 1. Basic info --}}
                <article class="abo-admin-card">
                    <header class="abo-admin-card__head">
                        <h2 class="abo-admin-card__title">BASIC INFO</h2>
                    </header>

                    <div class="abo-admin-products__lang" role="tablist" aria-label="Language">
                        <button type="button" role="tab" aria-selected="true"
                                class="is-active"
                                data-abo-admin-products-lang="en">EN</button>
                        <button type="button" role="tab" aria-selected="false"
                                data-abo-admin-products-lang="ar">AR</button>
                    </div>

                    <div class="abo-admin-products__field">
                        <label for="abo-admin-products-name">Product name</label>
                        <input id="abo-admin-products-name"
                               type="text"
                               name="name"
                               placeholder="e.g. Oversize hoodie"
                               autocomplete="off"
                               data-abo-admin-products-field="name">
                    </div>

                    <div class="abo-admin-products__field">
                        <label for="abo-admin-products-sku">SKU</label>
                        <input id="abo-admin-products-sku"
                               type="text"
                               name="sku"
                               placeholder="ABO-0000"
                               autocomplete="off"
                               data-abo-admin-products-field="sku">
                    </div>

                    <div class="abo-admin-products__field">
                        <label for="abo-admin-products-description">Description</label>
                        <textarea id="abo-admin-products-description"
                                  name="description"
                                  rows="4"
                                  placeholder="Short product description…"
                                  data-abo-admin-products-field="description"></textarea>
                    </div>
                </article>

                {{-- 2. Images --}}
                <article class="abo-admin-card">
                    <header class="abo-admin-card__head">
                        <h2 class="abo-admin-card__title">IMAGES</h2>
                        <p class="abo-admin-card__soft">Up to 8 images</p>
                    </header>

                    <p class="abo-admin-products__hint">
                        Attach images to a colour so the store switches photos when the customer picks it.
                    </p>

                    <div class="abo-admin-products__colour-picker" role="group" aria-label="Attach next image to colour">
                        <span class="abo-admin-products__hint">Attach to:</span>
                        @foreach ([
                            ['value' => '', 'label' => 'None',     'hex' => null],
                            ['value' => 'black', 'label' => 'Black', 'hex' => '#0D0F11'],
                            ['value' => 'grey',  'label' => 'Grey',  'hex' => '#8D928A'],
                            ['value' => 'olive', 'label' => 'Olive', 'hex' => '#5C6A48'],
                            ['value' => 'lime',  'label' => 'Lime',  'hex' => '#C6FF00'],
                        ] as $opt)
                            <button type="button"
                                    class="abo-admin-products__colour-chip @if ($loop->first) is-active @endif"
                                    data-abo-admin-products-colour="{{ $opt['value'] }}"
                                    title="{{ $opt['label'] }}"
                                    aria-pressed="{{ $loop->first ? 'true' : 'false' }}">
                                @if ($opt['hex'])
                                    <span class="abo-admin-products__colour-swatch"
                                          style="--swatch: {{ $opt['hex'] }};"></span>
                                @endif
                                {{ $opt['label'] }}
                            </button>
                        @endforeach
                    </div>

                    <div class="abo-admin-products__images"
                         role="list"
                         aria-label="Product images"
                         data-abo-admin-products-images>
                        {{-- Slots are injected by products.js. First slot
                             gets a "COVER" label automatically. --}}
                    </div>
                </article>

                {{-- 3. Variants --}}
                <article class="abo-admin-card">
                    <header class="abo-admin-card__head">
                        <h2 class="abo-admin-card__title">VARIANTS</h2>
                    </header>

                    <p class="abo-admin-products__hint">COLOURS</p>
                    <div class="abo-admin-products__chips" role="group" aria-label="Available colours"
                         data-abo-admin-products-variant-colors>
                        @foreach ([
                            ['value' => 'black', 'label' => 'Black', 'hex' => '#0D0F11'],
                            ['value' => 'grey',  'label' => 'Grey',  'hex' => '#8D928A'],
                            ['value' => 'olive', 'label' => 'Olive', 'hex' => '#5C6A48'],
                            ['value' => 'lime',  'label' => 'Lime',  'hex' => '#C6FF00'],
                            ['value' => 'coral', 'label' => 'Coral', 'hex' => '#FF7A5C'],
                        ] as $c)
                            <button type="button"
                                    class="abo-admin-products__chip is-active"
                                    data-abo-admin-products-variant-color="{{ $c['value'] }}"
                                    aria-pressed="true">
                                <span class="abo-admin-products__chip-dot"
                                      style="--swatch: {{ $c['hex'] }};" aria-hidden="true"></span>
                                {{ $c['label'] }}
                            </button>
                        @endforeach
                    </div>

                    <p class="abo-admin-products__hint">SIZES</p>
                    <div class="abo-admin-products__chips" role="group" aria-label="Available sizes"
                         data-abo-admin-products-variant-sizes>
                        @foreach (['S', 'M', 'L', 'XL', 'XXL'] as $s)
                            <button type="button"
                                    class="abo-admin-products__chip is-active"
                                    data-abo-admin-products-variant-size="{{ $s }}"
                                    aria-pressed="true">{{ $s }}</button>
                        @endforeach
                    </div>

                    <p class="abo-admin-products__helper">
                        Rows are generated automatically from the colours and sizes selected above.
                    </p>

                    <div class="abo-admin-products__bulk-set">
                        <label for="abo-admin-products-set-all">Set stock for all</label>
                        <input id="abo-admin-products-set-all"
                               type="number"
                               min="0"
                               step="1"
                               placeholder="0"
                               data-abo-admin-products-set-all>
                    </div>

                    <div class="abo-admin-orders__wrap">
                        <table class="abo-admin-orders__table abo-admin-products__variants">
                            <thead>
                                <tr>
                                    <th scope="col">Variant</th>
                                    <th scope="col">SKU</th>
                                    <th scope="col">Stock</th>
                                    <th scope="col">Price override</th>
                                </tr>
                            </thead>
                            <tbody data-abo-admin-products-variant-rows>
                                {{-- Generated by products.js from the
                                     selected colours × sizes matrix. --}}
                            </tbody>
                        </table>
                    </div>
                </article>
            </div>

            {{-- ─── RIGHT COLUMN ─────────────────────────────────── --}}
            <div class="abo-admin-products__form-col">
                {{-- 1. Status --}}
                <article class="abo-admin-card">
                    <header class="abo-admin-card__head">
                        <h2 class="abo-admin-card__title">STATUS</h2>
                    </header>

                    <div class="abo-admin-segmented abo-admin-products__segmented"
                         role="tablist"
                         aria-label="Product status"
                         data-abo-admin-products-status>
                        <button type="button" role="tab" aria-selected="true"
                                class="is-active" data-status="active">Active</button>
                        <button type="button" role="tab" aria-selected="false"
                                data-status="draft">Draft</button>
                    </div>
                </article>

                {{-- 2. Pricing --}}
                <article class="abo-admin-card">
                    <header class="abo-admin-card__head">
                        <h2 class="abo-admin-card__title">PRICING</h2>
                    </header>

                    <div class="abo-admin-products__field">
                        <label for="abo-admin-products-price">Price (EGP)</label>
                        <input id="abo-admin-products-price"
                               type="number"
                               min="0"
                               step="1"
                               placeholder="0"
                               inputmode="numeric"
                               data-abo-admin-products-field="price">
                    </div>

                    <div class="abo-admin-products__field">
                        <label for="abo-admin-products-compare">Compare at (EGP)</label>
                        <input id="abo-admin-products-compare"
                               type="number"
                               min="0"
                               step="1"
                               placeholder="0"
                               inputmode="numeric"
                               data-abo-admin-products-field="compare">
                    </div>

                    <div class="abo-admin-products__field">
                        <label for="abo-admin-products-cost">Cost (EGP)</label>
                        <input id="abo-admin-products-cost"
                               type="number"
                               min="0"
                               step="1"
                               placeholder="0"
                               inputmode="numeric"
                               data-abo-admin-products-field="cost">
                    </div>

                    <div class="abo-admin-products__margin" aria-live="polite">
                        <div>
                            <span class="abo-admin-products__margin-label">Margin</span>
                            <span class="abo-admin-products__margin-value"
                                  data-abo-admin-products-margin="pct">—</span>
                        </div>
                        <div>
                            <span class="abo-admin-products__margin-label">Profit</span>
                            <span class="abo-admin-products__margin-value"
                                  data-abo-admin-products-margin="profit">—</span>
                        </div>
                    </div>
                </article>

                {{-- 3. Organize --}}
                <article class="abo-admin-card">
                    <header class="abo-admin-card__head">
                        <h2 class="abo-admin-card__title">ORGANIZE</h2>
                    </header>

                    <div class="abo-admin-products__field">
                        <label for="abo-admin-products-form-category">Category</label>
                        <select id="abo-admin-products-form-category"
                                class="abo-admin-products__select"
                                name="category_id"
                                data-abo-admin-products-field="category">
                            <option value="">Select category</option>
                            @foreach ($categoriesList as $cat)
                                <option value="{{ $cat->id }}"
                                    @selected(old('category_id', $isModel ? $product->category_id : (is_array($product) ? ($product['category_id'] ?? null) : null)) == $cat->id)>
                                    {{ $cat->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="abo-admin-products__field">
                        <label for="abo-admin-products-size-guide">Size guide</label>
                        <select id="abo-admin-products-size-guide"
                                class="abo-admin-products__select"
                                data-abo-admin-products-field="size_guide">
                            <option value="">No size guide</option>
                            <option value="mens-standard">Men's standard</option>
                            <option value="mens-oversize">Men's oversize</option>
                            <option value="womens">Women's</option>
                        </select>
                    </div>

                    <div class="abo-admin-products__field">
                        <label for="abo-admin-products-tags">Tags</label>
                        <div class="abo-admin-products__tags" data-abo-admin-products-tags>
                            <ul class="abo-admin-products__taglist" data-abo-admin-products-taglist
                                aria-label="Selected tags"></ul>
                            <input id="abo-admin-products-tags"
                                   type="text"
                                   class="abo-admin-products__taginput"
                                   placeholder="Press Enter to add…"
                                   autocomplete="off"
                                   data-abo-admin-products-taginput>
                        </div>
                    </div>
                </article>

                {{-- 4. Store preview --}}
                <article class="abo-admin-card">
                    <header class="abo-admin-card__head">
                        <h2 class="abo-admin-card__title">STORE PREVIEW</h2>
                    </header>

                    <div class="abo-admin-products__preview" aria-label="Live store preview">
                        <div class="abo-admin-products__preview-img" aria-hidden="true">
                            <span>COVER</span>
                        </div>
                        <p class="abo-admin-products__preview-name"
                           data-abo-admin-products-preview="name">Product name</p>
                        <p class="abo-admin-products__preview-price">
                            <span data-abo-admin-products-preview="price">0 EGP</span>
                            <span class="abo-admin-products__preview-compare"
                                  data-abo-admin-products-preview="compare" hidden>0 EGP</span>
                        </p>
                    </div>
                </article>
            </div>
        </div>
    </form>

    {{-- ─── Single shared modal + toast containers ──────────────── --}}
    <div class="abo-admin-products__modal" hidden
         role="dialog"
         aria-modal="true"
         aria-labelledby="abo-admin-products-modal-title"
         data-abo-admin-products-modal>
        <div class="abo-admin-products__modal-backdrop" data-abo-admin-products-modal-close></div>
        <div class="abo-admin-products__modal-card">
            <h2 class="abo-admin-products__modal-title" id="abo-admin-products-modal-title">DISCARD CHANGES</h2>
            <p class="abo-admin-products__modal-body"
               data-abo-admin-products-modal-body>
                Are you sure you want to discard the changes you made?
            </p>
            <div class="abo-admin-products__modal-actions">
                <button type="button" class="abo-admin-btn abo-admin-btn--ghost"
                        data-abo-admin-products-modal-close>CANCEL</button>
                <button type="button" class="abo-admin-products__modal-confirm"
                        data-abo-admin-products-modal-confirm>DISCARD</button>
            </div>
        </div>
    </div>

    <div class="abo-admin-products__toast" role="status" aria-live="polite" hidden
         data-abo-admin-products-toast>
        <span class="abo-admin-products__toast-dot" aria-hidden="true"></span>
        <span class="abo-admin-products__toast-text" data-abo-admin-products-toast-text>Saved (demo)</span>
    </div>
@endsection

@push('head')
    <link rel="stylesheet" href="{{ asset('admin/css/products.css') }}">
@endpush

@push('scripts')
    <script src="{{ asset('admin/js/products.js') }}" defer></script>
@endpush

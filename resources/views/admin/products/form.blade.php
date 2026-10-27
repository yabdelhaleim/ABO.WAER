{{--
    Admin · Product form — shared create + edit.

    Used by both /admin/products/create and /admin/products/{id}/edit.
    The mode and the prefilled product come from
    Admin\ProductController::create() / edit(), which read the
    products table. The form used to be hydrated from a hard-coded
    sample array in products.js, so it opened on an empty name and an
    empty variant table — and saving it wiped the variants the
    product actually had.

    Everything on the page is a real input that posts to the server:
    the colours and the sizes come from the catalogue, the variant
    rows carry the prices and the stock, the images are the ones the
    product has. products.js only rebuilds the colour × size matrix
    when the operator changes the selection.

    Layout (two columns on desktop, stacked on tablet/mobile):
      LEFT (1.8fr):
        1. Basic info (name, SKU, description, language switch)
        2. Images (up to 8 slots, drag to reorder, colour attach)
        3. Variants (colours + sizes chips, generated matrix table)
      RIGHT (1fr):
        1. Status (segmented Active / Archived)
        2. Pricing (price, compare-at, cost, live margin)
        3. Organize (category, size guide, tags)
        4. Store preview (live mini product card)

    Top action bar: ← Products, Discard, Save as draft, SAVE AND PUBLISH.
    The two save buttons set the status of the product and submit the
    form; the discard button asks first.

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

    // The images the product already carries, and the colour each one
    // hangs on, so the form can show them and let the operator drop one.
    $existingImages = $isModel ? $product->images : collect();
    $imageColors = $isModel
        ? $product->productColors->mapWithKeys(fn ($pivot) => [$pivot->id => $pivot->color])->filter()
        : collect();

    // The colour × size matrix the product offers, one row per pair.
    $variantRows = $isModel
        ? app(\App\Services\ProductService::class)->formMatrix($product, $colorsList, $sizesList)
        : [];

    // The colours the product offers — the pivot plus the colours its
    // variants carry.
    $productColorIds = $isModel
        ? app(\App\Services\ProductService::class)->formColorIds($product)
        : [];

    $isArchived = $isModel ? (bool) $product->is_archived : false;
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
                'slug' => $product->slug,
                'categoryId' => $product->category_id ? (string) $product->category_id : null,
                'description' => $product->description ?? '',
                'archived' => (bool) $product->is_archived,
                'featured' => (bool) $product->is_featured,
                'colors' => $product->colors ? $product->colors->pluck('id')->map(fn ($id) => (string) $id)->all() : [],
                'sizes' => $product->variants->pluck('size_id')->map(fn ($id) => (string) $id)->unique()->values()->all(),
                'variants' => $product->variants ? $product->variants->map(fn ($v) => [
                    'colorId' => (string) $v->color_id,
                    'sizeId' => (string) $v->size_id,
                    'sku' => $v->sku,
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
                               value="{{ old('name', $isModel ? $product->name : '') }}"
                               placeholder="e.g. Oversize hoodie"
                               autocomplete="off"
                               data-abo-admin-products-field="name"
                               data-abo-admin-products-lang-field="en">
                    </div>

                    <div class="abo-admin-products__field" data-abo-admin-products-lang-panel="ar" hidden>
                        <label for="abo-admin-products-name-ar">اسم المنتج بالعربية</label>
                        <input id="abo-admin-products-name-ar"
                               type="text"
                               name="name_ar"
                               value="{{ old('name_ar', $isModel ? ($product->name_ar ?? '') : '') }}"
                               placeholder="مثال: هودي اوفرسايز"
                               autocomplete="off"
                               dir="rtl"
                               data-abo-admin-products-field="nameAr"
                               data-abo-admin-products-lang-field="ar">
                    </div>

                    <div class="abo-admin-products__field">
                        <label for="abo-admin-products-slug">URL slug</label>
                        <input id="abo-admin-products-slug"
                               type="text"
                               name="slug"
                               value="{{ old('slug', $isModel ? $product->slug : '') }}"
                               placeholder="oversize-hoodie"
                               autocomplete="off"
                               data-abo-admin-products-field="slug">
                        <p class="abo-admin-products__helper">
                            The address of the product in the store. Changing it breaks the links
                            that were already shared.
                        </p>
                    </div>

                    <div class="abo-admin-products__field">
                        <label for="abo-admin-products-sku">SKU</label>
                        <input id="abo-admin-products-sku"
                               type="text"
                               name="sku"
                               value="{{ $isModel ? ($product->variants->first()?->sku ?? '') : '' }}"
                               placeholder="ABO-0000"
                               autocomplete="off"
                               readonly
                               aria-readonly="true"
                               data-abo-admin-products-field="sku">
                        <p class="abo-admin-products__helper">
                            The SKU is derived from the product, the colour and the size of every row
                            in the variants table.
                        </p>
                    </div>

                    <div class="abo-admin-products__field">
                        <label for="abo-admin-products-description">Description</label>
                        <textarea id="abo-admin-products-description"
                                  name="description"
                                  rows="4"
                                  placeholder="Short product description…"
                                  data-abo-admin-products-field="description">{{ old('description', $isModel ? ($product->description ?? '') : '') }}</textarea>
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

                    <div class="abo-admin-products__images"
                         role="list"
                         aria-label="Product images"
                         data-abo-admin-products-images>
                        @forelse ($existingImages as $image)
                            @php($imageColor = $imageColors->get($image->product_color_id))
                            <div class="abo-admin-products__image-slot filled"
                                 role="listitem"
                                 data-abo-admin-products-image="{{ $image->id }}">
                                <img src="{{ $image->thumb_url ?: $image->url }}"
                                     alt="{{ $imageColor?->name ?? 'Product image' }}"
                                     loading="lazy"
                                     decoding="async">
                                @if ($imageColor)
                                    <span class="abo-admin-products__image-colour">
                                        <span class="abo-admin-products__image-colour-dot"
                                              style="--swatch: {{ $imageColor->hex_code ?? '#8D928A' }};"></span>
                                        {{ $imageColor->name }}
                                    </span>
                                @endif
                                <label class="abo-admin-products__image-remove">
                                    <input type="checkbox"
                                           name="removed_images[]"
                                           value="{{ $image->id }}"
                                           data-abo-admin-products-image-remove>
                                    <span>Remove</span>
                                </label>
                            </div>
                        @empty
                            <p class="abo-admin-products__helper">No images yet.</p>
                        @endforelse
                    </div>

                    {{-- One upload per colour. The name carries the colour id, which is
                         the key ProductRequest expects: images[<color id>][]. --}}
                    <div class="abo-admin-products__image-uploads">
                        @foreach ($colorsList as $color)
                            <label class="abo-admin-products__image-upload">
                                <span class="abo-admin-products__image-colour-dot"
                                      style="--swatch: {{ $color->hex_code ?? '#8D928A' }};"></span>
                                <span class="abo-admin-products__image-upload-name">{{ $color->name }}</span>
                                <input type="file"
                                       name="images[{{ $color->id }}][]"
                                       accept="image/jpeg,image/png,image/webp"
                                       multiple
                                       data-abo-admin-products-upload>
                            </label>
                        @endforeach
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
                        @foreach ($colorsList as $c)
                            @php($colorSelected = in_array($c->id, $productColorIds, true))
                            <label class="abo-admin-products__chip @if ($colorSelected) is-active @endif"
                                   data-abo-admin-products-variant-color="{{ $c->id }}">
                                <input type="checkbox"
                                       name="colors[]"
                                       value="{{ $c->id }}"
                                       @checked($colorSelected)
                                       data-abo-admin-products-variant-color-input>
                                <span class="abo-admin-products__chip-dot"
                                      style="--swatch: {{ $c->hex_code ?? '#8D928A' }};" aria-hidden="true"></span>
                                {{ $c->name }}
                            </label>
                        @endforeach
                    </div>

                    <p class="abo-admin-products__hint">SIZES</p>
                    <div class="abo-admin-products__chips" role="group" aria-label="Available sizes"
                         data-abo-admin-products-variant-sizes>
                        @foreach ($sizesList as $s)
                            @php($sizeSelected = $isModel && $product->variants->contains('size_id', $s->id))
                            <label class="abo-admin-products__chip @if ($sizeSelected) is-active @endif"
                                   data-abo-admin-products-variant-size="{{ $s->id }}">
                                <input type="checkbox"
                                       name="sizes[]"
                                       value="{{ $s->id }}"
                                       @checked($sizeSelected)
                                       data-abo-admin-products-variant-size-input>
                                {{ $s->name }}
                            </label>
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
                                    <th scope="col">Price</th>
                                    <th scope="col">Cost</th>
                                    <th scope="col">Discount Price</th>
                                </tr>
                            </thead>
                            <tbody data-abo-admin-products-variant-rows>
                                @forelse ($variantRows as $index => $row)
                                    @php($variant = $row['variant'])
                                    <tr data-abo-admin-products-variant-row="{{ $row['key'] }}">
                                        <td>{{ $row['color']->name }} / {{ $row['size']->name }}</td>
                                        <td>
                                            <input type="hidden" name="variants[{{ $index }}][color_id]" value="{{ $row['color']->id }}">
                                            <input type="hidden" name="variants[{{ $index }}][size_id]" value="{{ $row['size']->id }}">
                                            <span class="abo-admin-products__variant-sku">{{ $variant?->sku ?? '—' }}</span>
                                        </td>
                                        <td>
                                            <input type="number"
                                                   name="variants[{{ $index }}][quantity]"
                                                   value="{{ $variant?->quantity ?? 0 }}"
                                                   min="0"
                                                   step="1"
                                                   data-abo-admin-products-variant-quantity>
                                        </td>
                                        <td>
                                            <input type="number"
                                                   name="variants[{{ $index }}][price]"
                                                   value="{{ $variant ? (float) $variant->price : 0 }}"
                                                   min="0"
                                                   step="0.01"
                                                   data-abo-admin-products-variant-price>
                                        </td>
                                        <td>
                                            <input type="number"
                                                   name="variants[{{ $index }}][cost]"
                                                   value="{{ $variant ? (float) $variant->cost : 0 }}"
                                                   min="0"
                                                   step="0.01"
                                                   data-abo-admin-products-variant-cost>
                                        </td>
                                        <td>
                                            <input type="number"
                                                   name="variants[{{ $index }}][discount_price]"
                                                   value="{{ $variant?->discount_price !== null ? (float) $variant->discount_price : '' }}"
                                                   min="0"
                                                   step="0.01"
                                                   placeholder="—"
                                                   data-abo-admin-products-variant-discount>
                                        </td>
                                    </tr>
                                @empty
                                    <tr data-abo-admin-products-variant-empty>
                                        <td colspan="6">Pick the colours and sizes the product comes in.</td>
                                    </tr>
                                @endforelse
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
                        <label class="abo-admin-products__segment @if (! $isArchived) is-active @endif">
                            <input type="radio"
                                   name="is_archived"
                                   value="0"
                                   @checked(! $isArchived)
                                   data-abo-admin-products-status-input>
                            <span>Active</span>
                        </label>
                        <label class="abo-admin-products__segment @if ($isArchived) is-active @endif">
                            <input type="radio"
                                   name="is_archived"
                                   value="1"
                                   @checked($isArchived)
                                   data-abo-admin-products-status-input>
                            <span>Archived</span>
                        </label>
                    </div>

                    <div class="abo-admin-products__field">
                        <label for="abo-admin-products-featured">
                            <input type="checkbox"
                                   id="abo-admin-products-featured"
                                   name="is_featured"
                                   value="1"
                                   @checked($isModel && $product->is_featured)>
                            Show in the new drop on the home page
                        </label>
                    </div>
                </article>

                {{-- 2. Pricing --}}
                <article class="abo-admin-card">
                    <header class="abo-admin-card__head">
                        <h2 class="abo-admin-card__title">PRICING</h2>
                    </header>

                    <p class="abo-admin-products__helper">
                        These three fields fill the rows of the variants table. Every price a
                        customer sees comes from a variant row, not from here.
                    </p>

                    <div class="abo-admin-products__field">
                        <label for="abo-admin-products-price">Default price (EGP)</label>
                        <input id="abo-admin-products-price"
                               type="number"
                               min="0"
                               step="0.01"
                               placeholder="0"
                               inputmode="numeric"
                               data-abo-admin-products-field="price">
                    </div>

                    <div class="abo-admin-products__field">
                        <label for="abo-admin-products-compare">Default discount price (EGP)</label>
                        <input id="abo-admin-products-compare"
                               type="number"
                               min="0"
                               step="0.01"
                               placeholder="0"
                               inputmode="numeric"
                               data-abo-admin-products-field="compare">
                    </div>

                    <div class="abo-admin-products__field">
                        <label for="abo-admin-products-cost">Default cost (EGP)</label>
                        <input id="abo-admin-products-cost"
                               type="number"
                               min="0"
                               step="0.01"
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
                        <label for="abo-admin-products-size-guide">Size chart</label>
                        <p id="abo-admin-products-size-guide" class="abo-admin-products__helper">
                            The size chart follows the category of the product. The chart of
                            <strong>{{ $categoriesList->firstWhere('id', $isModel ? $product->category_id : null)?->name ?? 'the selected category' }}</strong>
                            is the one the store shows on the product page.
                        </p>
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
        <span class="abo-admin-products__toast-text" data-abo-admin-products-toast-text>Saved</span>
    </div>
@endsection

@push('head')
    <link rel="stylesheet" href="{{ asset('admin/css/products.css') }}">
@endpush

@push('scripts')
    <script src="{{ asset('admin/js/products.js') }}" defer></script>
@endpush

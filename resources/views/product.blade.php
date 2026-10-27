@extends('layouts.store')

@section('title', $product->name.' — ABO')
@section('description', $product->description ?: ($product->name.' — '.number_format((float) ($product->min_price ?? $product->starting_price ?? 0), 0, '.', ',').' EGP.'))

{{-- Share card (Open Graph / Twitter). Driven by layouts.store so a
     WhatsApp, Facebook or Instagram link to this product renders a rich
     card with the product photo instead of the generic hero shot.
     `og_image` is wrapped in asset() here because the layout requires an
     absolute URL. `$product->primaryImage` reads the already eager-loaded
     productColors.images relation, so this costs no extra query. --}}
@section('og_type', 'product')
@section('og_title', $product->name)
@section('og_image', $product->primaryImage?->url ?: asset('images/streetwear-hero.jpg'))
@section('og_image_alt', $product->name)
@section('og_extra')
    @php
        // Bare decimal with no thousands separator and 2 fraction digits —
        // the format Facebook's product parser expects. The price shown on
        // the page (1,490 EGP) is a display format, not a wire format.
        $sharePrice = (float) ($product->min_price ?? $product->starting_price ?? 0);
    @endphp
    <meta property="product:price:amount" content="{{ number_format($sharePrice, 2, '.', '') }}">
    <meta property="product:price:currency" content="EGP">
    <meta property="product:availability" content="{{ $product->is_in_stock ? 'in stock' : 'out of stock' }}">
@endsection

@section('announcement')
    <aside class="announcement" aria-label="Store announcement">
        <div class="announcement__inner">
            <span>{{ $announcement ?? 'Free shipping over 1,500 EGP · Drop 02 is live' }}</span>
        </div>
    </aside>
@endsection

@section('content')
    <x-intro.overlay />

    @php
        // Project $product onto an array shape that size-guide.modal
        // expects (it only reads ['slug'] and ['category']). This keeps
        // size-guide independent from the live Product model while we
        // migrate the rest of the storefront.
        $legacyProduct = [
            'slug' => $product->slug,
            'category' => $product->category?->slug,
        ];

        $startingPrice = $product->min_price ?? $product->starting_price;
        $legacyProduct['name'] = $product->name;
        $legacyProduct['price'] = (float) ($startingPrice ?? 0);

        $primaryImage = $product->primaryImage?->url ?: asset('images/streetwear-hero.jpg');
    @endphp

    <section class="product-page" aria-label="{{ $product->name }}"
             data-product-page
             data-product-slug="{{ $product->slug }}"
             data-product-name="{{ $product->name }}"
             data-product-category="{{ $product->category?->slug }}">
        <div class="product-page__inner">
            <div class="product-page__media" data-product-gallery>
                {{-- Always render the first image so the gallery is never empty. --}}
                <img
                    src="{{ $primaryImage }}"
                    alt="{{ $product->name }}"
                    width="900"
                    height="1125"
                    loading="eager"
                    fetchpriority="high"
                    decoding="async"
                    data-product-gallery-main
                >
                @if ($badge = $product->display_badge)
                    <span class="product-page__badge product-page__badge--{{ \Illuminate\Support\Str::slug($badge) }}">
                        {{ strtoupper($badge) }}
                    </span>
                @endif

                {{-- Color thumbnails (one per color that has images). --}}
                @if ($variantsByColor->isNotEmpty())
                    <ul class="product-page__thumbs" aria-label="Color images">
                        @foreach ($variantsByColor as $block)
                            @if ($block['images']->isNotEmpty())
                                <li>
                                    <button type="button"
                                            class="product-page__thumb @if ($loop->first) is-active @endif"
                                            data-color-id="{{ $block['color']->id }}"
                                            data-color-hex="{{ $block['color']->hex_code }}"
                                            aria-label="Show {{ $block['color']->name }}">
                                        <img src="{{ $block['images']->first()->url }}" alt="{{ $product->name }} in {{ $block['color']->name }}" loading="lazy" decoding="async" width="120" height="150">
                                    </button>
                                </li>
                            @endif
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="product-page__body">
                <p class="product-page__crumbs">
                    <a href="{{ route('shop') }}">Shop</a>
                    <span aria-hidden="true">/</span>
                    @if ($product->category)
                        <a href="{{ route('shop') }}?category={{ $product->category->slug }}">{{ ucfirst($product->category->slug) }}</a>
                    @else
                        <span>—</span>
                    @endif
                </p>

                <h1 class="product-page__name">{{ $product->name }}</h1>

                <p class="product-page__price" data-product-price>
                    {{ number_format((float) ($startingPrice ?? 0), 0, '.', ',') }} EGP
                </p>

                @if ($variantsByColor->isNotEmpty())
                    <div class="product-page__group" data-product-colors>
                        <p class="product-page__group-label">COLOR</p>
                        <ul class="product-page__colors" aria-label="Available colors">
                            @foreach ($variantsByColor as $block)
                                @php
                                    $total = $block['sizes']->sum('quantity');
                                    $anyStock = $block['sizes']->contains(fn ($v) => $v->quantity > 0);
                                @endphp
                                <li>
                                    <button type="button"
                                            class="product-page__color @if ($loop->first) is-active @endif"
                                            data-color-id="{{ $block['color']->id }}"
                                            data-color-name="{{ $block['color']->name }}"
                                            data-color-hex="{{ $block['color']->hex_code }}"
                                            @if (!$anyStock) aria-disabled="true" @endif
                                            aria-label="Color {{ $block['color']->name }}"
                                            @if ($loop->first) aria-pressed="true" @else aria-pressed="false" @endif>
                                        <span class="product-page__color-swatch" style="--swatch: {{ $block['color']->hex_code }};"></span>
                                        <span class="product-page__color-name">{{ $block['color']->name }}</span>
                                        @if (!$anyStock)
                                            <span class="product-page__color-oos" aria-label="Out of stock">—</span>
                                        @endif
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    <div class="product-page__group product-page__group--size" data-product-sizes>
                        <div class="product-page__size-row">
                            <p class="product-page__group-label">SIZE</p>
                            <x-size-guide.modal :chart="$sizeChart ?? null" :product="$legacyProduct" />
                        </div>
                        <ul class="product-page__sizes" aria-label="Available sizes">
                            @foreach ($variantsByColor as $block)
                                @foreach ($block['sizes'] as $variant)
                                    <li class="product-page__size @if ($variant->quantity < 1) is-out @endif"
                                        data-color-id="{{ $block['color']->id }}"
                                        data-size-id="{{ $variant->size_id }}"
                                        data-size-name="{{ $variant->size?->name }}"
                                        data-price="{{ (float) $variant->final_price }}"
                                        data-stock="{{ (int) $variant->quantity }}"
                                        @if (!($loop->parent->first && $loop->first)) hidden @endif>
                                        <button type="button"
                                                class="product-page__size-btn @if ($variant->quantity < 1) is-out @endif"
                                                @if ($variant->quantity < 1) disabled aria-disabled="true" @endif
                                                @if ($loop->parent->first && $loop->first) aria-pressed="true" @else aria-pressed="false" @endif>
                                            <span class="product-page__size-name">{{ $variant->size?->name }}</span>
                                            <span class="product-page__size-stock">
                                                @if ($variant->quantity > 0)
                                                    {{ number_format((float) $variant->final_price, 0, '.', ',') }} EGP
                                                @else
                                                    OUT
                                                @endif
                                            </span>
                                        </button>
                                    </li>
                                @endforeach
                            @endforeach
                        </ul>
                        <p class="product-page__size-empty" data-product-size-empty hidden>
                            No sizes in stock for this color yet.
                        </p>
                    </div>

                    {{-- Hidden JSON used by the product.js hydration script. --}}
                    <script type="application/json" data-product-variants>{!! json_encode([
                        'slug' => $product->slug,
                        'colors' => $variantsByColor->map(fn ($block) => [
                            'id' => $block['color']->id,
                            'name' => $block['color']->name,
                            'hex' => $block['color']->hex_code,
                            'image' => $block['images']->first()?->url,
                            'sizes' => $block['sizes']->map(fn ($v) => [
                                'id' => $v->size_id,
                                // The variant row itself — the colour × size
                                // combination the cart API stores. The cart
                                // is keyed by it, so the shopper's choice has
                                // to travel as an id, not as a guess.
                                'variant_id' => $v->id,
                                'name' => $v->size?->name,
                                'price' => (float) $v->final_price,
                                'stock' => (int) $v->quantity,
                            ])->all(),
                        ])->all(),
                    ]) !!}</script>
                @else
                    {{-- Fallback: no variants linked yet — still show the
                         size label so the modal trigger is reachable. --}}
                    <div class="product-page__group product-page__group--size">
                        <div class="product-page__size-row">
                            <p class="product-page__group-label">SIZE</p>
                            <x-size-guide.modal :chart="$sizeChart ?? null" :product="$legacyProduct" />
                        </div>
                        <p class="product-page__size-empty">Sizes &amp; stock for this piece are coming soon.</p>
                    </div>
                @endif

                {{-- The cart is live. The button stays disabled in the
                     markup and product.js enables it once a colour and a
                     size with stock behind it are selected — without JS
                     there is nothing to add with. --}}
                <div class="product-page__group">
                    <button type="button" class="store-btn store-btn--primary" disabled aria-disabled="true" data-product-atc>
                        <span data-product-atc-label>ADD TO CART</span>
                    </button>
                    <p class="product-page__hint">Shipping is calculated at checkout.</p>
                </div>

                {{-- The cart's toast host, so a successful add or a
                     refused one is reported in the storefront's own style. --}}
                <div
                    class="cart__toasts"
                    data-cart-toasts
                    role="region"
                    aria-label="Cart notifications"
                ></div>
            </div>
        </div>

        {{-- Related products — same category. --}}
        @if ($relatedProducts->isNotEmpty())
            <section class="related" aria-label="More from this category">
                <header class="section-head">
                    <p class="section-head__eyebrow">MORE FROM {{ strtoupper($product->category?->name ?? 'ABO') }}</p>
                    <h2 class="section-head__title">Keep exploring.</h2>
                </header>
                <ul class="related__grid">
                    @foreach ($relatedProducts as $related)
                        @php
                            $relatedImage = $related->primaryImage?->url ?: asset('images/streetwear-hero.jpg');
                            $relatedPrice = $related->min_price ?? $related->starting_price;
                        @endphp
                        <li class="related__item">
                            <a href="{{ route('shop.show', ['slug' => $related->slug]) }}" class="related__link" aria-label="{{ $related->name }}">
                                <img src="{{ $relatedImage }}" alt="{{ $related->name }}" loading="lazy" decoding="async" width="600" height="750">
                                <div class="related__body">
                                    <h3 class="related__name">{{ $related->name }}</h3>
                                    <p class="related__price">{{ number_format((float) ($relatedPrice ?? 0), 0, '.', ',') }} EGP</p>
                                    @if ($related->colors->isNotEmpty())
                                        <ul class="related__colors" aria-label="Available colors">
                                            @foreach ($related->colors->take(5) as $color)
                                                <li class="related__color" style="--swatch: {{ $color->hex_code }};" aria-label="{{ $color->name }}"></li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </section>
@endsection

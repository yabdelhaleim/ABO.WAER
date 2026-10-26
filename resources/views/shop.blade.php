@extends('layouts.store')

@section('title', 'Shop — '.$shop['title_line_1'].' '.$shop['title_line_2'])
@section('description', 'Browse the full ABO collection. Hoodies, tees, pants, jackets — built for the streets.')

@section('announcement')
    <aside class="announcement" aria-label="Store announcement">
        <div class="announcement__inner">
            <span>{{ $announcement ?? 'Free shipping over 1,500 EGP · Drop 02 is live' }}</span>
        </div>
    </aside>
@endsection

@section('content')
    <x-intro.overlay />

    {{-- ─── 1. Compact hero ──────────────────────────────────────── --}}
    <section class="shop-hero" aria-label="Shop — ABO collection">
        <div class="shop-hero__inner">
            <p class="shop-hero__eyebrow">
                <span class="shop-hero__eyebrow-line" aria-hidden="true"></span>
                <span>SHOP THE COLLECTION</span>
            </p>

            <h1 class="shop-hero__headline">
                <span class="shop-hero__headline-line">{{ $shop['title_line_1'] }}</span>
                <span class="shop-hero__headline-line shop-hero__headline-line--accent">{{ $shop['title_line_2'] }}</span>
            </h1>

            <p class="shop-hero__lede">{{ $shop['lede'] }}</p>

            <a href="#grid" class="store-btn store-btn--primary shop-hero__cta">
                <span>EXPLORE COLLECTION</span>
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M5 12h12M13 6l6 6-6 6" fill="none" stroke="currentColor"
                          stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </a>
        </div>
    </section>

    {{-- ─── 2. Category strip ────────────────────────────────────── --}}
    @php
        $perCategoryCount = $products->getCollection()
            ->groupBy(fn ($product) => $product->category?->slug)
            ->map->count();
    @endphp
    <section class="shop-categories" id="categories" aria-label="Shop by category" data-home-stagger>
        <div class="shop-categories__grid">
            @forelse ($categories as $cat)
                @php $count = $perCategoryCount[$cat->slug] ?? 0; @endphp
                <a class="shop-cat"
                   href="{{ route('shop') }}?category={{ $cat->slug }}#grid"
                   data-category="{{ $cat->slug }}"
                   data-home-reveal>
                    <div class="shop-cat__media">
                        <img
                            src="{{ asset($cat->image ?: 'images/streetwear-hero.jpg') }}"
                            alt="{{ $cat->name }}"
                            width="600"
                            height="450"
                            loading="lazy"
                            decoding="async"
                        >
                        <span class="shop-cat__watermark" aria-hidden="true">ABO</span>
                    </div>
                    <div class="shop-cat__foot">
                        <span class="shop-cat__name">{{ strtoupper($cat->name) }}</span>
                        <span class="shop-cat__count" aria-label="{{ $count }} item{{ $count === 1 ? '' : 's' }}">
                            {{ $count }} {{ $count === 1 ? 'ITEM' : 'ITEMS' }}
                        </span>
                    </div>
                </a>
            @empty
                <p class="shop-categories__empty">Categories are coming soon.</p>
            @endforelse
        </div>
    </section>

    {{-- ─── 3. Sticky filter bar ─────────────────────────────────── --}}
    @php
        $hasMultiplePrices = (float) ($minPricePoint ?? 0) !== (float) ($maxPricePoint ?? 0);
        $hasSizes = $sizes->count() > 0;
    @endphp
    <div class="shop-filters" id="filters" data-shop-filters>
        <div class="shop-filters__inner">
            <div class="shop-filters__chips" role="toolbar" aria-label="Product filters">
                <button type="button"
                        class="shop-filters__chip is-active"
                        data-filter="all"
                        aria-pressed="true">All items</button>
                <button type="button"
                        class="shop-filters__chip"
                        data-filter="category"
                        aria-haspopup="listbox"
                        aria-expanded="false"
                        aria-pressed="false">
                    <span>Category</span>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
                <button type="button"
                        class="shop-filters__chip"
                        data-filter="color"
                        aria-haspopup="listbox"
                        aria-expanded="false"
                        aria-pressed="false">
                    <span>Color</span>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
                @if ($hasSizes)
                    <button type="button"
                            class="shop-filters__chip"
                            data-filter="size"
                            aria-haspopup="listbox"
                            aria-expanded="false"
                            aria-pressed="false">
                        <span>Size</span>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                @endif
                @if ($hasMultiplePrices)
                    <button type="button"
                            class="shop-filters__chip"
                            data-filter="price"
                            aria-haspopup="listbox"
                            aria-expanded="false"
                            aria-pressed="false">
                        <span>Price</span>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                @endif
                <button type="button"
                        class="shop-filters__chip"
                        data-filter="sort"
                        aria-haspopup="listbox"
                        aria-expanded="false"
                        aria-pressed="false">
                    <span>Sort by</span>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
            </div>

            <p class="shop-filters__count" data-shop-count aria-live="polite">
                <span data-shop-count-num>{{ $products->count() }}</span> result{{ $products->count() === 1 ? '' : 's' }}
            </p>
        </div>

        {{-- Pre-rendered filter options so shop.js stays in sync with the
             server-side state and the user sees a label for every chip.
             Keep the markup flat for the JS pickers below. --}}
        <datalist id="shop-category-options" hidden>
            @foreach ($categories as $cat)
                <option data-slug="{{ $cat->slug }}" value="{{ $cat->slug }}">{{ $cat->name }}</option>
            @endforeach
        </datalist>

        {{-- Hidden form so the GET query string can be replayed when a
             client-side filter chip is changed. --}}
        <form id="shop-filter-form" method="GET" action="{{ route('shop') }}" data-shop-filter-form hidden>
            <input type="hidden" name="category" value="{{ request('category') }}">
            <input type="hidden" name="color" value="{{ request('color') }}">
            <input type="hidden" name="size" value="{{ request('size') }}">
            <input type="hidden" name="price_min" value="{{ request('price_min') }}">
            <input type="hidden" name="price_max" value="{{ request('price_max') }}">
            <input type="hidden" name="sort" value="{{ request('sort') }}">
        </form>

        {{-- Active filter tags --}}
        <div class="shop-filters__tags" data-shop-tags @if (!request()->except('page')) hidden @endif>
            @if (request('category') && $activeCategory)
                <button type="button" class="shop-filters__tag" data-remove="category">
                    <span>{{ $activeCategory->name }}</span><span aria-hidden="true">×</span>
                </button>
            @endif
            @if (request('color'))
                <button type="button" class="shop-filters__tag" data-remove="color">
                    <span>Color #{{ ltrim((string) request('color'), '#') }}</span><span aria-hidden="true">×</span>
                </button>
            @endif
            @if (request('size'))
                <button type="button" class="shop-filters__tag" data-remove="size">
                    <span>Size {{ request('size') }}</span><span aria-hidden="true">×</span>
                </button>
            @endif
            @if (request('price_min') || request('price_max'))
                <button type="button" class="shop-filters__tag" data-remove="price">
                    <span>{{ request('price_min', '0') }} – {{ request('price_max', '∞') }} EGP</span><span aria-hidden="true">×</span>
                </button>
            @endif
            @if (request('sort') && request('sort') !== 'newest')
                <button type="button" class="shop-filters__tag" data-remove="sort">
                    <span>{{ ucfirst(str_replace('-', ' ', (string) request('sort'))) }}</span><span aria-hidden="true">×</span>
                </button>
            @endif
            <button type="button" class="shop-filters__clear" data-shop-clear>Clear all</button>
        </div>

        {{-- Dropdown panel (desktop) --}}
        <div class="shop-filters__panel" data-shop-panel hidden>
            <div class="shop-filters__panel-group" data-panel="category" hidden>
                <p class="shop-filters__panel-title">Filter by category</p>
                <ul role="listbox" aria-label="Categories">
                    <li><button type="button" role="option" data-category-clear>All categories</button></li>
                    @foreach ($categories as $cat)
                        <li><button type="button" role="option" data-category-value="{{ $cat->slug }}">{{ $cat->name }}</button></li>
                    @endforeach
                </ul>
            </div>
            <div class="shop-filters__panel-group" data-panel="color" hidden>
                <p class="shop-filters__panel-title">Filter by color</p>
                <ul role="listbox" aria-label="Colors">
                    <li><button type="button" role="option" data-color-clear>Any color</button></li>
                    @foreach ($colors as $color)
                        <li><button type="button" role="option" data-color-value="{{ $color->id }}"><span class="shop-filters__swatch" style="--swatch: {{ $color->hex_code }};"></span> {{ $color->name }}</button></li>
                    @endforeach
                </ul>
            </div>
            @if ($hasSizes)
                <div class="shop-filters__panel-group" data-panel="size" hidden>
                    <p class="shop-filters__panel-title">Filter by size</p>
                    <ul role="listbox" aria-label="Sizes">
                        <li><button type="button" role="option" data-size-clear>Any size</button></li>
                        @foreach ($sizes as $size)
                            <li><button type="button" role="option" data-size-value="{{ $size->id }}">{{ $size->name }}</button></li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @if ($hasMultiplePrices && ! empty($priceBuckets))
                <div class="shop-filters__panel-group" data-panel="price" hidden>
                    <p class="shop-filters__panel-title">Filter by price</p>
                    <ul role="listbox" aria-label="Price range">
                        <li><button type="button" role="option" data-price-clear>Any price</button></li>
                        @foreach ($priceBuckets as $bucket)
                            <li>
                                <button type="button"
                                        role="option"
                                        data-price-value="{{ $bucket['id'] }}"
                                        data-price-min="{{ (float) $bucket['min'] }}"
                                        data-price-max="{{ (float) $bucket['max'] }}">
                                    @if ($bucket['id'] === 'low')
                                        Under {{ number_format((float) $bucket['max'], 0, '.', ',') }} EGP
                                    @elseif ($bucket['id'] === 'mid')
                                        {{ number_format((float) $bucket['min'], 0, '.', ',') }} – {{ number_format((float) $bucket['max'], 0, '.', ',') }} EGP
                                    @else
                                        Over {{ number_format((float) $bucket['min'], 0, '.', ',') }} EGP
                                    @endif
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
            <div class="shop-filters__panel-group" data-panel="sort" hidden>
                <p class="shop-filters__panel-title">Sort by</p>
                <ul role="listbox" aria-label="Sort options">
                    <li><button type="button" role="option" data-sort-value="newest">Newest</button></li>
                    <li><button type="button" role="option" data-sort-value="price-asc">Price: low to high</button></li>
                    <li><button type="button" role="option" data-sort-value="price-desc">Price: high to low</button></li>
                </ul>
            </div>
        </div>
    </div>

    {{-- ─── 4. Product grid + 5. Brand banner + 6. Empty state ─────── --}}
    <section class="shop-grid-section" id="grid" aria-label="Products">
        @if ($products->isEmpty())
            <div class="shop-empty" data-shop-empty>
                <p class="shop-empty__line">No pieces match those filters.</p>
                <a href="{{ route('shop') }}" class="store-btn store-btn--ghost" data-shop-reset>
                    <span>RESET FILTERS</span>
                </a>
            </div>
        @else
            <ul class="shop-grid" data-shop-grid>
                @foreach ($products as $i => $product)
                    @php
                        $imagePath = $product->primaryImage?->path ?: 'images/streetwear-hero.jpg';
                        $price = $product->min_price ?? $product->starting_price;
                        $categorySlug = $product->category?->slug;
                        $badge = $product->display_badge;
                        // The colour and size filters match on the ids the
                        // card actually offers, so the browser and the
                        // server filter on the same set.
                        $cardColors = $product->colors->pluck('id')->filter()->join(',');
                        $cardSizes = $product->variants->pluck('size_id')->filter()->unique()->join(',');
                    @endphp
                    <li class="shop-grid__item"
                        data-product
                        data-name="{{ strtolower($product->name) }}"
                        data-category="{{ $categorySlug }}"
                        data-price="{{ (float) ($price ?? 0) }}"
                        data-colors="{{ $cardColors }}"
                        data-sizes="{{ $cardSizes }}"
                        data-index="{{ ($products->currentPage() - 1) * $products->perPage() + $i }}"
                        data-home-reveal>
                        <a class="shop-card" href="{{ route('shop.show', ['slug' => $product->slug]) }}" aria-label="{{ $product->name }}">
                            <div class="shop-card__media">
                                <img
                                    src="{{ asset($imagePath) }}"
                                    alt="{{ $product->name }}"
                                    width="600"
                                    height="750"
                                    loading="lazy"
                                    decoding="async"
                                >
                                @if (!empty($badge))
                                    <span class="shop-card__badge shop-card__badge--{{ \Illuminate\Support\Str::slug($badge) }}">
                                        {{ strtoupper($badge) }}
                                    </span>
                                @endif
                            </div>
                            <div class="shop-card__body">
                                <h3 class="shop-card__name">{{ $product->name }}</h3>
                                <p class="shop-card__price">{{ number_format((float) ($price ?? 0), 0, '.', ',') }} EGP</p>
                                @if ($product->colors->isNotEmpty())
                                    <ul class="shop-card__colors" aria-label="Available colors">
                                        @foreach ($product->colors as $color)
                                            <li class="shop-card__color" style="--swatch: {{ $color->hex_code }};" aria-label="{{ $color->name }}" title="{{ $color->name }}"></li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        </a>
                    </li>
                @endforeach
            </ul>

            @if ($products->hasMorePages())
                {{-- Server-side pagination: shop.js keeps the query string
                     intact by calling withQueryString() on each page. --}}
                <div class="shop-loadmore" data-shop-loadmore-wrap>
                    <a href="{{ $products->nextPageUrl() }}" class="store-btn store-btn--ghost" data-shop-loadmore rel="next">
                        <span>LOAD MORE</span>
                    </a>
                </div>
            @endif

            @if ($products->count() > 8)
                <aside class="shop-mid-banner" aria-label="Brand line">
                    <span class="shop-mid-banner__text">NOT FOR EVERYONE.</span>
                </aside>
            @endif
        @endif
    </section>
@endsection

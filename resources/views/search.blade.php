{{--
    Search results.

    The page is server-rendered: the term travels in the URL, the products
    and the categories come straight from the database, and the same URL can
    be shared or bookmarked. There is no client-side search and no static
    result list — an empty result set is an empty result set.
--}}
@extends('layouts.store')

@section('title', $term !== '' ? 'Search — '.$term : 'Search')
@section('description', 'Search the ABO collection by name, colour, size or category.')

@section('content')
    <x-intro.overlay />

    <section class="search" aria-labelledby="search-title">
        <header class="search__head">
            <p class="search__eyebrow">
                <span class="search__eyebrow-line" aria-hidden="true"></span>
                <span>SEARCH</span>
            </p>

            <h1 class="search__title" id="search-title">
                @if ($term !== '')
                    <span>RESULTS FOR</span>
                    <span class="search__title-accent" dir="auto">“{{ $term }}”</span>
                @else
                    <span>FIND YOUR</span>
                    <span class="search__title-accent">PIECE.</span>
                @endif
            </h1>
        </header>

        {{-- A plain GET form: the page works without JavaScript, the
             browser can restore the term, and the query stays in the URL. --}}
        <form class="search__form" method="GET" action="{{ route('search') }}" role="search">
            <label class="sr-only" for="search-term">Search the collection</label>
            <input
                class="search__input"
                id="search-term"
                type="search"
                name="q"
                value="{{ $term }}"
                placeholder="Hoodie, cargo, tee…"
                autocomplete="off"
                enterkeyhint="search"
            >
            <button type="submit" class="store-btn store-btn--primary">
                <span>SEARCH</span>
            </button>
        </form>

        @if ($term !== '')
            @if ($categories->isNotEmpty())
                {{-- The card answers in the language of the term: the
                     storefront is English by default, but an Arabic
                     search deserves an Arabic result. --}}
                <section class="search__categories" aria-label="Matching categories">
                    <h2 class="search__section-title">CATEGORIES</h2>
                    <ul class="search__category-list">
                        @foreach ($categories as $category)
                            <li>
                                <a class="search__category" href="{{ route('shop', ['category' => $category->slug]) }}">
                                    {{ ($isArabicTerm && filled($category->name_ar)) ? $category->name_ar : $category->name }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            <section class="shop-grid-section" id="grid" aria-label="Matching products">
                @if ($products->isEmpty())
                    <div class="shop-empty">
                        <p class="shop-empty__line">
                            Nothing matches “{{ $term }}”. Try a shorter word or browse the collection.
                        </p>
                        <a href="{{ route('shop') }}" class="store-btn store-btn--ghost">
                            <span>EXPLORE COLLECTION</span>
                        </a>
                    </div>
                @else
                    <p class="search__count">
                        {{ $products->total() }} {{ \Illuminate\Support\Str::plural('piece', $products->total()) }} found.
                    </p>

                    <ul class="shop-grid">
                        @foreach ($products as $product)
                            <li class="shop-grid__item" data-product>
                                <a class="shop-card" href="{{ route('shop.show', ['slug' => $product->slug]) }}" aria-label="{{ $product->name }}">
                                    <div class="shop-card__media">
                                        <img
                                            src="{{ $product->primaryImage?->url ?: asset('images/streetwear-hero.jpg') }}"
                                            alt="{{ $product->name }}"
                                            width="600"
                                            height="750"
                                            loading="lazy"
                                            decoding="async"
                                        >
                                        @if (!empty($product->display_badge))
                                            <span class="shop-card__badge shop-card__badge--{{ \Illuminate\Support\Str::slug($product->display_badge) }}">
                                                {{ strtoupper($product->display_badge) }}
                                            </span>
                                        @endif
                                    </div>
                                    <div class="shop-card__body">
                                        <h3 class="shop-card__name">
                                            {{ ($isArabicTerm && filled($product->name_ar)) ? $product->name_ar : $product->name }}
                                        </h3>
                                        <p class="shop-card__price">
                                            {{ number_format((float) ($product->min_price ?? $product->starting_price ?? 0), 0, '.', ',') }} EGP
                                        </p>
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
                        <div class="shop-loadmore">
                            <a href="{{ $products->nextPageUrl() }}" class="store-btn store-btn--ghost" rel="next">
                                <span>LOAD MORE</span>
                            </a>
                        </div>
                    @endif
                @endif
            </section>
        @endif
    </section>
@endsection

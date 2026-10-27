@extends('layouts.store')

@section('title', 'ABO — Modern fit, urban vibe.')
@section('description', 'ABO — Premium men\'s streetwear. Modern fit, urban vibe, built for the next generation. Style, comfort, attitude.')

@section('announcement')
    {{-- ─── 1. Announcement bar (lime, black uppercase) ──────────── --}}
    <aside class="announcement" aria-label="Store announcement">
        <div class="announcement__inner">
            <span>{{ $announcement }}</span>
        </div>
    </aside>
@endsection

@section('content')
    <x-intro.overlay />

    {{-- ─── 3. Hero (min-height 88vh, photo + masked gradient) ──── --}}
    {{-- Loop on $banners when the admin has published any; otherwise fall back to
         the static $hero copy so the page renders intact before the first banner
         is added. Single-slide hero is rendered for the first banner to keep the
         existing single-hero layout — a future JS carousel can promote the loop. --}}
    @if ($banners->isNotEmpty())
        @foreach ($banners as $banner)
            @if ($loop->first)
                <section class="hero" data-home-hero data-home-banner aria-label="ABO hero — Banner">
                    <div class="hero__photo-wrap" data-home-photo-wrap>
                        <img
                            class="hero__photo"
                            data-home-photo
                            src="{{ $banner->image_url }}"
                            alt="{{ $banner->title_ar ?? $banner->title ?? '' }}"
                            width="800"
                            height="450"
                            fetchpriority="high"
                            loading="eager"
                            decoding="async"
                        >
                        {{-- Lime light sweep — thin line travels L→R across the hero once. --}}
                        <span class="hero__sweep" data-home-sweep aria-hidden="true"></span>
                    </div>

                    <div class="hero__inner">
                        <p class="hero__eyebrow" data-home-eyebrow>
                            <span class="hero__eyebrow-line" data-home-eyebrow-line aria-hidden="true"></span>
                            <span class="hero__eyebrow-text">{{ $banner->subtitle_ar ?? $banner->subtitle }}</span>
                        </p>

                        <h1 class="hero__headline">
                            <span class="hero__h1-wrap" data-home-h1-line>
                                <span class="hero__h1-inner">{{ $banner->title_ar ?? $banner->title }}</span>
                            </span>
                        </h1>

                        <div class="hero__ctas">
                            <a href="{{ $banner->button_url ?: route('shop') }}" class="store-btn store-btn--primary" data-home-cta>
                                <span>{{ strtoupper($banner->button_text_ar ?? $banner->button_text ?? 'تسوق الآن') }} →</span>
                            </a>
                        </div>
                    </div>
                </section>
            @endif
        @endforeach
    @else
        <section class="hero" data-home-hero aria-label="ABO hero — Drop 02">
            <div class="hero__photo-wrap" data-home-photo-wrap>
                <img
                    class="hero__photo"
                    data-home-photo
                    src="{{ asset($hero['photo']['src']) }}"
                    alt="{{ $hero['photo']['alt'] }}"
                    width="{{ $hero['photo']['width'] }}"
                    height="{{ $hero['photo']['height'] }}"
                    fetchpriority="high"
                    loading="eager"
                    decoding="async"
                >
                {{-- Lime light sweep — thin line travels L→R across the hero once. --}}
                <span class="hero__sweep" data-home-sweep aria-hidden="true"></span>
            </div>

            <div class="hero__inner">
                <p class="hero__eyebrow" data-home-eyebrow>
                    <span class="hero__eyebrow-line" data-home-eyebrow-line aria-hidden="true"></span>
                    <span class="hero__eyebrow-text">{{ $hero['eyebrow'] }}</span>
                </p>

                <h1 class="hero__headline">
                    <span class="hero__h1-wrap" data-home-h1-line>
                        <span class="hero__h1-inner">{{ $hero['line_1'] }}</span>
                    </span>
                    <span class="hero__h1-wrap hero__h1-wrap--accent" data-home-h1-line>
                        <span class="hero__h1-inner">{{ $hero['line_2'] }}</span>
                    </span>
                </h1>

                <p class="hero__paragraph" data-home-paragraph>{{ $hero['lede'] }}</p>

                <div class="hero__ctas">
                    <a href="{{ $hero['primary_cta']['href'] }}" class="store-btn store-btn--primary" data-home-cta>
                        <span>{{ strtoupper($hero['primary_cta']['label']) }} →</span>
                    </a>
                    <a href="{{ $hero['secondary_cta']['href'] }}" class="store-btn store-btn--ghost" data-home-cta>
                        <span>{{ strtoupper($hero['secondary_cta']['label']) }}</span>
                    </a>
                </div>

                <ul class="hero__stats">
                    @foreach ($hero['stats'] as $i => $stat)
                        <li class="hero__stat" data-home-stat>
                            <span class="hero__stat-value" data-home-stat-value>{{ $stat['value'] }}{{ $stat['suffix'] }}</span>
                            <span class="hero__stat-label">{{ strtoupper($stat['label']) }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>
    @endif

    {{-- ─── 4. Ticker (lime band, marquee, pauses on hover) ──────── --}}
    <section class="ticker" aria-label="Brand ticker" data-home-reveal>
        <div class="ticker__track" aria-hidden="true">
            @for ($i = 0; $i < 4; $i++)
                <span class="ticker__item">{{ $ticker }}</span>
            @endfor
        </div>
        <span class="sr-only">{{ $ticker }}</span>
    </section>

    {{-- ─── 5. Shop by category (4 cards, 3:4 ratio) ────────────── --}}
    <section class="categories" id="categories" aria-label="Shop by category" data-home-stagger>
        <header class="section-head" data-home-reveal>
            <p class="section-head__eyebrow">SHOP BY CATEGORY</p>
            <h2 class="section-head__title">Built for every layer.</h2>
        </header>
        <div class="categories__grid">
            @forelse ($categories as $category)
                <a class="cat-card" href="{{ route('shop', ['category' => $category->slug]) }}" data-home-reveal>
                    <div class="cat-card__media">
                        <img
                            src="{{ $category->image_url ?: asset('store/images/product-placeholder.jpg') }}"
                            alt="{{ $category->name_ar ?? $category->name }}"
                            width="600"
                            height="800"
                            loading="lazy"
                            decoding="async"
                        >
                        <span class="cat-card__watermark" aria-hidden="true">ABO</span>
                    </div>
                    <div class="cat-card__foot">
                        <span class="cat-card__name">{{ strtoupper($category->name_ar ?? $category->name) }}</span>
                        <span class="cat-card__underline" aria-hidden="true"></span>
                    </div>
                </a>
            @empty
                {{-- No category is published for the homepage yet. The
                     page says so instead of showing editorial cards that
                     do not exist in the catalog. --}}
                <p class="categories__empty">More categories are coming soon.</p>
            @endforelse
        </div>
    </section>

    {{-- ─── 6. New drop (4 product cards) ──────────────────────── --}}
    <section class="newdrop" id="new" aria-label="New drop — Drop 02" data-home-stagger>
        <header class="section-head" data-home-reveal>
            <p class="section-head__eyebrow">NEW DROP</p>
            <h2 class="section-head__title">Drop 02 — just landed.</h2>
        </header>
        <div class="newdrop__grid">
            @forelse ($featuredProducts as $product)
                @php($badgeText = $product->badge ?: ($product->is_featured ? 'NEW' : null))
                <article class="product" data-home-reveal>
                    <a class="product__media" href="{{ route('shop.show', $product->slug) }}" aria-label="{{ $product->name_ar ?? $product->name }}">
                        <img
                            src="{{ $product->images->first()?->thumb_url ?: asset('store/images/product-placeholder.jpg') }}"
                            alt="{{ $product->name_ar ?? $product->name }}"
                            width="600"
                            height="750"
                            loading="lazy"
                            decoding="async"
                        >
                        @if (!empty($badgeText))
                            <span class="product__badge product__badge--{{ \Illuminate\Support\Str::slug($badgeText) }}">
                                {{ strtoupper($badgeText) }}
                            </span>
                        @endif
                    </a>
                    <div class="product__body">
                        <h3 class="product__name">{{ $product->name_ar ?? $product->name }}</h3>
                        <p class="product__price">{{ number_format($product->starting_price ?? 0, 0, '.', ',') }} EGP</p>
                        <ul class="product__colors" aria-label="Available colors">
                            @forelse ($product->colors as $color)
                                <li class="product__color" style="--swatch: {{ $color->hex_code }};" aria-label="Color {{ $color->hex_code }}"></li>
                            @empty
                                @foreach ($product->productColors as $pc)
                                    @if ($pc->color)
                                        <li class="product__color" style="--swatch: {{ $pc->color->hex_code }};" aria-label="Color {{ $pc->color->hex_code }}"></li>
                                    @endif
                                @endforeach
                            @endforelse
                        </ul>
                    </div>
                </article>
            @empty
                {{-- No featured product is published yet. The page says so
                     instead of showing editorial cards for products that are
                     not in the catalog. --}}
                <p class="newdrop__empty">The next drop is being packed. Check back soon.</p>
            @endforelse
        </div>
    </section>

    {{-- ─── 7. Brand statement banner ──────────────────────────── --}}
    <section class="banner" aria-label="Brand statement" data-home-reveal>
        <span class="banner__line banner__line--top" aria-hidden="true"></span>
        <span class="banner__line banner__line--bottom" aria-hidden="true"></span>
        <div class="banner__inner">
            <p class="banner__eyebrow">{{ $banner['eyebrow'] }}</p>
            <h2 class="banner__headline">
                <span class="banner__line-1">{{ strtoupper($banner['line_1']) }}</span>
                <span class="banner__line-2">{{ strtoupper($banner['line_2']) }}</span>
            </h2>
            <p class="banner__subline">{{ strtoupper($banner['subline']) }}</p>
        </div>
    </section>

    {{-- ─── 8. Why ABO (bordered grid, 4 cells) ────────────────── --}}
    <section class="why" aria-label="Why ABO" data-home-stagger>
        <header class="section-head" data-home-reveal>
            <p class="section-head__eyebrow">WHY ABO</p>
            <h2 class="section-head__title">Built like nothing else.</h2>
        </header>
        <ul class="why__grid">
            @foreach ($why as $cell)
                <li class="why__cell" data-home-reveal>
                    <span class="why__num">{{ $cell['n'] }}</span>
                    <h3 class="why__title">{{ $cell['title'] }}</h3>
                    <p class="why__body">{{ $cell['body'] }}</p>
                </li>
            @endforeach
        </ul>
    </section>

    {{-- ─── 9. Newsletter ──────────────────────────────────────── --}}
    <section class="newsletter" aria-label="Newsletter" data-home-reveal>
        <div class="newsletter__panel">
            <p class="section-head__eyebrow">{{ $newsletter['eyebrow'] }}</p>
            <h2 class="newsletter__headline">{{ $newsletter['headline'] }}</h2>
            <p class="newsletter__subline">{{ $newsletter['subline'] }}</p>

            <form class="newsletter__form" data-home-newsletter novalidate>
                <label class="sr-only" for="newsletter-email">Email address</label>
                <input
                    id="newsletter-email"
                    name="email"
                    type="email"
                    inputmode="email"
                    autocomplete="email"
                    placeholder="{{ $newsletter['placeholder'] }}"
                    required
                >
                <button type="submit" class="store-btn store-btn--primary">
                    <span>{{ strtoupper($newsletter['cta']) }}</span>
                </button>
                <span class="newsletter__success" data-home-newsletter-success hidden>{{ $newsletter['success'] }}</span>
            </form>
            <p class="newsletter__hint" role="status" aria-live="polite"></p>
        </div>
    </section>

    {{-- ─── 10. Footer (rendered by partial) ───────────────────── --}}
    {{-- @include('store.partials.footer') is in the layout, after main. --}}
@endsection

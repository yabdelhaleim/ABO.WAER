{{--
    Content pages — DB-driven.

    Renders one of the 5 content pages (Shipping, Returns, Exchange,
    About, Contact) from the `pages` table. The active record is
    resolved by App\Http\Controllers\Store\PageController from the
    route's slug (about / contact / shipping / returns / exchange).
    Inactive slugs are never reached — the controller throws
    NotFoundHttpException before this view renders.

    The page is rendered entirely from the record passed by the
    controller. There is no client-side renderer any more: the former
    resources/js/content.js used to wipe this markup and re-draw the
    page from a static mock, which meant the admin's saved copy never
    reached the customer. The database is now the single source of
    truth and this view is the only place that turns a Page record
    into storefront markup.

    Language: the storefront is an English-only site (`layouts/store`
    declares lang="en" dir="ltr"), so the English column is the
    primary one and the Arabic column is the fallback for pages whose
    English copy has not been written yet. The chosen variant also
    drives the `lang` / `dir` of the <article>, so an Arabic fallback
    still reads right-to-left.

    `content` / `content_ar` are admin-edited HTML. They are echoed
    unescaped via {!! … !!} because only authenticated admins can
    write them through the protected admin.pages.update route — see
    the note in Admin\PageRequest about the trust boundary.
--}}

@php
    // Body copy — the storefront is an English-only site
    // (`layouts/store` declares lang="en" dir="ltr"), so the English
    // column is the primary one and the Arabic column is the fallback
    // for pages whose English copy has not been written yet.
    //
    // The page is served in the language of its body: when only the
    // Arabic copy exists the page reads right-to-left and carries the
    // Arabic title, so the heading and the body never disagree.
    $pageBody = $page->content ?? $page->content_ar;
    $isRtl = $page->content === null && $page->content_ar !== null;

    // Header copy — the title column is required, so the English title
    // is always there; the Arabic title is used when the page is being
    // served in Arabic. The brand suffix is appended by
    // resources/views/layouts/store.blade.php via @yield('title').
    $pageTitle = $isRtl
        ? ($page->title_ar ?? $page->title)
        : ($page->title ?? $page->title_ar);

    // The three policy pages share a tab strip. The list mirrors the
    // named routes registered in routes/web.php.
    $policyTabs = [
        'shipping' => 'Shipping',
        'returns' => 'Returns',
        'exchange' => 'Exchange',
    ];
    $isPolicy = array_key_exists($page->slug, $policyTabs);
@endphp

@extends('layouts.store')

@section('title', $pageTitle)
@section('description', $pageTitle)

@section('content')
    <article class="content{{ $isRtl ? ' content--rtl' : '' }}"
             data-page-slug="{{ $page->slug }}"
             lang="{{ $isRtl ? 'ar' : 'en' }}"
             dir="{{ $isRtl ? 'rtl' : 'ltr' }}">

        <header class="content__head">
            <p class="content__eyebrow">
                <span class="content__eyebrow-line" aria-hidden="true"></span>
                <span>{{ $isPolicy ? 'HELP CENTER' : 'ABOUT ABO' }}</span>
            </p>

            <h1 class="content__title">{{ $pageTitle }}</h1>

            @if ($page->updated_at)
                <p class="content__updated">LAST UPDATED: {{ $page->updated_at->format('Y-m-d') }}</p>
            @endif
        </header>

        @if ($isPolicy)
            <nav class="content__tabs" aria-label="Policies">
                @foreach ($policyTabs as $tabSlug => $tabLabel)
                    <a href="{{ route($tabSlug) }}"
                       class="content__tab{{ $tabSlug === $page->slug ? ' is-active' : '' }}"
                       @if ($tabSlug === $page->slug) aria-current="page" @endif>{{ $tabLabel }}</a>
                @endforeach
            </nav>
        @endif

        <div class="content__main">
            @if ($pageBody)
                {{-- Page body. content / content_ar are admin-edited HTML
                     from the protected admin pages editor, so echoing them
                     unescaped is intentional. --}}
                <div class="content__prose">{!! $pageBody !!}</div>
            @else
                <p class="content__empty">This page has no content yet.</p>
            @endif
        </div>
    </article>
@endsection

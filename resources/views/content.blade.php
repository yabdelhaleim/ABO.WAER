{{--
    Content pages — DB-driven.

    Renders one of the 5 content pages (Shipping, Returns, Exchange,
    About, Contact) from the `pages` table. The active record is
    resolved by App\Http\Controllers\Store\PageController from the
    route's slug (defaults: about / contact / shipping / returns /
    exchange). Inactive slugs are never reached — the controller
    throws NotFoundHttpException before this view renders.

    The page is rendered entirely from the record passed by the
    controller (title_ar with a title fallback, content_ar with a
    content fallback). `content` / `content_ar` are admin-edited
    HTML; they are echoed unescaped via {!! … !!} because only
    authenticated admins can write them through the protected
    admin.pages.update route.
--}}

@php
    // Header copy — prefer the Arabic translation when present so
    // the storefront matches the customer's locale, otherwise fall
    // back to the English title. The brand suffix is appended by
    // resources/views/layouts/store.blade.php via @yield('title').
    $pageTitle = $page->title_ar ?? $page->title;

    // Body copy — same Arabic-first fallback. Used both for the
    // real article and for the <noscript> fallback below.
    $pageBody = $page->content_ar ?? $page->content;
@endphp

@extends('layouts.store')

@section('title', $pageTitle)
@section('description', $pageTitle)

@section('content')
    <div class="content__shell" data-page-slug="{{ $page->slug }}">
        <article class="content">
            <h1 class="content__title">{{ $pageTitle }}</h1>

            {{-- Page body. content_ar / content are admin-edited HTML
                 from the protected admin pages editor, so echoing
                 them unescaped is intentional. --}}
            <div class="content__body">
                {!! $pageBody !!}
            </div>
        </article>

        {{-- No-JS fallback. Renders the static H1 + body derived
             directly from the page record so the page is still
             meaningful without JavaScript. --}}
        <noscript>
            <article class="content">
                <h1 class="content__title">{{ $pageTitle }}</h1>
                <div class="content__body">{!! $pageBody !!}</div>
            </article>
        </noscript>
    </div>
@endsection
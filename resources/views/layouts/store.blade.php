<!DOCTYPE html>
<html lang="en" dir="ltr" class="abo-store">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#050607">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="@yield('description', 'ABO — Premium men\'s streetwear. Modern fit, urban vibe, built for the next generation.')">

    <title>@yield('title', 'ABO — Modern fit, urban vibe.') — Premium Men's Streetwear</title>

    {{--
        Open Graph / Twitter share card.

        `og_title` is a section of its own rather than reusing `title`
        because the <title> above gets " — Premium Men's Streetwear"
        appended to it; a share card should not carry that suffix.

        `og:image` must be ABSOLUTE — WhatsApp, Facebook and Instagram all
        silently drop a relative one — so both the default here and the
        product page's override call asset() themselves.
    --}}
    <link rel="canonical" href="{{ url()->current() }}">

    <meta property="og:type" content="@yield('og_type', 'website')">
    <meta property="og:site_name" content="ABO">
    <meta property="og:title" content="@yield('og_title', 'ABO — Modern fit, urban vibe.')">
    <meta property="og:description" content="@yield('description', 'ABO — Premium men\'s streetwear. Modern fit, urban vibe, built for the next generation.')">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:locale" content="en_US">
    <meta property="og:image" content="@yield('og_image', asset('images/streetwear-hero.jpg'))">
    <meta property="og:image:alt" content="@yield('og_image_alt', 'ABO')">
    {{-- Extra product tags (price, availability) — see product.blade.php. --}}
    @yield('og_extra')

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="@yield('og_title', 'ABO — Modern fit, urban vibe.')">
    <meta name="twitter:description" content="@yield('description', 'ABO — Premium men\'s streetwear. Modern fit, urban vibe, built for the next generation.')">
    <meta name="twitter:image" content="@yield('og_image', asset('images/streetwear-hero.jpg'))">
    <meta name="twitter:image:alt" content="@yield('og_image_alt', 'ABO')">

    <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
    <link rel="icon" type="image/svg+xml" href="{{ \App\Models\StoreSetting::faviconUrl() }}">

    @if (request()->routeIs('home'))
        {{-- Preload the hero photo only on the home page so LCP and reveal are instant. --}}
        <link rel="preload" as="image" href="{{ asset('images/streetwear-hero.jpg') }}">
    @endif

    {{-- Add `js` class to <html> as early as possible so the home page
         can hide elements only when JS is running. Without JS, the page
         is fully visible (graceful degradation per spec §4). --}}
    <script>document.documentElement.classList.add('js');</script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="abo-store-body">
    <a class="skip-to-content" href="#content">Skip to content</a>
    @yield('announcement')
    @include('store.partials.nav')

    <main class="abo-store-main" id="content">
        @if (session('success'))
            <div class="alert-success" role="status">{{ session('success') }}</div>
        @endif

        @if ($errors->any())
            <ul class="alert-errors" role="alert">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
            @endif

        @yield('content')
    </main>

    @include('store.partials.footer')

    @stack('scripts')
</body>
</html>

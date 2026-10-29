{{--
    Admin layout (front-end only, static data).

    Hosts every admin page. Loads the global brand stylesheet
    (resources/css/app.css) for fonts and brand tokens, plus the
    page-local admin stylesheet (public/admin/css/admin.css) and
    the admin script (public/admin/js/admin.js). Pages yield
    'content'. The notification bell lives in the topbar partial.
--}}
<!DOCTYPE html>
<html lang="en" dir="ltr" class="abo-admin">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#050607">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="@yield('description', 'ABO — Admin dashboard')">

    <title>@yield('title', 'Dashboard') · ABO Admin</title>

    {{-- Preconnect to the Bunny font CDN (used by the global brand stylesheet). --}}
    <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
    <link rel="icon" type="image/svg+xml" href="{{ \App\Models\StoreSetting::faviconUrl() }}">
    <link rel="icon" type="image/png" sizes="192x192" href="{{ asset('images/abo-icon-192.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="manifest" href="/manifest.json">

    {{-- Brand fonts + tokens from the global stylesheet. --}}
    @vite(['resources/css/app.css'])

    {{-- Page-local admin stylesheet (public asset, plain <link>, not bundled). --}}
    <link rel="stylesheet" href="{{ asset('admin/css/admin.css') }}">

    {{-- Pages push extra <head> assets (e.g. products.css) here. --}}
    @stack('head')

    {{-- Add `js` class to <html> as early as possible for admin.js. --}}
    <script>document.documentElement.classList.add('js');</script>
</head>
<body class="abo-admin-body">
    @include('admin.partials.sidebar')

    <div class="abo-admin-main">
        @include('admin.partials.topbar')

        <main class="abo-admin-page" id="content">
            @yield('content')
        </main>
    </div>

    {{-- Admin script (notification dropdown + chart redraw + filter chips). --}}
    <script src="{{ asset('admin/js/admin.js') }}" defer></script>

    {{-- Pages push extra <body> scripts (e.g. products.js) here. --}}
    @stack('scripts')
</body>
</html>
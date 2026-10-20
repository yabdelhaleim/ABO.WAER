<!DOCTYPE html>
<html lang="en" dir="ltr" class="abo-auth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#050505">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', 'Login') — ABO · Premium Men's Streetwear</title>

    <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/abo-logo.svg') }}">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="abo-auth-body">
    {{ $slot ?? '' }}
    @yield('content')

    @stack('scripts')
</body>
</html>

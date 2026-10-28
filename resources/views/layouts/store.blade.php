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
    {{-- The storefront chrome is measured, not written: the service
         behind it reads the announcement, the footer columns and the
         store's contact details out of the database. --}}
    @inject('storefront', \App\Services\Store\StorefrontService::class)

    <a class="skip-to-content" href="#content">Skip to content</a>
    @include('store.partials.announcement')
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

    @auth
        <script>
            (function () {
                const statusEl = document.getElementById('account-push-status');
                const btnEl = document.getElementById('account-push-btn');

                if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
                    if (statusEl) statusEl.textContent = 'Not supported by this browser';
                    return;
                }

                const vapidKey = @json(config('webpush.vapid.public_key'));
                const subscribeUrl = @json(route('store.push.subscriptions.update'));

                function urlBase64ToUint8Array(base64String) {
                    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
                    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
                    const rawData = window.atob(base64);
                    const outputArray = new Uint8Array(rawData.length);
                    for (let i = 0; i < rawData.length; ++i) {
                        outputArray[i] = rawData.charCodeAt(i);
                    }
                    return outputArray;
                }

                function updateUI() {
                    if (!statusEl) return;
                    if (Notification.permission === 'granted') {
                        statusEl.textContent = 'Active ✓';
                        statusEl.style.color = 'var(--abo-lime)';
                        if (btnEl) btnEl.style.display = 'none';
                    } else if (Notification.permission === 'denied') {
                        statusEl.textContent = 'Blocked (allow in browser site settings)';
                        statusEl.style.color = '#ff5c5c';
                        if (btnEl) btnEl.style.display = 'none';
                    } else {
                        statusEl.textContent = 'Not enabled';
                        statusEl.style.color = 'var(--abo-gray)';
                        if (btnEl) {
                            btnEl.style.display = 'inline-flex';
                            btnEl.textContent = 'Enable Notifications';
                        }
                    }
                }

                function subscribeUser(reg) {
                    if (!vapidKey || !subscribeUrl) {
                        console.warn('VAPID public key or subscribe route missing.');
                        return Promise.resolve();
                    }
                    return reg.pushManager.getSubscription().then(function (sub) {
                        if (sub) {
                            updateUI();
                            return;
                        }
                        return reg.pushManager.subscribe({
                            userVisibleOnly: true,
                            applicationServerKey: urlBase64ToUint8Array(vapidKey)
                        }).then(function (newSub) {
                            return fetch(subscribeUrl, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                                    'Accept': 'application/json'
                                },
                                body: JSON.stringify(newSub)
                            }).then(function () {
                                updateUI();
                            });
                        });
                    });
                }

                // If already granted, register & ensure subscribed
                navigator.serviceWorker.register('/sw.js').then(function (reg) {
                    updateUI();
                    if (Notification.permission === 'granted') {
                        subscribeUser(reg);
                    }
                }).catch(function (e) {
                    console.warn('SW registration failed:', e);
                });

                // User gesture click handler: 100% compliant with browser permission policies
                if (btnEl) {
                    btnEl.addEventListener('click', function () {
                        btnEl.disabled = true;
                        btnEl.textContent = 'Requesting…';
                        Notification.requestPermission().then(function (perm) {
                            btnEl.disabled = false;
                            updateUI();
                            if (perm === 'granted') {
                                navigator.serviceWorker.ready.then(function (reg) {
                                    subscribeUser(reg);
                                });
                            }
                        }).catch(function () {
                            btnEl.disabled = false;
                            updateUI();
                        });
                    });
                }
            })();
        </script>
    @endauth
</body>
</html>

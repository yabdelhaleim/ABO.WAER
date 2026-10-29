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
    <link rel="icon" type="image/png" sizes="192x192" href="{{ asset('images/abo-icon-192.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="manifest" href="/manifest.json">

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
        <div id="abo-push-banner" style="position:fixed;bottom:1.25rem;left:50%;transform:translateX(-50%);z-index:9999;background:#141416;color:#ffffff;border:1px solid rgba(223,242,74,0.35);border-radius:12px;padding:0.75rem 1.25rem;box-shadow:0 12px 35px rgba(0,0,0,0.6);display:none;align-items:center;gap:1rem;max-width:420px;width:calc(100% - 2rem);font-family:inherit;">
            <span style="font-size:1.3rem;">🔔</span>
            <div style="flex:1;font-size:0.8rem;line-height:1.4;">
                <strong style="color:var(--abo-lime);display:block;margin-bottom:2px;">ABO Notifications</strong>
                Stay updated with exclusive offers & order tracking.
            </div>
            <div style="display:flex;gap:0.5rem;align-items:center;">
                <button type="button" id="abo-push-banner-btn" style="padding:0.4rem 0.9rem;font-size:0.75rem;background:var(--abo-lime);color:#0a0a0b;border:none;font-weight:700;border-radius:6px;cursor:pointer;">Enable</button>
                <button type="button" id="abo-push-banner-close" style="background:none;border:none;color:#888;cursor:pointer;font-size:1.2rem;padding:0 0.25rem;line-height:1;">&times;</button>
            </div>
        </div>

        <script>
            (function () {
                const statusEl = document.getElementById('account-push-status');
                const helpEl = document.getElementById('account-push-help');
                const btnEl = document.getElementById('account-push-btn');
                const testBtnEl = document.getElementById('account-push-test-btn');
                const resetBtnEl = document.getElementById('account-push-reset-btn');
                const bannerEl = document.getElementById('abo-push-banner');
                const bannerBtnEl = document.getElementById('abo-push-banner-btn');
                const bannerCloseEl = document.getElementById('abo-push-banner-close');

                if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
                    if (statusEl) statusEl.textContent = 'Not supported by this browser';
                    return;
                }

                const vapidKey = @json(config('webpush.vapid.public_key'));
                const subscribeUrl = @json(route('store.push.subscriptions.update'));
                const testUrl = @json(route('store.push.subscriptions.test'));

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
                    const perm = Notification.permission;
                    if (perm === 'granted') {
                        if (statusEl) {
                            statusEl.textContent = 'Active ✓';
                            statusEl.style.color = 'var(--abo-lime)';
                        }
                        if (helpEl) helpEl.style.display = 'none';
                        if (btnEl) btnEl.style.display = 'none';
                        if (testBtnEl) testBtnEl.style.display = 'inline-flex';
                        if (resetBtnEl) resetBtnEl.style.display = 'inline-block';
                        if (bannerEl) bannerEl.style.display = 'none';
                    } else if (perm === 'denied') {
                        if (statusEl) {
                            statusEl.textContent = 'Blocked ❌';
                            statusEl.style.color = '#ff5c5c';
                        }
                        if (helpEl) {
                            helpEl.textContent = 'Notifications are blocked in your browser. Click the lock icon 🔒 beside the URL bar to allow notifications, then reload.';
                            helpEl.style.display = 'block';
                        }
                        if (btnEl) {
                            btnEl.style.display = 'inline-flex';
                            btnEl.textContent = 'Re-check Settings';
                        }
                        if (testBtnEl) testBtnEl.style.display = 'none';
                        if (resetBtnEl) resetBtnEl.style.display = 'none';
                        if (bannerEl) bannerEl.style.display = 'none';
                    } else {
                        if (statusEl) {
                            statusEl.textContent = 'Not enabled';
                            statusEl.style.color = 'var(--abo-gray)';
                        }
                        if (helpEl) helpEl.style.display = 'none';
                        if (btnEl) {
                            btnEl.style.display = 'inline-flex';
                            btnEl.textContent = 'Enable Notifications';
                        }
                        if (testBtnEl) testBtnEl.style.display = 'none';
                        if (resetBtnEl) resetBtnEl.style.display = 'none';

                        if (bannerEl && !sessionStorage.getItem('abo_push_dismissed')) {
                            bannerEl.style.display = 'flex';
                        }
                    }
                }

                function createNewSubscription(reg) {
                    return reg.pushManager.subscribe({
                        userVisibleOnly: true,
                        applicationServerKey: urlBase64ToUint8Array(vapidKey)
                    }).then(function (newSub) {
                        return sendSubscriptionToBackend(newSub);
                    });
                }

                function subscribeUser(reg, forceNew) {
                    if (!vapidKey || !subscribeUrl) {
                        console.warn('VAPID public key or subscribe route missing.');
                        return Promise.resolve();
                    }
                    return reg.pushManager.getSubscription().then(function (sub) {
                        if (sub) {
                            if (forceNew) {
                                return sub.unsubscribe().then(function () {
                                    return createNewSubscription(reg);
                                });
                            }
                            return sendSubscriptionToBackend(sub);
                        }
                        return createNewSubscription(reg);
                    }).catch(function (err) {
                        console.error('Subscription error:', err);
                    });
                }

                function sendSubscriptionToBackend(sub) {
                    return fetch(subscribeUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify(sub)
                    }).then(function (res) {
                        if (res.ok) {
                            updateUI();
                        } else {
                            res.json().then(function(err) {
                                console.warn('Push subscription failed on server:', err);
                            });
                        }
                    }).catch(function (err) {
                        console.error('Network error saving subscription:', err);
                    });
                }

                navigator.serviceWorker.register('/sw.js').then(function (reg) {
                    try { reg.update(); } catch (e) {}
                    updateUI();
                    if (Notification.permission === 'granted') {
                        subscribeUser(reg);
                    }
                }).catch(function (e) {
                    console.warn('SW registration failed:', e);
                });

                function triggerPrompt() {
                    if (Notification.permission === 'denied') {
                        alert('Notifications are blocked in your browser.\n\nPlease click the lock icon 🔒 in your address bar, find Notifications, change it to "Allow", and reload.');
                        return;
                    }
                    if (btnEl) {
                        btnEl.disabled = true;
                        btnEl.textContent = 'Requesting…';
                    }
                    Notification.requestPermission().then(function (perm) {
                        if (btnEl) btnEl.disabled = false;
                        updateUI();
                        if (perm === 'granted') {
                            navigator.serviceWorker.ready.then(function (reg) {
                                subscribeUser(reg);
                            });
                        }
                    }).catch(function () {
                        if (btnEl) btnEl.disabled = false;
                        updateUI();
                    });
                }

                if (btnEl) {
                    btnEl.addEventListener('click', triggerPrompt);
                }

                if (bannerBtnEl) {
                    bannerBtnEl.addEventListener('click', function () {
                        if (bannerEl) bannerEl.style.display = 'none';
                        triggerPrompt();
                    });
                }

                if (bannerCloseEl) {
                    bannerCloseEl.addEventListener('click', function () {
                        sessionStorage.setItem('abo_push_dismissed', '1');
                        if (bannerEl) bannerEl.style.display = 'none';
                    });
                }

                if (testBtnEl) {
                    testBtnEl.addEventListener('click', function () {
                        testBtnEl.disabled = true;
                        const originalText = testBtnEl.textContent;
                        testBtnEl.textContent = 'Syncing…';

                        navigator.serviceWorker.ready.then(function (reg) {
                            return subscribeUser(reg);
                        }).catch(function (e) {
                            console.warn('SW ready catch:', e);
                        }).then(function () {
                            testBtnEl.textContent = 'Sending…';
                            return fetch(testUrl, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                                    'Accept': 'application/json'
                                }
                            });
                        }).then(function (res) {
                            return res.json();
                        }).then(function (data) {
                            testBtnEl.disabled = false;
                            testBtnEl.textContent = originalText;
                            if (data.success) {
                                alert('🎉 Test notification dispatched!\nCheck your screen / notification tray.');
                            } else {
                                alert('⚠️ Delivery blip: ' + (data.message || 'Unknown error'));
                            }
                        }).catch(function (err) {
                            testBtnEl.disabled = false;
                            testBtnEl.textContent = originalText;
                            alert('⚠️ Request error: ' + err.message);
                        });
                    });
                }
                if (resetBtnEl) {
                    resetBtnEl.addEventListener('click', function () {
                        resetBtnEl.textContent = 'Re-syncing…';
                        navigator.serviceWorker.ready.then(function (reg) {
                            return subscribeUser(reg, true);
                        }).then(function () {
                            resetBtnEl.textContent = 'Re-sync Device';
                            alert('✓ تم إعادة تهيئة اشتراك هذا الجهاز بنجاح مع السيرفر!\nاضغط الآن على زرار "Send Test Notification" لتجربته.');
                        }).catch(function (e) {
                            resetBtnEl.textContent = 'Re-sync Device';
                            alert('Error: ' + e.message);
                        });
                    });
                }
            })();
        </script>
    @endauth
</body>
</html>

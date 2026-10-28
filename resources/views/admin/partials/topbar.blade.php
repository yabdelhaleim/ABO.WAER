{{--
    Admin topbar partial.

    Contains:
      • A search field on the left
      • The notification bell with a dropdown panel (340px wide)
      • The "Admin" name + lime avatar block on the right

    The bell and its dropdown are wired by public/admin/js/admin.js.
    The bell's badge and its initial list are hydrated server-side
    from the `notifications` table (database channel) via
    App\Services\Admin\AdminNotificationService. admin.js keeps the
    badge in sync after the user marks items read and polls every
    30 seconds for new orders.
--}}
@php
    // Hydrate the bell straight from the admin's own `notifications`
    // table — the same data admin.js polls every 30s. Keeping the
    // initial paint honest means the badge is never a hard-coded 3
    // when there are 12 unread orders sitting in the table.
    $bellAdmin = auth('admin')->user();
    $bellNotifications = $bellAdmin
        ? app(\App\Services\Admin\AdminNotificationService::class)->listForAdmin($bellAdmin)->all()
        : [];
    $unreadCount = $bellAdmin
        ? app(\App\Services\Admin\AdminNotificationService::class)->unreadCountFor($bellAdmin)
        : 0;

    // The signed-in admin's own name and first letter. The topbar used
    // to say "Admin" to everybody, so a screen full of real names had
    // one account that never named itself — the operator could not
    // tell whose session they were looking at.
    $signedInName = $bellAdmin?->name ?: 'Admin';
    $signedInInitial = mb_strtoupper(mb_substr($signedInName, 0, 1));
@endphp

@once
    @push('head')
        <script>
            // Initial bell payload. admin.js uses this so the first
            // paint already shows the right rows and badge count
            // instead of a brief "loading" empty state.
            window.INITIAL_ADMIN_NOTIFICATIONS = @json($bellNotifications);
            window.ADMIN_NOTIFICATIONS_ENDPOINTS = {
                index:        @json(route('admin.notifications.index')),
                markAllRead:  @json(route('admin.notifications.markAllRead')),
                markReadBase: @json(url('admin/notifications') . '/'),
                pushSubscribe: @json(route('admin.push.subscriptions.update')),
            };
            window.ADMIN_NOTIFICATIONS_POLL_MS = 30000;
            window.VAPID_PUBLIC_KEY = @json(config('webpush.vapid.public_key'));
        </script>
    @endpush
@endonce

<header class="abo-admin-topbar" role="banner">
    <form class="abo-admin-topbar__search" role="search" onsubmit="event.preventDefault();">
        <label class="sr-only" for="abo-admin-topbar-search">Search</label>
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" class="abo-admin-topbar__search-icon">
            <path d="M21 20.3 16.7 16a8 8 0 1 0-1.4 1.4L20.3 21 21 20.3zM4 10a6 6 0 1 1 12 0 6 6 0 0 1-12 0z" fill="currentColor"/>
        </svg>
        <input id="abo-admin-topbar-search" type="search" placeholder="Search orders, products, customers…" autocomplete="off">
        <kbd class="abo-admin-topbar__search-kbd" aria-hidden="true">⌘ K</kbd>
    </form>

    {{-- Notification bell + dropdown. The dropdown is closed by
         default; admin.js toggles the `is-open` class on the
         wrapper and the `aria-expanded` attribute on the button. --}}
    <div class="abo-admin-bell" data-abo-admin-bell>
        <button type="button"
                class="abo-admin-bell__btn"
                aria-label="Notifications"
                aria-expanded="false"
                aria-controls="abo-admin-bell-panel"
                data-abo-admin-bell-trigger>
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M12 2a6 6 0 0 0-6 6v3.5L4 14v2h16v-2l-2-2.5V8a6 6 0 0 0-6-6zm0 20a3 3 0 0 0 3-3H9a3 3 0 0 0 3 3z" fill="currentColor"/>
            </svg>
            <span class="abo-admin-bell__badge"
                  data-abo-admin-bell-badge
                  @if ($unreadCount === 0) hidden @endif>{{ $unreadCount }}</span>
        </button>

        <div class="abo-admin-bell__panel"
             id="abo-admin-bell-panel"
             role="dialog"
             aria-label="Notifications"
             hidden
             data-abo-admin-bell-panel>
            <header class="abo-admin-bell__head">
                <h2 class="abo-admin-bell__title">NOTIFICATIONS</h2>
                <button type="button"
                        class="abo-admin-bell__mark"
                        data-abo-admin-bell-mark>Mark all read</button>
            </header>

            <div class="abo-admin-bell__filters" role="tablist" aria-label="Filter notifications">
                <button type="button" role="tab" aria-selected="true"  data-abo-admin-bell-filter="all"     class="is-active">All</button>
                <button type="button" role="tab" aria-selected="false" data-abo-admin-bell-filter="orders"  >Orders</button>
                <button type="button" role="tab" aria-selected="false" data-abo-admin-bell-filter="stock"   >Stock</button>
                <button type="button" role="tab" aria-selected="false" data-abo-admin-bell-filter="returns" >Returns</button>
            </div>

            <ul class="abo-admin-bell__list" data-abo-admin-bell-list>
                {{-- Items rendered initially by admin.js from
                     window.INITIAL_ADMIN_NOTIFICATIONS, so the markup
                     is empty until the script runs. --}}
            </ul>

            <footer class="abo-admin-bell__foot">
                <a href="#/notifications" class="abo-admin-bell__viewall">View all notifications →</a>
            </footer>
        </div>
    </div>

    <div class="abo-admin-topbar__admin">
        <span class="abo-admin-topbar__admin-name">{{ $signedInName }}</span>
        <span class="abo-admin-topbar__avatar" aria-hidden="true">{{ $signedInInitial }}</span>
    </div>
</header>

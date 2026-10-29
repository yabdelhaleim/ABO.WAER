'use strict';

self.addEventListener('install', function (event) {
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('push', function (event) {
    let data = {};
    if (event.data) {
        try {
            data = event.data.json();
        } catch (e) {
            data = {
                title: 'ABO Store',
                body: event.data.text()
            };
        }
    }

    const title = data.title || 'ABO Store';
    const options = {
        body: data.body || '',
        icon: data.icon || '/images/abo-icon-192.png',
        badge: data.badge || '/images/abo-badge-96.png',
        image: data.image || null,
        tag: data.tag || ('abo-notification-' + Date.now()),
        renotify: true,
        vibrate: data.vibrate || [200, 100, 200],
        data: data.data || {},
        actions: data.actions || []
    };

    event.waitUntil(
        self.registration.showNotification(title, options).catch(function (err) {
            console.error('showNotification failed:', err);
        })
    );
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();

    let targetUrl = (event.notification.data && event.notification.data.url) ? event.notification.data.url : '/';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clientList) {
            for (let i = 0; i < clientList.length; i++) {
                const client = clientList[i];
                if (client.url === targetUrl && 'focus' in client) {
                    return client.focus();
                }
            }
            if (clients.openWindow) {
                return clients.openWindow(targetUrl);
            }
        })
    );
});

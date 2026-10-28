'use strict';

self.addEventListener('push', function (event) {
    if (!(self.Notification && self.Notification.permission === 'granted')) {
        return;
    }

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
        icon: data.icon || '/images/abo-logo.svg',
        badge: data.badge || '/images/abo-logo.svg',
        tag: data.tag || 'abo-notification',
        vibrate: data.vibrate || [200, 100, 200],
        data: data.data || {},
        actions: data.actions || []
    };

    event.waitUntil(self.registration.showNotification(title, options));
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

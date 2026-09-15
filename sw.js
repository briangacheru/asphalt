// iVehicle service worker — enables "Add to Home Screen" installability and
// browser push notifications for service/insurance/licence reminders.
// Intentionally does NOT cache app pages (they're dynamic, per-user PHP), so
// there's no offline app shell here — this is push + installability only.

self.addEventListener('install', function (event) {
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('push', function (event) {
    if (!event.data) return;

    var payload = {};
    try {
        payload = event.data.json();
    } catch (e) {
        payload = { title: 'iVehicle', body: event.data.text() };
    }

    var title = payload.title || 'iVehicle';
    var options = {
        body: payload.body || '',
        icon: payload.icon || 'assets/img/pwa/icon-192.png',
        badge: 'assets/img/pwa/icon-192.png',
        data: { url: payload.url || '/' }
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    var url = (event.notification.data && event.notification.data.url) || '/';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clientList) {
            for (var i = 0; i < clientList.length; i++) {
                var client = clientList[i];
                if ('focus' in client) {
                    client.navigate(url);
                    return client.focus();
                }
            }
            if (self.clients.openWindow) {
                return self.clients.openWindow(url);
            }
        })
    );
});

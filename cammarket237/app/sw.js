// CamMarket237 Service Worker - Push Notifications
const CACHE_NAME = 'cammarket237-v1';

self.addEventListener('install', function(e) {
  self.skipWaiting();
});

self.addEventListener('activate', function(e) {
  e.waitUntil(clients.claim());
});

// Handle push notifications
self.addEventListener('push', function(e) {
  var data = {};
  try { data = e.data.json(); } catch(err) { data = {title: 'CamMarket237', body: e.data ? e.data.text() : 'New notification'}; }

  var title   = data.title || 'CamMarket237 \uD83C\uDDE8\uD83C\uDDF2';
  var options = {
    body:    data.body || 'You have a new notification',
    icon:    '/icon-192.png',
    badge:   '/icon-72.png',
    tag:     data.tag || 'cammarket237',
    data:    data.url ? {url: data.url} : {},
    vibrate: [200, 100, 200],
    actions: data.actions || []
  };

  e.waitUntil(self.registration.showNotification(title, options));
});

// Handle notification click
self.addEventListener('notificationclick', function(e) {
  e.notification.close();
  var url = (e.notification.data && e.notification.data.url) ? e.notification.data.url : '/';
  e.waitUntil(
    clients.matchAll({type: 'window', includeUncontrolled: true}).then(function(clientList) {
      for (var i = 0; i < clientList.length; i++) {
        var client = clientList[i];
        if (client.url.includes(self.location.origin) && 'focus' in client) {
          return client.focus();
        }
      }
      if (clients.openWindow) return clients.openWindow(url);
    })
  );
});

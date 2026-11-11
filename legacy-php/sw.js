/* Language Hub service worker — only handles Web Push popups + notification
   clicks. No offline caching (keeps behaviour identical to a normal page
   load; this is not a PWA-offline shell). */
'use strict';

self.addEventListener('install', function (e) {
  self.skipWaiting();
});

self.addEventListener('activate', function (e) {
  e.waitUntil(self.clients.claim());
});

self.addEventListener('push', function (e) {
  var data = {};
  try { data = e.data ? e.data.json() : {}; } catch (err) { data = { title: 'Language Hub', body: e.data ? e.data.text() : '' }; }

  var title = data.title || 'Language Hub';
  var options = {
    body: data.body || '',
    icon: data.icon || '/image/language-hub-logo-192.png',
    badge: data.icon || '/image/language-hub-logo-192.png',
    data: { url: data.url || '/' },
    tag: 'ba-notification',
    renotify: true
  };

  e.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', function (e) {
  e.notification.close();
  var url = (e.notification.data && e.notification.data.url) || '/';

  e.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clientList) {
      for (var i = 0; i < clientList.length; i++) {
        var c = clientList[i];
        if (c.url === url && 'focus' in c) return c.focus();
      }
      if (self.clients.openWindow) return self.clients.openWindow(url);
    })
  );
});

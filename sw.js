/**
 * Minimal service worker for the RSS reader.
 * - Handles 'push' events: shows a notification with the new-article count
 * - Notification click: focuses an existing tab or opens the reader
 * - No caching — keep the SW simple, the reader is mostly server-driven
 */

self.addEventListener('install',  (e) => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
  let payload = { title: 'Neue Artikel', body: '', count: 0 };
  if (event.data) {
    try { payload = { ...payload, ...event.data.json() }; }
    catch { payload.body = event.data.text(); }
  }
  const title = payload.title || 'RSS Reader';
  const opts = {
    body: payload.body || (payload.count > 0 ? `${payload.count} neue${payload.count === 1 ? 'r' : ''} Artikel` : ''),
    icon: 'assets/icon.php?size=192',
    badge: 'assets/icon.php?size=96',
    tag: 'rss-new',
    renotify: true,
    data: { url: payload.url || './' },
  };
  event.waitUntil(self.registration.showNotification(title, opts));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = event.notification.data?.url || './';
  event.waitUntil((async () => {
    const all = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const c of all) {
      if (c.url.includes(self.registration.scope)) { c.focus(); return; }
    }
    return self.clients.openWindow(url);
  })());
});

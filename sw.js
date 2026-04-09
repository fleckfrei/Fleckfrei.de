const CACHE = 'fleckfrei-v10';
const OFFLINE_URL = '/offline.html';
const PRECACHE = [OFFLINE_URL, '/icons/icon.php?s=192'];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(keys => Promise.all(
      keys.filter(k => k !== CACHE).map(k => caches.delete(k))
    )).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', e => {
  const req = e.request;
  // Skip non-GET, API calls, and external resources
  if (req.method !== 'GET') return;
  if (req.url.includes('/api/')) return;
  if (!req.url.startsWith(self.location.origin)) return;

  // Network-first for HTML pages
  if (req.headers.get('accept')?.includes('text/html')) {
    e.respondWith(
      fetch(req).then(resp => {
        const clone = resp.clone();
        caches.open(CACHE).then(c => c.put(req, clone));
        return resp;
      }).catch(() => caches.match(req).then(r => r || caches.match(OFFLINE_URL)))
    );
    return;
  }

  // Cache-first for static assets (fonts, CSS, images)
  if (req.url.match(/\.(css|js|woff2?|ttf|png|jpg|svg|ico)(\?|$)/)) {
    e.respondWith(
      caches.match(req).then(cached => cached || fetch(req).then(resp => {
        const clone = resp.clone();
        caches.open(CACHE).then(c => c.put(req, clone));
        return resp;
      }))
    );
    return;
  }

  // Network-first default
  e.respondWith(fetch(req).catch(() => caches.match(req)));
});

// Push notifications
self.addEventListener('push', e => {
  const data = e.data?.json() || { title: 'Fleckfrei', body: 'Neue Benachrichtigung' };
  e.waitUntil(self.registration.showNotification(data.title, {
    body: data.body, icon: '/icons/icon.php?s=192', badge: '/icons/icon.php?s=72',
    vibrate: [200, 100, 200], tag: data.tag || 'default',
    data: { url: data.url || '/' }
  }));
});

self.addEventListener('notificationclick', e => {
  e.notification.close();
  e.waitUntil(clients.openWindow(e.notification.data.url || '/'));
});

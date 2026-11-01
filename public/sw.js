/* NYX Messenger PWA service worker v3 */
const CACHE = 'nyx-shell-v3';
const SHELL = [
  './',
  './client.html',
  './status.html',
  './identity.html',
  './home.html',
  './manifest.webmanifest',
  './assets/icons/icon-192.png',
  './assets/icons/icon-512.png',
  './assets/icons/icon-maskable-512.png'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE)
      .then((c) => c.addAll(SHELL.map((u) => new Request(u, { cache: 'reload' }))))
      .then(() => self.skipWaiting())
      .catch(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)));
    await self.clients.claim();
  })());
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.pathname.includes('/api/') || url.pathname.endsWith('/ws') || url.pathname.endsWith('/ws.php')) {
    return;
  }
  const isNav = req.mode === 'navigate' || (req.headers.get('accept') || '').includes('text/html');
  if (isNav) {
    event.respondWith((async () => {
      try {
        const net = await fetch(req);
        const copy = net.clone();
        caches.open(CACHE).then((c) => c.put(req, copy)).catch(() => {});
        return net;
      } catch (e) {
        return (await caches.match(req)) || (await caches.match('./client.html')) || Response.error();
      }
    })());
    return;
  }
  event.respondWith((async () => {
    const hit = await caches.match(req);
    if (hit) return hit;
    try {
      const net = await fetch(req);
      if (net && net.ok) {
        const copy = net.clone();
        caches.open(CACHE).then((c) => c.put(req, copy)).catch(() => {});
      }
      return net;
    } catch (e) {
      return hit || Response.error();
    }
  })());
});

self.addEventListener('message', (event) => {
  if (event.data === 'skipWaiting') self.skipWaiting();
});

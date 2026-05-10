// MIS service worker — cache app shell, network-first for API.
const CACHE = 'mis-shell-v1';
const SHELL = [
  './',
  './index.php',
  './assets/css/app.css',
  './assets/js/app.js',
  './manifest.webmanifest',
  './icons/icon-192.png',
  './icons/icon-512.png',
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE).then(cache => cache.addAll(SHELL)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', event => {
  const req = event.request;
  const url = new URL(req.url);

  // Never cache API calls — always fresh
  if (url.pathname.includes('/api/')) {
    event.respondWith(fetch(req).catch(() =>
      new Response(JSON.stringify({ error: 'offline' }), {
        headers: { 'Content-Type': 'application/json' }, status: 503,
      })
    ));
    return;
  }

  // Network-first for navigations, fall back to cached shell
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req).catch(() => caches.match('./index.php'))
    );
    return;
  }

  // Cache-first for shell assets
  event.respondWith(
    caches.match(req).then(hit => hit || fetch(req).then(resp => {
      const clone = resp.clone();
      if (resp.ok && req.method === 'GET') {
        caches.open(CACHE).then(c => c.put(req, clone));
      }
      return resp;
    }))
  );
});

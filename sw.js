/* ParkSmart service worker
 * - PHP pages are NEVER cached (they hold sessions, plate numbers, live DB data).
 * - Static files (icons, CSS/JS libs, fonts) are cached for speed.
 * - If the network is down, a friendly offline page is shown.
 * Bump VERSION whenever you change this file or the precache list.
 */
const VERSION = 'parksmart-v1';
const CACHE = VERSION + '-static';
const OFFLINE_URL = 'offline.html';
const PRECACHE = [
  OFFLINE_URL,
  'manifest.json',
  'icons/icon-192.png',
  'icons/icon-512.png',
  'icons/apple-touch-icon.png'
];
const CDN_HOSTS = [
  'cdnjs.cloudflare.com',
  'cdn.jsdelivr.net',
  'cdn.datatables.net',
  'unpkg.com',
  'fonts.googleapis.com',
  'fonts.gstatic.com'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE).then((c) => c.addAll(PRECACHE)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;               // forms / POST always go to the network

  const url = new URL(req.url);

  // Page navigations: network only, offline page as fallback
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req).catch(() => caches.match(OFFLINE_URL))
    );
    return;
  }

  const sameOrigin = url.origin === self.location.origin;
  const isCdn = CDN_HOSTS.includes(url.hostname);
  const isStaticFile = /\.(?:png|jpg|jpeg|gif|svg|webp|ico|css|js|woff2?|ttf)$/i.test(url.pathname);

  // Never cache dynamic PHP / ajax / api responses
  if (sameOrigin && !isStaticFile) return;

  if ((sameOrigin && isStaticFile) || isCdn) {
    // stale-while-revalidate
    event.respondWith(
      caches.open(CACHE).then((cache) =>
        cache.match(req).then((cached) => {
          const network = fetch(req).then((res) => {
            if (res && (res.ok || res.type === 'opaque')) cache.put(req, res.clone());
            return res;
          }).catch(() => cached);
          return cached || network;
        })
      )
    );
  }
});

/* AntonX service worker (item #11).
 * - App-shell / static assets: stale-while-revalidate.
 * - PHP pages: network-first with cache fallback (so the app opens offline,
 *   but always prefers fresh data when online).
 * - Never caches non-GET, the chat SSE stream, or API endpoints.
 */
const CACHE = 'antonx-v1';
const STATIC_RE = /\.(?:css|js|png|jpg|jpeg|webp|gif|svg|ico|woff2?|ttf)$/i;

self.addEventListener('install', (event) => {
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

function isUncacheable(url) {
  // SSE event stream + JSON APIs should always hit the network.
  return url.pathname.indexOf('/chat/api/') !== -1 ||
         url.pathname.endsWith('/events.php') ||
         url.pathname.endsWith('_api.php') ||
         url.pathname.indexOf('/api/') !== -1;
}

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;

  let url;
  try { url = new URL(req.url); } catch (e) { return; }
  if (url.origin !== self.location.origin) return; // leave cross-origin (fonts/CDN) to the browser
  if (isUncacheable(url)) return;

  if (STATIC_RE.test(url.pathname)) {
    // Stale-while-revalidate for static assets.
    event.respondWith(
      caches.open(CACHE).then((cache) =>
        cache.match(req).then((cached) => {
          const network = fetch(req).then((res) => {
            if (res && res.status === 200) cache.put(req, res.clone());
            return res;
          }).catch(() => cached);
          return cached || network;
        })
      )
    );
    return;
  }

  // Network-first for pages (PHP / navigations).
  event.respondWith(
    fetch(req).then((res) => {
      if (res && res.status === 200 && res.type === 'basic') {
        const copy = res.clone();
        caches.open(CACHE).then((cache) => cache.put(req, copy));
      }
      return res;
    }).catch(() => caches.match(req).then((c) => c || caches.match('dashboard.php')))
  );
});

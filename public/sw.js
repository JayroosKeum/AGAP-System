/* AGAP Local Network / Offline Asset Cache Service Worker */
const CACHE_NAME = 'agap-static-v1';

const isCacheableAsset = (url) => {
  if (url.origin !== self.location.origin) return false;
  if (url.pathname.includes('/backend/api/')) return false;
  return /\.(?:css|js|png|jpe?g|svg|webp|woff2?|ico)$/i.test(url.pathname);
};

self.addEventListener('install', (event) => {
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);
  if (!isCacheableAsset(url)) return;

  event.respondWith(
    caches.match(request).then((cached) => {
      const networkFetch = fetch(request)
        .then((response) => {
          if (response && response.ok) {
            const copy = response.clone();
            caches.open(CACHE_NAME).then((cache) => cache.put(request, copy));
          }
          return response;
        })
        .catch(() => cached);

      return cached || networkFetch;
    })
  );
});

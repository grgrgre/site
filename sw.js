/* SvityazHOME service worker */
const SW_VERSION = 'svh-sw-20260312-1';
const STATIC_CACHE = `${SW_VERSION}-static`;
const PAGE_CACHE = `${SW_VERSION}-pages`;
const OFFLINE_URL = '/offline.html';

const PRECACHE = [
  '/',
  '/offline.html',
  '/assets/css/site.css',
  '/assets/js/app.js',
  '/manifest.json',
];

const STATIC_EXT_RE = /\.(?:css|js|mjs|map|png|jpe?g|webp|avif|svg|ico|woff2?|ttf|otf)$/i;

const isSameOrigin = (url) => url.origin === self.location.origin;
const isApi = (url) => url.pathname.startsWith('/api/');
const isStatic = (request, url) => {
  if (request.destination && ['style', 'script', 'image', 'font'].includes(request.destination)) {
    return true;
  }
  if (url.pathname.startsWith('/assets/')) return true;
  return STATIC_EXT_RE.test(url.pathname);
};

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(STATIC_CACHE)
      .then((cache) => cache.addAll(PRECACHE))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(
        keys
          .filter((key) => !key.startsWith(SW_VERSION))
          .map((key) => caches.delete(key))
      ))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});

async function networkFirstPage(request) {
  const pageCache = await caches.open(PAGE_CACHE);
  try {
    const response = await fetch(request);
    if (response && response.ok && response.type === 'basic') {
      const robotsTag = (response.headers.get('X-Robots-Tag') || '').toLowerCase();
      if (!robotsTag.includes('noindex')) {
        pageCache.put(request, response.clone());
      }
    }
    return response;
  } catch (error) {
    const cached = await pageCache.match(request);
    if (cached) return cached;
    const offline = await caches.match(OFFLINE_URL);
    if (offline) return offline;
    return new Response('Offline', {
      status: 503,
      headers: { 'Content-Type': 'text/plain; charset=UTF-8' },
    });
  }
}

async function staleWhileRevalidate(request, cacheName) {
  const cache = await caches.open(cacheName);
  const cached = await cache.match(request);

  const networkPromise = fetch(request)
    .then((response) => {
      if (response && response.ok && (response.type === 'basic' || response.type === 'cors')) {
        cache.put(request, response.clone());
      }
      return response;
    })
    .catch(() => null);

  return cached || networkPromise || fetch(request);
}

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);
  if (!isSameOrigin(url)) return;
  if (isApi(url)) return;

  if (request.mode === 'navigate') {
    event.respondWith(networkFirstPage(request));
    return;
  }

  if (isStatic(request, url)) {
    event.respondWith(staleWhileRevalidate(request, STATIC_CACHE));
  }
});

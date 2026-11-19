// A company's own service worker (companies plan, Phase 4d). Served from the
// company's address, so its cache belongs to that origin alone.
const CACHE = @json($cache);
const OFFLINE = '/offline';

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((cache) => cache.add(OFFLINE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    // Only this company's older caches; an origin never sees another's.
    event.waitUntil(
        caches.keys()
            .then((names) => Promise.all(names.filter((name) => name.startsWith('site-') && name !== CACHE).map((name) => caches.delete(name))))
            .then(() => self.clients.claim()),
    );
});

// Pages from the network first, always: nothing a company changes is ever
// served stale. The offline page only when there is no network at all.
self.addEventListener('fetch', (event) => {
    if (event.request.mode !== 'navigate') {
        return;
    }

    event.respondWith(fetch(event.request).catch(() => caches.match(OFFLINE)));
});

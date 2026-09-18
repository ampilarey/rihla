/**
 * Rihla Travels service worker.
 *
 * The previous version could never install. cache.addAll() rejects the whole
 * batch if any single request 404s, and three of its five entries did not
 * exist: /css/app.css and /js/app.js (Vite emits hashed files under
 * /build/assets/) and '/images/guide/', which is a directory. Every install
 * therefore threw, the cache stayed empty, and nothing ever worked offline.
 *
 * It was also cache-first for every request including navigations, so any page
 * it did manage to store would be served stale forever.
 */

const VERSION = 'v2';
const SHELL_CACHE = `rihla-shell-${VERSION}`;
const RUNTIME_CACHE = `rihla-runtime-${VERSION}`;

/**
 * Precached individually rather than with addAll, so a single missing file
 * degrades that one entry instead of failing the install.
 */
const SHELL = [
    '/offline.html',
    '/images/icon-192.png',
    '/images/icon-512.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil((async () => {
        const cache = await caches.open(SHELL_CACHE);

        await Promise.allSettled(SHELL.map((url) => cache.add(url)));

        // Take over as soon as the new worker is ready rather than waiting for
        // every tab to close; paired with clients.claim() below.
        await self.skipWaiting();
    })());
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const names = await caches.keys();

        await Promise.all(
            names
                .filter((name) => name !== SHELL_CACHE && name !== RUNTIME_CACHE)
                .map((name) => caches.delete(name)),
        );

        await self.clients.claim();
    })());
});

/** Hashed build assets are immutable, so they are safe to serve from cache. */
function isImmutableAsset(url) {
    return url.pathname.startsWith('/build/assets/');
}

function isStaticAsset(url) {
    return isImmutableAsset(url)
        || url.pathname.startsWith('/images/')
        || url.pathname.startsWith('/fonts/')
        || url.pathname.startsWith('/js/');
}

/**
 * Network-first. A cached page is a fallback for being offline, never the
 * normal answer — prices, departure dates and seat counts change, and serving
 * a stale trip page is worse than serving none.
 */
async function handleNavigation(request) {
    const cache = await caches.open(RUNTIME_CACHE);

    try {
        const response = await fetch(request);

        if (response.ok) {
            cache.put(request, response.clone());
        }

        return response;
    } catch (error) {
        const cached = await cache.match(request);

        if (cached) {
            return cached;
        }

        const offline = await caches.match('/offline.html');

        // Without this last resort the browser shows its own error page, which
        // is the behaviour the offline page exists to replace.
        return offline || Response.error();
    }
}

/** Cache-first: these are content-hashed or rarely change. */
async function handleAsset(request) {
    const cached = await caches.match(request);

    if (cached) {
        return cached;
    }

    const response = await fetch(request);

    // Opaque cross-origin responses report status 0 and cannot be inspected,
    // so storing them would fill the cache with possible errors.
    if (response.ok && response.type === 'basic') {
        const cache = await caches.open(RUNTIME_CACHE);
        cache.put(request, response.clone());
    }

    return response;
}

self.addEventListener('fetch', (event) => {
    const { request } = event;

    // Only GET is cacheable, and a POST must never be replayed from cache.
    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    // Other origins (fonts, WhatsApp) are left entirely to the browser.
    if (url.origin !== self.location.origin) {
        return;
    }

    // Never intercept the admin panel or anything authenticated: a cached
    // response could be shown to the wrong user after logout.
    if (url.pathname.startsWith('/admin') || url.pathname.startsWith('/dashboard')) {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(handleNavigation(request));

        return;
    }

    if (isStaticAsset(url)) {
        event.respondWith(handleAsset(request));
    }
});

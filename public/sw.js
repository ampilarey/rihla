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

const VERSION = 'v4';
const SHELL_CACHE = `rihla-shell-${VERSION}`;
const RUNTIME_CACHE = `rihla-runtime-${VERSION}`;

/**
 * The Ziyarah Guide a pilgrim deliberately saved — §7.2.
 *
 * Deliberately **not** versioned. The shell and runtime caches are wiped
 * whenever VERSION changes, which is correct for them and would be a
 * serious bug here: somebody saves the guide the night before they fly, the
 * site ships a fix while they are in Makkah, their phone picks up the new
 * worker on the hotel wifi, and the guide they saved is gone the next time
 * they need it with no data. A cache holding what the user asked for is
 * cleared when the user asks, not when we deploy.
 */
const ZIYARAH_CACHE = 'rihla-ziyarah';

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
                .filter((name) => name !== SHELL_CACHE
                    && name !== RUNTIME_CACHE
                    && name !== ZIYARAH_CACHE)
                .map((name) => caches.delete(name)),
        );

        await self.clients.claim();
    })());
});

/**
 * Hashed build assets are immutable, so they are safe to serve from cache.
 *
 * Vite renames the file whenever its contents change, so a cache hit here can
 * only ever be the right bytes. Nothing else on this origin has that property.
 */
function isImmutableAsset(url) {
    return url.pathname.startsWith('/build/assets/');
}

/**
 * Everything else worth caching keeps its URL across deploys.
 *
 * `/images/rihla-mark-inverse.svg` is the same path before and after a
 * rebrand, so a cache-first entry for it is a promise to serve last year's
 * logo forever. That is not hypothetical: it is what put the pre-violet dhoni
 * in the footer of every phone that had visited the site once, while the CSS
 * beside it updated normally because Vite had given it a new name.
 */
function isMutableAsset(url) {
    return url.pathname.startsWith('/images/')
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
        // The deliberately saved guide first. A pilgrim who pressed "save
        // all pages" gets that copy rather than whatever the runtime cache
        // happens to hold, and gets it whether or not they ever browsed to
        // this particular page.
        const kept = await caches.match(request, { cacheName: ZIYARAH_CACHE });

        if (kept) {
            return kept;
        }

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

/** Cache-first. Safe only because the URL changes whenever the bytes do. */
async function handleImmutableAsset(request) {
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

/**
 * Stale-while-revalidate, for assets whose URL outlives their contents.
 *
 * The page is answered from cache straight away, so this stays as fast as
 * cache-first and still works with no signal. The difference is that the
 * network request goes out every time regardless of the hit, and the cache is
 * replaced with what comes back — so a changed file is wrong for exactly one
 * page load instead of until somebody remembers to bump VERSION.
 *
 * `event.waitUntil` is what makes that true. Returning the cached response
 * settles the fetch event, and without it the browser is free to kill the
 * worker before the revalidation has finished writing.
 */
async function handleMutableAsset(event) {
    const { request } = event;
    const cache = await caches.open(RUNTIME_CACHE);

    // Fall back to the wider lookup for the handful of images the install step
    // put in the shell cache; without it those would miss here and fail when
    // there is no network.
    const cached = (await cache.match(request)) ?? (await caches.match(request));

    const revalidate = fetch(request)
        .then((response) => {
            if (response.ok && response.type === 'basic') {
                cache.put(request, response.clone());
            }

            return response;
        })
        .catch(() => null);

    if (cached) {
        event.waitUntil(revalidate);

        return cached;
    }

    return (await revalidate) ?? Response.error();
}

/**
 * Fetch every page of the Ziyarah Guide, because the pilgrim asked.
 *
 * Reports what it actually saved. Answering "done" for a batch where three
 * requests failed would mean the pilgrim finds out in Mina, which is the
 * failure the whole feature exists to prevent — so the counts are real and
 * the page says so.
 *
 * `reload` skips the HTTP cache: somebody pressing "save again" after a
 * correction went up is asking for the corrected page, and a 200 served
 * from the browser's own cache would hand them the old one.
 */
async function saveZiyarah(urls) {
    const cache = await caches.open(ZIYARAH_CACHE);

    const results = await Promise.all(urls.map(async (url) => {
        try {
            const response = await fetch(url, { cache: 'reload', credentials: 'same-origin' });

            if (!response.ok || response.type !== 'basic') {
                return false;
            }

            await cache.put(url, response.clone());

            return true;
        } catch (error) {
            return false;
        }
    }));

    return {
        saved: results.filter(Boolean).length,
        failed: results.filter((ok) => !ok).length,
    };
}

self.addEventListener('message', (event) => {
    if (event.data?.type !== 'ziyarah-save' || !Array.isArray(event.data.urls)) {
        return;
    }

    const port = event.ports[0];

    event.waitUntil(saveZiyarah(event.data.urls).then((result) => {
        port?.postMessage(result);
    }));
});

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

    if (isImmutableAsset(url)) {
        event.respondWith(handleImmutableAsset(request));

        return;
    }

    if (isMutableAsset(url)) {
        event.respondWith(handleMutableAsset(event));
    }
});

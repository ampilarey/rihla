/**
 * Saving the whole Ziyarah Guide to the phone — §7.2.
 *
 * ## Why this is not left to the service worker's runtime cache
 *
 * The existing worker caches pages as they are visited, which means a
 * pilgrim has the pages they happened to open and none of the others. §7.2
 * calls full offline support "the feature pilgrims use with no data in
 * Saudi Arabia", and someone standing outside a place they did not think to
 * read about beforehand is exactly the case that describes. So this asks
 * the worker to fetch every live page deliberately, while there is still
 * wifi, and reports what it actually managed to save.
 *
 * ## It reports failures rather than claiming success
 *
 * A button that says "saved" when three pages did not download is worse
 * than no button: the pilgrim finds out in Mina. The status line names the
 * number saved, and says plainly when some did not.
 */

const STATE_KEY = 'rihla.ziyarah.offline';

/** Whether the browser can do this at all, not whether we would like it to. */
function isSupported() {
    return 'serviceWorker' in navigator && 'caches' in window;
}

function readState() {
    try {
        const raw = localStorage.getItem(STATE_KEY);

        return raw ? JSON.parse(raw) : null;
    } catch (error) {
        // Private browsing, or storage the user has blocked. Not an error
        // worth showing — it only means we cannot remember last time.
        return null;
    }
}

function writeState(state) {
    try {
        localStorage.setItem(STATE_KEY, JSON.stringify(state));
    } catch (error) {
        // Same: the pages are still saved in the cache, which is the part
        // that matters. We just cannot say when.
    }
}

function savedOnDate(iso) {
    const date = new Date(iso);

    return Number.isNaN(date.getTime())
        ? ''
        : date.toLocaleDateString(undefined, { day: 'numeric', month: 'long' });
}

/**
 * Everything this page needs besides its own HTML.
 *
 * Read off the document rather than listed server-side, so a change to the
 * build output cannot leave the saved copy unstyled — which would look to a
 * pilgrim like the save had half failed.
 */
function assetUrls() {
    const urls = [];

    document.querySelectorAll('link[rel="stylesheet"][href], script[src]').forEach((node) => {
        const raw = node.getAttribute('href') || node.getAttribute('src');

        if (!raw) {
            return;
        }

        const url = new URL(raw, window.location.href);

        if (url.origin === window.location.origin) {
            urls.push(url.href);
        }
    });

    return urls;
}

async function worker() {
    const registration = await navigator.serviceWorker.ready;

    return registration.active;
}

/** Ask the worker to save a list, and wait for the count it actually got. */
function askWorkerToSave(active, urls) {
    return new Promise((resolve, reject) => {
        const channel = new MessageChannel();

        // A worker that never answers would leave the button saying
        // "Saving…" for ever, which reads as success to somebody about to
        // board a plane.
        const timeout = setTimeout(() => reject(new Error('timeout')), 120000);

        channel.port1.onmessage = (event) => {
            clearTimeout(timeout);
            resolve(event.data);
        };

        active.postMessage({ type: 'ziyarah-save', urls }, [channel.port2]);
    });
}

export function startZiyarahOffline() {
    const root = document.querySelector('[data-ziyarah-offline]');

    if (!root) {
        return;
    }

    const status = root.querySelector('[data-ziyarah-status]');
    const button = root.querySelector('[data-ziyarah-save]');

    if (!status || !button) {
        return;
    }

    if (!isSupported()) {
        // The server-rendered sentence already says to open the page on the
        // phone being taken; leaving it alone is more honest than offering
        // a button that cannot work.
        return;
    }

    const saved = readState();

    if (saved && saved.count) {
        const when = savedOnDate(saved.at);

        status.textContent = when
            ? `${saved.count} pages are on this phone, saved ${when}. Save again to pick up any changes.`
            : `${saved.count} pages are on this phone. Save again to pick up any changes.`;

        button.textContent = 'Save again';
    }

    button.classList.remove('hidden');

    button.addEventListener('click', async () => {
        button.disabled = true;
        status.textContent = 'Saving…';

        try {
            const response = await fetch(root.dataset.manifest, {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                throw new Error('manifest');
            }

            const manifest = await response.json();
            const urls = manifest.urls.concat(assetUrls());

            const active = await worker();

            if (!active) {
                throw new Error('no worker');
            }

            const result = await askWorkerToSave(active, urls);
            const pages = Math.min(result.saved, manifest.count + 1);

            if (result.failed > 0) {
                // Named, not hidden. Finding out in Makkah that three pages
                // are missing is the failure this whole feature exists to
                // prevent.
                status.textContent = `${pages} of ${manifest.count + 1} pages saved. ${result.failed} did not download — try again on a better connection.`;
            } else {
                status.textContent = `All ${manifest.count + 1} pages are on this phone now. They will open with no data.`;

                writeState({
                    version: manifest.version,
                    count: manifest.count + 1,
                    at: new Date().toISOString(),
                });
            }

            button.textContent = 'Save again';
        } catch (error) {
            status.textContent = 'That did not work. Check the connection and try again.';
        } finally {
            button.disabled = false;
        }
    });
}

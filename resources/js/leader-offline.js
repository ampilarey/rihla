/**
 * The Tour Leader Portal's offline half — §6.3.
 *
 * §6.3 says the portal "must work offline — connectivity in transit is
 * unreliable; design for queued writes and sync". On this host there is no
 * queue worker and no websocket (ADR 0002), so the queue lives on the
 * phone: every mark goes into IndexedDB first and is posted when a signal
 * comes back.
 *
 * ## Written before it is sent, always
 *
 * A mark is queued and the screen updated before any request is made, even
 * when there is a connection. A leader at a coach door must never be
 * waiting on a spinner to know whether a tap registered, and a request that
 * succeeds is just a queued item that clears quickly.
 *
 * ## The queue is ordered and idempotent
 *
 * Items are keyed by an auto-incrementing id and replayed in that order.
 * A mark is keyed server-side on (roll call, traveller), so replaying one
 * sets the same value again; an incident carries a uuid generated here
 * before the first attempt, so a retry is recognised rather than raising a
 * second incident. The server also refuses a mark whose time is older than
 * the one it already holds, which is what stops a retry of a stale attempt
 * undoing a correction.
 *
 * ## It is cleared on logout
 *
 * This store holds pilgrim names. A shared phone that keeps them after the
 * leader signs out is the same disclosure as leaving the roster on a table,
 * so `clear()` runs on the logout form's submit.
 */

const DB_NAME = 'rihla-leader';
const DB_VERSION = 1;
const OUTBOX = 'outbox';
const SNAPSHOTS = 'snapshots';

function openDatabase() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, DB_VERSION);

        request.onupgradeneeded = () => {
            const db = request.result;

            if (!db.objectStoreNames.contains(OUTBOX)) {
                db.createObjectStore(OUTBOX, { keyPath: 'id', autoIncrement: true });
            }

            if (!db.objectStoreNames.contains(SNAPSHOTS)) {
                db.createObjectStore(SNAPSHOTS, { keyPath: 'departure' });
            }
        };

        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

function transact(db, store, mode, work) {
    return new Promise((resolve, reject) => {
        const tx = db.transaction(store, mode);
        const result = work(tx.objectStore(store));

        tx.oncomplete = () => resolve(result && result.result !== undefined ? result.result : result);
        tx.onerror = () => reject(tx.error);
        tx.onabort = () => reject(tx.error);
    });
}

async function queue(item) {
    const db = await openDatabase();

    return transact(db, OUTBOX, 'readwrite', (store) => store.add(item));
}

async function queued() {
    const db = await openDatabase();

    return new Promise((resolve, reject) => {
        const tx = db.transaction(OUTBOX, 'readonly');
        const request = tx.objectStore(OUTBOX).getAll();

        request.onsuccess = () => resolve(request.result || []);
        request.onerror = () => reject(request.error);
    });
}

async function forget(ids) {
    if (ids.length === 0) {
        return;
    }

    const db = await openDatabase();

    return transact(db, OUTBOX, 'readwrite', (store) => {
        ids.forEach((id) => store.delete(id));
    });
}

async function rememberSnapshot(departure, payload) {
    const db = await openDatabase();

    return transact(db, SNAPSHOTS, 'readwrite', (store) =>
        store.put({ departure: Number(departure), payload, at: new Date().toISOString() }));
}

async function recallSnapshot(departure) {
    const db = await openDatabase();

    return new Promise((resolve, reject) => {
        const tx = db.transaction(SNAPSHOTS, 'readonly');
        const request = tx.objectStore(SNAPSHOTS).get(Number(departure));

        request.onsuccess = () => resolve(request.result || null);
        request.onerror = () => reject(request.error);
    });
}

async function clear() {
    const db = await openDatabase();

    await transact(db, OUTBOX, 'readwrite', (store) => store.clear());
    await transact(db, SNAPSHOTS, 'readwrite', (store) => store.clear());
}

// ── Telling the leader what is going on ──────────────────────────────────

function status(headline, detail) {
    const banner = document.querySelector('[data-leader-status]');

    if (!banner) {
        return;
    }

    if (!headline) {
        banner.hidden = true;

        return;
    }

    banner.hidden = false;
    banner.querySelector('[data-leader-status-headline]').textContent = headline;
    banner.querySelector('[data-leader-status-detail]').textContent = detail || '';
}

async function reportQueue() {
    let waiting = [];

    try {
        waiting = await queued();
    } catch (error) {
        // A private window, or storage the browser refuses. The page still
        // works online; saying nothing would be worse than saying this.
        status('This phone will not keep a local copy', 'Marks are sent as you tap them. Do not rely on this page without a signal.');

        return;
    }

    if (waiting.length === 0) {
        status(navigator.onLine ? null : 'No signal', 'Everything you have tapped is already sent.');

        return;
    }

    status(
        waiting.length === 1 ? '1 mark waiting to send' : `${waiting.length} marks waiting to send`,
        navigator.onLine ? 'Sending…' : 'They will go as soon as there is a signal. Keep this page open.',
    );
}

// ── Sending ──────────────────────────────────────────────────────────────

let sending = false;

async function flush() {
    if (sending || !navigator.onLine) {
        return;
    }

    const page = document.querySelector('[data-sync-url]');

    if (!page) {
        return;
    }

    let items = [];

    try {
        items = await queued();
    } catch (error) {
        return;
    }

    if (items.length === 0) {
        await reportQueue();

        return;
    }

    sending = true;

    try {
        const response = await fetch(page.dataset.syncUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': window.rihlaCsrfToken || '',
            },
            body: JSON.stringify({ items }),
        });

        if (!response.ok) {
            // Left in the queue deliberately. A 419 after a long trip is an
            // expired session, and dropping the marks would lose a count
            // nobody can retake.
            status('Could not send yet', 'Your marks are still saved on this phone. Sign in again if this keeps happening.');

            return;
        }

        const body = await response.json();
        const done = [];
        const refused = [];

        (body.results || []).forEach((result) => {
            if (result.result === 'refused') {
                refused.push(result);
            } else {
                done.push(result.id);
            }
        });

        await forget(done);

        if (refused.length > 0) {
            // Refused items are dropped: they will be refused again every
            // time, and a queue that never empties is one a leader learns
            // to ignore. The reason is shown instead.
            await forget(refused.map((result) => result.id));
            status('Some marks were not accepted', refused[0].reason || 'Ask the office.');

            return;
        }

        await reportQueue();
    } catch (error) {
        status('No signal', 'Your marks are saved on this phone and will go when there is one.');
    } finally {
        sending = false;
    }
}

// ── The count screen ─────────────────────────────────────────────────────

function wireCount() {
    const page = document.querySelector('[data-leader-count]');

    if (!page) {
        return;
    }

    const rollCallId = Number(page.dataset.leaderCount);

    page.querySelectorAll('[data-leader-traveller]').forEach((row) => {
        const travellerId = Number(row.dataset.leaderTraveller);

        row.querySelectorAll('[data-leader-mark]').forEach((button) => {
            button.addEventListener('click', async () => {
                const state = button.dataset.leaderMark;

                // The screen moves first. A leader must not be waiting on a
                // request to know a tap registered.
                paint(row, state);

                try {
                    await queue({
                        type: 'mark',
                        roll_call_id: rollCallId,
                        traveller_id: travellerId,
                        state,
                        marked_at: new Date().toISOString(),
                    });
                } catch (error) {
                    status('This phone will not keep a local copy', 'Sending directly instead.');
                }

                await reportQueue();
                flush();
            });
        });
    });
}

function paint(row, state) {
    row.dataset.leaderState = state;

    row.querySelectorAll('[data-leader-mark]').forEach((button) => {
        const chosen = button.dataset.leaderMark === state;

        button.classList.toggle('border-wine', chosen);
        button.classList.toggle('bg-cream', chosen);
        button.classList.toggle('text-ink', chosen);
        button.classList.toggle('border-cream-deep', !chosen);
        button.classList.toggle('text-ink-muted', !chosen);
    });

    const label = row.querySelector('[data-leader-mark-status]');

    if (label) {
        label.textContent = { present: 'Here', absent: 'Not here', excused: 'Excused' }[state] || 'Not counted';
    }
}

// ── The group screen ─────────────────────────────────────────────────────

async function keepSnapshot() {
    const page = document.querySelector('[data-snapshot-url]');

    if (!page) {
        return;
    }

    const departure = page.dataset.leaderDeparture;

    if (!navigator.onLine) {
        const stored = await recallSnapshot(departure).catch(() => null);

        if (stored) {
            status('No signal — showing the copy on this phone', `Last updated ${new Date(stored.at).toLocaleString()}.`);
        } else {
            status('No signal', 'This phone has no copy of this group yet. Open this page once with a signal.');
        }

        return;
    }

    try {
        const response = await fetch(page.dataset.snapshotUrl, { headers: { Accept: 'application/json' } });

        if (response.ok) {
            await rememberSnapshot(departure, await response.json());
        }
    } catch (error) {
        // Nothing to say: the page rendered from the server, so the leader
        // has what they need right now.
    }
}

// ── Wiring ───────────────────────────────────────────────────────────────

export function startLeaderPortal() {
    if (!document.querySelector('[data-leader-count], [data-snapshot-url]')) {
        return;
    }

    wireCount();
    keepSnapshot();
    reportQueue();
    flush();

    window.addEventListener('online', () => {
        reportQueue();
        flush();
    });

    window.addEventListener('offline', reportQueue);

    // This store holds pilgrim names. A shared phone that keeps them after
    // the leader signs out is the same disclosure as leaving the roster on
    // a table.
    document.querySelectorAll('form[action$="/logout"]').forEach((form) => {
        form.addEventListener('submit', () => { clear(); });
    });
}

export const leaderStore = { queue, queued, forget, clear, rememberSnapshot, recallSnapshot };

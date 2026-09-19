/**
 * Behaviour that used to live in onclick and onsubmit attributes.
 *
 * Thirty-six of them were spread across fourteen views. Two reasons to move
 * them out, in the order they matter:
 *
 * 1. They cannot survive a Content-Security-Policy. A nonce lets the browser
 *    trust a <script> block this application wrote; it cannot vouch for code
 *    living in an attribute, so a nonce-based policy blocks every one. They
 *    were the only thing standing between this site and a real policy.
 *
 * 2. Interpolating values into them is an escaping trap. The media delete
 *    button built a JavaScript string literal out of a media title inside an
 *    HTML attribute — three layers of quoting — so a title containing an
 *    apostrophe broke the handler. A broken `return confirm(...)` does not
 *    cancel anything: the button stayed a submit button, and the form went
 *    through without asking. A destructive action lost its guard on exactly
 *    the titles most likely to be written by a person.
 *
 * Arguments now travel as JSON in a data attribute, where Blade's own
 * escaping is correct and an apostrophe is just an apostrophe.
 */

/**
 * `data-click="fnName"` calls window.fnName.
 *
 * With `data-args` (a JSON array) it is called with those arguments; without,
 * it is called with the element, which is what `onclick="fn(this)"` did.
 *
 * The functions themselves still live in the page's own <script> block, close
 * to the markup they drive. Moving them into the bundle is a separate job and
 * would put admin-only code on every public page.
 */
document.addEventListener('click', (event) => {
    const el = event.target.closest('[data-click]');

    if (! el) {
        return;
    }

    const fn = window[el.dataset.click];

    if (typeof fn !== 'function') {
        console.warn(`No handler named ${el.dataset.click}`);

        return;
    }

    let args = [el];

    if (el.dataset.args !== undefined) {
        try {
            args = JSON.parse(el.dataset.args);
        } catch (error) {
            console.error(`Bad data-args on ${el.dataset.click}`, error);

            return;
        }
    }

    // A handler that returns false cancels, the way `onclick="return false"`
    // did. Anything else — including undefined — lets the event continue.
    if (fn(...args) === false) {
        event.preventDefault();
    }
});

/**
 * `data-confirm="..."` asks before the form submits.
 *
 * On the form rather than the button, because that is where the decision
 * belongs: a form submitted by pressing Enter in a text field never fires the
 * button's click handler, and the old attributes on buttons let that through
 * unasked.
 */
document.addEventListener('submit', (event) => {
    const message = event.target.dataset?.confirm;

    if (message && ! window.confirm(message)) {
        event.preventDefault();
    }
});

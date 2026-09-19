# 3. Filament for new admin modules, at /staff

- **Status:** accepted
- **Date:** 2026-09-19
- **Context:** §9.2 of [`WEBSITE_UPGRADE_PLAN.md`](../WEBSITE_UPGRADE_PLAN.md)
- **Decided by:** the owner, asked directly — *"adopt Filament, new modules first"*

## The decision

Filament v5 is installed and serves a panel at **`/staff`**. New admin modules
are built there. The hand-rolled Blade panel keeps `/admin` and the six models
it already manages — trips, media, guide steps, hero banners, the why-section
and settings — and moves across in Phase 3, screen by screen, not in one
rewrite.

Two panels is the cost of not doing a big-bang migration. The Blade panel's
navigation links to the new one so staff can find it.

## What that bought, and what it cost

Filament brings **32 packages**, including Livewire. On shared hosting every
package is deploy weight and every upgrade is a `composer update` run by hand.
That is the price of not writing CRUD by hand for the 25+ models the plan adds.

Three things had to be dealt with before it worked here, none of which a
tutorial mentions.

### The panel cannot have the site's Content-Security-Policy

The site's `script-src` is nonce-based. Filament and Livewire emit four inline
`<script>` blocks between them and only Livewire's can carry a nonce —
Filament's `assets.blade.php` writes `window.filamentData` with no hook to
attach one. Under the nonce policy the browser refuses all four, and the panel
renders but does nothing.

So `script-src` on `/staff` is `'self' 'unsafe-inline' 'unsafe-eval'`, and
every other URL keeps the strict policy. A nonce and `'unsafe-inline'` in the
same directive is not belt-and-braces — a browser that understands nonces
ignores `'unsafe-inline'` — so the panel's policy carries no nonce at all.

This is a real weakening, and it is confined to URLs behind authentication and
the `admin.access` permission. The public site, which is where an injection
would come from a stranger, is unchanged. `StaffPanelTest` asserts both halves,
and a third test fails if a future Filament starts nonce-ing its inline
scripts — at which point the exception can go.

The alternative is publishing and patching Filament's views on every upgrade.

### The assets have to be committed

There is no Node and no build step on the server, and the deploy is a
`git pull` — see [ADR 0002](0002-stay-on-cpanel-shared-hosting.md).
`filament:install` had added `public/js/filament`, `public/css/filament` and
`public/fonts/filament` to `.gitignore`, which would have shipped a panel with
no styles and no JavaScript. They are committed, exactly like `public/build`.

`php artisan filament:upgrade` republishes them after every `composer update`,
and a test fails when a committed asset no longer matches anything the
installed packages ship — because stale assets are silent, and mean last
version's JavaScript against this version's markup.

### It resolved a dependency the project cannot run

`filament/support` accepts `symfony/html-sanitizer ^7.0|^8.0`. Composer picked
v8.1.7, which requires **PHP >= 8.4.1** — fine on the machine that ran the
install, and impossible to install on PHP 8.3, which `composer.json` declares
as the floor and which CI tests. The lock was not installable on a host the
project claims to support.

The fix is not to pin that one package. `composer.json` now sets
`config.platform.php` to `8.3.0`, so composer resolves every dependency for
the *oldest* PHP the project supports rather than the newest one a developer
happens to have. This class of bug was going to recur otherwise, and only CI
would have caught it — which it did, within a minute.

### It added two public routes

`filament/actions` registers `/filament/exports/{export}/download` and
`/filament/imports/{import}/failed-rows/download` outside the panel's
middleware. Without the tables they bind against they answered **500** to
anyone who guessed the URL; the route smoke test found them within a minute of
installing. Filament's `imports`, `exports` and `failed_import_rows`
migrations are published and run, so they behave. Export and import are also
features Rihla will plausibly want for pilgrim lists in Phase 3.

## Other departures from the generated panel

- **No second login form.** `filament:install` adds one at `/staff/login`; it
  is removed. One login means one place to get session handling, throttling
  and password resets right. A guest is sent to the existing `/login`.
- **Brand colours**, so the two panels do not look like two products.
- **Avatars are drawn locally.** Filament's default points an `<img>` at
  ui-avatars.com — a third-party request carrying a staff member's name on
  every page load, which `img-src` refuses anyway. `InitialsAvatarProvider`
  returns a `data:` SVG.
- **Panel access is a permission**, not merely being signed in. Every customer
  account Phase 3 introduces will be an authenticated user, and none of them
  may see this.

## Consequences

- Two admin URLs until Phase 3. Staff need to know both exist.
- `composer update` now needs `php artisan filament:upgrade` and a commit of
  the result. That belongs in the deploy documentation, not in someone's head.
- A Filament major upgrade is a real piece of work, not a version bump.

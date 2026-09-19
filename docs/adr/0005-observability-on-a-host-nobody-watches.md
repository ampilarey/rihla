# 5. Observability on a host nobody watches

- **Status:** accepted
- **Date:** 2026-09-19
- **Context:** §9.5 of [`WEBSITE_UPGRADE_PLAN.md`](../WEBSITE_UPGRADE_PLAN.md), "Laravel Pulse + Sentry; log levels and retention"
- **Constrained by** [ADR 0002](0002-stay-on-cpanel-shared-hosting.md)

## The decision

Laravel Pulse is installed, at `/pulse`, configured for a host with no Redis,
no queue worker and no long-running processes. The log rotates. Two of the
four things §9.5 asks for — Sentry and uptime monitoring — **are not done**,
because both need an account only the owner can open. What that leaves is
written down at the end rather than left implied.

## The honest problem

Pulse is a **pull** system: a dashboard someone has to open. The question that
actually matters for Rihla is a **push** one — *when the site breaks at two in
the morning, does anybody find out?* Today the answer is still no, and Pulse
does not change it.

That is not an argument against Pulse. It makes the second question —
*what went wrong, and when did it start?* — answerable, and before this the
answer lived in an unrotated log file nobody opened. But it should not be
mistaken for alerting, and §9.5's own list puts Sentry beside it for exactly
that reason.

## What was changed for this host

| Pulse's default | Here | Why |
|---|---|---|
| Redis ingest | `storage` (direct database write on terminate) | There is no Redis (ADR 0002). Writes happen after the response is sent, and Pulse wraps every one in its own `rescue()`, so a failure cannot take a page down. |
| `pulse:check` daemon feeding a Servers card | Recorder commented out, card removed from the dashboard | Nothing long-running can be kept alive on cPanel. A card that can never have data reads as "the server is down", not "nothing is watching". |
| `CacheInteractions` recorder on | **Off** | The cache driver here *is* the database, and the homepage caches a block. On, it writes a row to MySQL for every cache read from MySQL, on every anonymous page view, to report on the cache. It is also the only recorder that fires for a visitor who is not signed in. |
| Trim on a schedule | Trim on the ingest lottery (1 in 1,000) | There is no scheduler cron on this host. The `pulse_*` tables stay bounded without one. |
| Dashboard open in `local` only | `pulse.view` permission, via the `viewPulse` gate | Pulse's default denies on production, which is the right direction to fail; this replaces it with a real check rather than widening it. |

`pulse.view` is **in no role's set**, like `user.*`. Pulse shows the SQL of
slow queries, the file and line of every exception, and the names and email
addresses of whoever was signed in. That is an engineering surface, and no
job at Rihla needs it. Super Admin reaches it through `Gate::before`.

## Three things found by opening the page

None of these would have been caught by a test asserting `/pulse` returns 200,
because each card loads in its own Livewire request and fails inside it — the
page answers 200 throughout.

1. **Every card threw.** Laravel 13's `cache.serializable_classes` refuses to
   unserialize a class that is not allowlisted and hands back a
   `__PHP_Incomplete_Class`. Pulse caches each dashboard query for a few
   seconds and its queries return plain rows, so every card died on
   "the script tried to access a property on an incomplete object" — an error
   that names `unserialize()` and an autoloader and says nothing about the
   list it came from. `stdClass` is allowlisted now: it is the safest possible
   entry, because the list exists to stop a leaked `APP_KEY` becoming a gadget
   chain, and a class with no methods has nothing to chain.

2. **The dashboard was telling gravatar.com who works here.** Pulse's default
   avatar is `gravatar.com/avatar/<sha256 of the email address>` — a stable
   identifier for that person across every site using Gravatar, sent once per
   listed staff member, every time the page is open. It was found because the
   picture was *broken*: `img-src` allows `'self'`, `data:` and YouTube's
   thumbnail hosts only, so the browser refused it. The Content-Security-Policy
   was doing its job, and the torn image was the visible half of a privacy
   leak. Avatars are now drawn locally, reusing the same class that already
   fixed the identical default in Filament (`App\Support\InitialsAvatar`).

3. **The dashboard needed the script-src exception**, like the Filament panel:
   Pulse's layout writes an inline `<script>` this application does not render
   and cannot nonce. It is keyed to `config('pulse.path')` rather than a
   hard-coded `pulse`, so moving the dashboard moves the exception with it.

## Logging

`LOG_STACK` defaulted to `single`, which appends to one `laravel.log` forever.
On an account with a disk quota that ends with the site unable to write a
session or accept an upload, for a reason that looks nothing like a full disk.
The default is `daily` now, and `rihla:preflight --production` warns about an
unrotated channel, about `LOG_LEVEL=debug`, and about a `laravel.log` over
100 MB — because the repository being right does not fix a live `.env` copied
from an older example.

## What is not done, and needs the owner

Neither can be finished from the repository. Both are small once the account
exists.

1. **Error alerting (Sentry).** Needs an account and a DSN. Until then an
   exception is recorded in Pulse and written to the log, and nobody is told.
   This is the gap that matters most.
2. **Uptime monitoring.** Needs an external monitor — BetterStack or
   UptimeRobot, both free at this size — pointed at `https://rihla.mv/up`,
   which already exists and answers without touching the database. Alerts to
   WhatsApp or email. Pulse cannot do this: a dashboard served by the site
   cannot tell you the site is down.

## When to revisit

- Add the Sentry SDK the day there is a DSN.
- Turn `CacheInteractions` back on for a spell if cache behaviour is ever in
  question, then turn it off again.
- Restore the Servers recorder **and** its card together if this moves to a
  VPS, where `pulse:check` can run.
- If the `pulse_*` tables ever show up in the Slow Queries card, shorten
  `PULSE_STORAGE_KEEP` before reaching for anything else.

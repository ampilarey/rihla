# 2. Stay on cPanel shared hosting

- **Status:** accepted
- **Date:** 2026-09-19
- **Context:** §9.1 of [`WEBSITE_UPGRADE_PLAN.md`](../WEBSITE_UPGRADE_PLAN.md)
- **Decided by:** the owner, asked directly

## The decision

Rihla stays on cPanel shared hosting. The plan recommended moving to a VPS
before Phase 2; the owner chose to stay, and this records what that costs so
nobody has to rediscover it when a Phase 3 feature quietly fails.

## What already works there

The site is live on it. `rihla.mv` and `test.rihla.mv` both run PHP 8.4,
MySQL, a deploy script driven by `git pull`, and cron. `public/build` is
committed because there is no Node on the server, and that is fine — CI proves
the committed build matches the sources on every pull request.

## What it costs

| Wanted | On cPanel | Consequence |
|---|---|---|
| Queue workers | No long-running processes. `queue:work` cannot be kept alive; cron can only run `queue:work --stop-when-empty` on a schedule | A booking confirmation, a payment webhook retry or a PDF render happens up to a minute late, and nothing can be a long-running consumer |
| Redis | Not available | Cache and sessions are the database. Cache stampedes and lock contention become a database problem, and `Cache::lock()` is only as good as MySQL row locks |
| Websockets | No persistent connections | No live seat counts, no live payment status. Polling only |
| Horizon | Needs Redis and a supervisor | Queue observability is whatever the `jobs` and `failed_jobs` tables show |
| Scheduled work finer than a minute | Cron's floor is one minute | Anything needing seconds is out |
| Zero-downtime deploys | The document root is a symlink into one checkout | The site serves mid-deploy files for the length of a `composer install` |
| Horizontal scale | One account, one machine | The ceiling is whatever that machine does |

The two that matter soonest are **queues** and **Redis**, and both arrive with
bookings and payments in Phase 3.

## What to do instead, for now

- **Queues**: use the `database` driver and drive it from cron with
  `queue:work --stop-when-empty --max-time=50`, once a minute. Jobs must be
  idempotent and must not assume they start promptly.
- **Cache and sessions**: the `database` driver, which is already what runs.
  Keep cached values small and their keys few — see `WhySection::CACHE_KEY`.
- **Anything live**: poll, and say so in the UI rather than implying real time.
- **Mail**: an external provider over SMTP, never the local sendmail.

## When to revisit

Move to a VPS when any one of these becomes true, not before:

1. A booking or payment flow needs a job to run within seconds of the request.
2. The database cache measurably contends under normal traffic.
3. Deploys during business hours start being felt by visitors.
4. Two people need to work on the server at once without stepping on each
   other.

## Consequences for the plan

§12's deployment section and Phase 3's booking engine are written assuming a
worker. They need the cron-driven variant above, and the plan's estimates for
Phase 3 should carry the extra work of making every job safe to run late and
twice.

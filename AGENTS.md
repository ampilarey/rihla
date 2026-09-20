# AGENTS.md

## Cursor Cloud specific instructions

Rihla Travels is a Laravel 13 (PHP 8.3+) travel website using TailwindCSS + Vite + Alpine.js on the frontend. It defaults to a SQLite database (`database/database.sqlite`). There is a single deployable web app (public site + `/admin` panel), so no multi-service orchestration is needed.

### Services / how to run
- App server: `php artisan serve --host=0.0.0.0 --port=8000` (serves both the public site and `/admin`).
- Frontend dev (hot reload): `npm run dev -- --host 127.0.0.1`. Bind to `127.0.0.1` — the default dev server writes `public/hot` with an IPv6 `http://[::1]:5173` URL, which some browsers fail to load assets from. Alternatively run `npm run build` once to generate `public/build/manifest.json` and skip the Vite dev server.
- Lint: `composer lint:php` (Pint, check-only) / `composer fix:php` (apply). Note: the existing codebase has many pre-existing Pint style violations, so `lint:php` reports failures on unmodified code.
- Static analysis: `composer analyse` (Larastan).
- Tests: `php artisan test` (PHPUnit; uses in-memory SQLite via `phpunit.xml`).

### Non-obvious gotchas
### What to give the owner after every merge and push

They asked for the command to paste into cPanel → Terminal to update
production. Include it in the reply that reports a merge, every time.

```
cd /home/rihla/rihla.mv-app && DRY_RUN=1 ./scripts/deploy-production.sh
```
```
cd /home/rihla/rihla.mv-app && ./scripts/deploy-production.sh
```

Dry run first, then the real one. The script fetches `origin/main` itself, so
nothing needs doing beforehand. It takes a verified database backup, enters
maintenance mode, fast-forwards, installs, migrates, rebuilds the caches and
smoke-tests the site — and refuses to start if preflight fails.

| | |
|---|---|
| Production | https://rihla.mv |
| Test — what a merge actually updates | https://test.rihla.mv |
| Production health, with the running commit | https://rihla.mv/up |

**Say plainly which of the two a merge touched.** Merging to `main` deploys to
**test only**. `rihla.mv` is never deployed automatically. Handing over a
production link after a merge, without that sentence, reads as "this is live",
and it is not.

The cPanel/StackCP control-panel URL is **not recorded here** because nobody
has given it. Ask rather than guessing one: a wrong `:2083` or StackCP link is
the same class of defect as the invented social links and the `PLxxxxxxxxxx`
playlist that reached the live site.

### TEST auto-deploy (like Bake & Grill)
- **After CI passes** on `main` (not on the push itself), GitHub Actions calls `POST https://test.rihla.mv/api/deploy/test-pull` (see `.github/workflows/deploy-test-immediate.yml`). A red CI run deploys nothing and says so.
- The workflow then polls `GET /api/health` with the deploy secret until it reports the commit it just deployed. A 202 from the webhook only means the pull *started*.
- Server script: `scripts/pull-deploy-test.sh` (path `/home/rihla/test.rihla.mv`). Production is never auto-deployed.
- Setup: `docs/TEST_AUTO_DEPLOY.md`. Requires GitHub environment `test` secret `TEST_DEPLOY_WEBHOOK_SECRET` and the same value in TEST `.env`. Cron fallback: `scripts/install-self-update-cron-test.sh`.
- Webhook needs public DNS for `test.rihla.mv`; cron works on-server without it.

- Blade layouts use `@vite`, so views require either the Vite dev server running OR a prior `npm run build`. Without one of these, page rendering (and any feature test that renders a view) throws "Vite manifest not found".
- Admin users are NOT seeded. Create one with `php artisan admin:create <email> <password>` (sets `is_admin=true`). `/admin` is gated by an admin check and redirects to `/login` when unauthenticated.
- `php artisan migrate --seed` seeds trips, media, settings, and the Umrah guide — needed for the homepage, which queries the `trips` table.
- Laravel 13 sets `cache.serializable_classes` restrictively. The homepage caches Eloquent `WhySection`/`WhyFeature` models (`HomeController`); those classes (plus Collection/Carbon and `stdClass`) are allowlisted in `config/cache.php`. After upgrading or changing that list, run `php artisan cache:clear` (and clear the `cache` DB table if using the database cache driver). **A class left off the list does not fail loudly:** PHP returns a `__PHP_Incomplete_Class` and the first property read throws "the script tried to access a property on an incomplete object", which names `unserialize()` and an autoloader and never mentions this list. `stdClass` had to be added for Pulse, whose dashboard cards each cache a collection of plain rows.
- **Tailwind utility classes do nothing inside `/staff`.** The Filament panel has no custom theme, so it serves `public/css/filament/filament/app.css`, which carries Filament's own `fi-*` classes and no utilities at all — `rounded-lg`, `text-sm`, `space-y-4`, `text-amber-600` are all absent. A Blade view written for a Filament modal or custom page renders as unstyled running text, and **no test can see it**: the markup is there and only the styling is missing. Use Filament's Blade components (`x-filament::callout`, `x-filament::section`, `x-filament::badge`). Note that `x-filament::callout` renders `heading`, `description` and `footer` and **ignores its slot**, so body text written between the tags disappears silently. **The same trap applies to every named slot:** Blade discards an `<x-slot name="…">` the component does not declare, without a word. `x-filament::section` reads `afterHeader`, not `headerEnd` — a whole row of severity badges rendered as nothing on the departure board, the page still answered 200, and every other assertion still passed. Check the component's `@props` in `vendor/filament/support/resources/views/components/`.
- **Static analysis runs here. Run `composer analyse` before every push.** This file used to say PHPStan was CI-only and could not run locally; that was wrong, and it cost three CI runs in one afternoon before anybody tried. The 5.7 GB figure came from a *source* install. Larastan is already in `vendor/`; the only missing piece is the `phpstan/phpstan` binary, whose dist is a GitHub zipball an unauthenticated composer cannot fetch. The released phar is 28 MB and downloads fine: run `./scripts/install-phpstan-locally.sh` once per sandbox, then `composer analyse` works unchanged. Nothing is committed — `vendor/` is gitignored.
- **Dropping a constrained, indexed column: `dropForeign`, then `dropIndex`, then `dropColumn` — in that order, each in its own `Schema::table()` call.** Both halves of the order come from a different engine, so neither can be worked out from one of them. **MySQL forces the foreign key to go first:** InnoDB requires an index on a constrained column, so dropping the index while the constraint stands is refused with errno 1553, *"Cannot drop index … needed in a foreign key constraint"*. **SQLite forces both to go before the column:** it rebuilds the whole table and the rebuild fails on anything still naming the departing column — *"error in index … after drop column"*, or *"unknown column … in foreign key definition"* from the constraint it keeps in the table definition. `dropConstrainedForeignId()` bundles the constraint and the column into one blueprint and leaves indexes alone, so it cannot express this at all. **This is the one thing here that SQLite cannot check for you:** index-first passed `php artisan test` locally and every SQLite job in CI, and took down the *entire* MySQL suite — every test class, because `RefreshDatabase` re-migrates per class and the failure is in none of the tests. A local MySQL is 90 seconds away (`apt-get install mariadb-server`, `mysqld_safe &`, then `DB_CONNECTION=mysql … php artisan test`); run it whenever a migration touches an index or a foreign key. Getting the order wrong also half-applies the migration and leaves it unrecorded, so the next run fails on a duplicate column instead of the real cause — always diagnose with `migrate:fresh`, never against a database a failed attempt has already touched.

- **A new table with a foreign key into `travellers` or `bookings` has to be added to `PackageDepartureTest::DEPENDENT_MIGRATIONS`.** That constant is an explicit, ordered list of every migration that must roll back before the booking domain can. Leave a new one out and the test fails **on MySQL only**, with *"Cannot delete or update a parent row"* against `drop table travellers` — SQLite never notices. The same local MySQL that catches the index-ordering trap above catches this one.

- **Larastan needs the generic on every relation.** A `HasMany` with no `@return HasMany<Child, $this>`, or a `BelongsTo` with no `@return BelongsTo<Parent, $this>`, types the result as `Model`, so every closure taking the real class is an `argument.type` error and any method returning it is a `return.type` error. `php artisan test` cannot see any of it, because at runtime the objects are exactly what the closures expect. The same applies to a model scope called on a bare `Illuminate\Database\Eloquent\Builder`: an arrow function cannot carry a docblock, so the scope has to move into a named method with `@param Builder<Model>`. Declare the generic when you write the relation, not when CI tells you — and fix the whole model rather than the two lines reported, because the next method to touch the relation fails the same way.

- **A Filament relation manager is lazy.** It loads in its own Livewire request, so the host page's HTML contains no relation-manager table. Fetching the page and asserting 200 — or asserting `assertSee` on it — proves nothing about the table; test the relation-manager component directly (`Livewire::test(XRelationManager::class, ['ownerRecord' => …, 'pageClass' => …])`). The same applies to a relation manager's action modals, which are rendered by the **page**, not by the relation manager: mounting the action on the relation-manager component yields HTML with no modal in it. **In a real browser it is lazy in the other sense too:** it loads on *intersection*, so it sits at "Loading…" until something scrolls it into view — a full-page Playwright screenshot does not, so `scrollTo(0, document.body.scrollHeight)` before asserting, or a working relation manager reads as a broken one.
- **Model factories run unguarded; the admin forms do not.** `Factory::make()` wraps instantiation in `Model::unguarded()`, so a column missing from `$fillable` is set happily in every test and dropped silently by `update()` from a Filament form. A new column needs a test that writes it through the guarded path.
- **Laravel Pulse is at `/pulse`**, gated by the `pulse.view` permission (no role holds it; Super Admin reaches it through `Gate::before`). It is configured for cPanel: no `pulse:check`, no Servers card, `CacheInteractions` off, trimming on the ingest lottery. See `docs/adr/0005-observability-on-a-host-nobody-watches.md`. Its cards load in their own Livewire requests, **so a card can throw while the page still answers 200** — checking the status code proves nothing. Open it, or read `storage/logs`.
- Production must run **PHP 8.3+** (Laravel 13 requirement). Confirm cPanel/StackCP PHP version before deploying.
- **There are no expected test failures.** `php artisan test` is green; treat any failure as real. (This line used to list the Breeze `Auth`/`Profile` tests and `ExampleTest` as acceptable failures. They were fixed — `/profile` is routed and all of them pass — but the note stayed, which is how a real failure gets waved through.)
- **Playwright's `setOffline` does not stop the service worker's own fetches.** A page checked this way loaded fine "offline" because the worker went to the network as usual and the test read as a pass. The only honest offline check in this sandbox is to **stop `php artisan serve`** and navigate afterwards; do that before believing any claim that something works without data. Related: a cache holding what the *user* asked to save must not be version-keyed with the shell cache, or the next deploy deletes the guide a pilgrim saved the night before they flew.

- **Headless Chrome cannot load the live sites from an agent sandbox.** Outbound HTTPS goes through the agent proxy, and this Chromium does not trust its CA, so `chrome --dump-dom https://test.rihla.mv/...` silently returns a `<title>Privacy error</title>` interstitial — it looks like a successful fetch and reports zero console errors, because no page ever loaded. Browser-verify against `php artisan serve` on `127.0.0.1`, and use `curl` (which does trust the CA) for anything live. A "no violations" result from Chrome against an https URL here means nothing.
- **Dhivehi is partly machine-generated and being removed rather than trusted.** Fabricated entries were found in `resources/lang/dv/` and in the seeded Umrah guide steps, always with the same signature: many unrelated English strings mapping to one identical Dhivehi string. `TranslationQualityTest` fails if that pattern returns. Do not paraphrase religious text to fill a gap — leave it to fall back to English.

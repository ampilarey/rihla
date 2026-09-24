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
- **A relation manager on a `ViewRecord` page is read-only, silently.** Filament's `isReadOnly()` returns true for any relation manager whose `pageClass` is a view page, so its Create and Delete actions simply do not render — no error, no warning, and a test asserting the page loads still passes. `Livewire::test(...)->callTableAction('create', ...)` fails with *"action with name [create] is visible"*, which reads like a permission problem and is not one. Override `public function isReadOnly(): bool { return false; }` on that relation manager, and keep the real permission check in `canCreate()`/`canDelete()`.

- **Model factories run unguarded; the admin forms do not.** `Factory::make()` wraps instantiation in `Model::unguarded()`, so a column missing from `$fillable` is set happily in every test and dropped silently by `update()` from a Filament form. A new column needs a test that writes it through the guarded path.
- **Laravel Pulse is at `/pulse`**, gated by the `pulse.view` permission (no role holds it; Super Admin reaches it through `Gate::before`). It is configured for cPanel: no `pulse:check`, no Servers card, `CacheInteractions` off, trimming on the ingest lottery. See `docs/adr/0005-observability-on-a-host-nobody-watches.md`. Its cards load in their own Livewire requests, **so a card can throw while the page still answers 200** — checking the status code proves nothing. Open it, or read `storage/logs`.
- Production must run **PHP 8.3+** (Laravel 13 requirement). Confirm cPanel/StackCP PHP version before deploying.
- **A test file named after a domain probably already exists.** `tests/Feature/KnowledgeCentreTest.php` covered Phase 5.1's editorial standard — nineteen tests of the gate an article passes before it may be published. Writing a new file for the *reader's* pages under the obvious name silently replaced all of them, and the suite still went green because the replacement passed. Check with `ls tests/Feature/` before writing, and give a second suite on the same domain a name that says which half it covers (`KnowledgeCentrePagesTest`).

- **A colour class for a palette with no `DEFAULT` compiles to nothing, and nothing looks like styling that merely did not apply.** `ink`, `cream`, `error`, `success` and `warning` all had one, so `text-ink` and `bg-cream` worked everywhere and everybody wrote the bare form; `wine` and `gold` did not, so `bg-wine`, `text-wine`, `border-s-wine` and `border-s-gold` produced **no rule at all**. The element renders, the markup is right, every assertion passes, and `bg-wine px-3 py-1 text-white` is white text on no background — which was live on the tour leader's head count ("3 missing") and the Family Portal's "Not yet counted". Both palettes now carry a `DEFAULT` and `BrandColourTest` fails if any view again names one that does not. **`grep` the built CSS before believing a class exists**: `grep -c '\.bg-wine{' public/build/assets/app-*.css`.

- **A palette has more than one source of truth, and the extra ones go stale silently.** The
Tailwind tokens are not the palette; they are one copy of it. `app/Support/Brand.php` holds
literals for hero banners and the Filament panel, `public/offline.html` and
`resources/views/errors/layout.blade.php` each carry a private stylesheet with no access to
either, the two PDF templates carry a third and fourth, `public/manifest.json` a fifth, and the
logo SVGs a sixth. A token swap reaches the first two and nothing else. **`BrandColourTest::RETIRED`
is the mechanism that catches the rest — so the first step of any palette change is adding the
outgoing values to that list and watching it fail.** Skipping that is how `offline.html` served
the previous brand in full, and how `#DBD3CE` and `#5B524D` survived in live views. The list also
has to scan `public/`, not just `resources/views`, or the standalone pages stay invisible to it.

- **A migration must never write a constant.** `2026_09_18_170000_rebrand_stored_banner_colours`
rewrote stored colours to `Brand::WINE`. It ran on test and production while that held the old
wine, stamping the rows *and the live column default*; the next palette change moved the constant
and reached neither, so the deployed homepage would have rendered its call-to-action in the
retired brand. A migration records what happened on the day it ran — point it at a constant and
its meaning moves afterwards. **Literals on both sides, always.** Note the test that missed it:
`migrate:fresh` in CI re-runs the old migration with the *current* constants, so a fresh-database
assertion never sees the state a deployed database is actually in. Testing a data migration means
seeding the old state and calling `up()` on it.

- **A service worker caching a path that outlives its contents serves last year's artwork forever.**
`public/sw.js` routed everything under `/images/`, `/fonts/` and `/js/` through a cache-first
handler with no revalidation. Those paths do not change when their bytes do — `rihla-mark-inverse.svg`
is the same URL before and after a rebrand — so every phone that had ever loaded the site kept
whatever logo it saw first, and the only thing that could clear it was bumping `VERSION` by hand,
which nobody did through an entire palette change. The stylesheet beside it updated normally,
because Vite renames a build asset whenever its contents change. **The visible symptom is the new
palette wrapped around the old logo, on returning devices only** — a fresh browser, CI, and every
screenshot taken here all look correct, which is why it reached the owner's phone rather than a
test. Cache-first is only ever safe for content-hashed URLs; anything else needs
stale-while-revalidate, with `event.waitUntil` on the revalidation or the worker may be killed
before it writes. **No PHP test can see this** — the guards in `PwaTest` check the routing, not the
behaviour. The honest check is the experiment: load the page in a real browser, let the worker
install, change a file under `public/images/`, reload, and look at what the page receives. Before
the fix it was the old bytes on every reload.

- **`filesize() > 0` is not a guard, and a raster is the one thing a palette change cannot reach.**
`favicon.ico` was the only icon still carrying the retired maroon brand — `#8E2653` sail,
`#D2A03C` sail, `#2E2621` hull, measured by decoding it, not guessed — through an entire
rebrand, because the only assertion `BrandMarkTest` ever made about it was that the file was
not empty. That passes for any bytes at all, including last year's artwork. Every *other* icon
was caught, because those tests read pixels. An `.ico` is a directory of images rather than
one image, so GD cannot open it; walk the 16-byte directory entries yourself and
`imagecreatefromstring` each PNG payload. The shape of the mistake generalises: when a guard
cannot see the thing it is named after, it reports green about something else.

- **A sameness assertion cannot catch a contrast bug.** `BrandColourTest` asserted the two logo
variants "differ only in the hull colour", on the stated belief that the sails held against
either ground. Measured, the wine sail was **1.8:1** against the ink footer and the gold sail
2.38:1 on white — both under the 3:1 a shape needs, both live for as long as the inverse mark
existed. The assertion compared the two files **to each other** and neither to its background, so
it could not have failed. When the property that matters is legibility, assert the ratio against
the surface, not the equality of two artefacts.

- **A `null` scrub strategy on a `NOT NULL` column throws, and takes the whole command with it.** `document_versions.path` is `NOT NULL`; `Anonymisation::SCRUB` mapped it to `null`. So `data:anonymise` — the command whose entire job is keeping real passport numbers off the public test server — died with an integrity-constraint violation on **any database holding a single document**, which is every real one. It shipped that way and stayed green because no test had ever stored a document. Use the `gone` stand-in for a file pointer that cannot be null, and note the shape of the mistake: **a fixture that never exercises the common case is not a passing test.**

- **dompdf does not shape Arabic, and the breakage is silent.** It reverses an RTL run for visual order but applies no contextual shaping, so every Arabic letter prints in its isolated form, joined to nothing — legible with effort and obviously wrong to anybody who reads Arabic. Nothing errors; the PDF downloads and looks plausible to someone who does not. **Measured, not assumed:** render the sheet and run `pdftotext file.pdf -`, then count codepoints — base letters are `U+0600`–`U+06FF`, shaped presentation forms are `U+FE70`–`U+FEFF`. A correct render is mostly presentation forms; ours came back **21 base, 0 shaped**. So the guesthouse fact sheet is offered in English and Dhivehi only (`StaysController::SHEET_LOCALES`), and a test pins that list with the reason. **Thaana is unaffected** — it does not join — which is why Dhivehi prints correctly once `A_Faruma` is declared for both weights, per the boxes trap above. The Arabic *web page* is fine: browsers shape properly, and it stays the shareable artefact for an Arabic reader until somebody adds a shaper.

- **A missing `messages.` key renders as the literal string `messages.Stays`, and `assertSee('Stays')` passes on it.** Phase 9.4 shipped thirty-four new keys with no English entry; every page test was green, because a substring assertion matches the key that failed to resolve. `TranslationTest::test_every_group_key_used_in_a_view_resolves_in_every_locale` exists to catch exactly this and did not, because its pattern read `[a-z0-9_.]+` after the group — **lowercase only** — while this codebase overwhelmingly uses sentence keys (`__('messages.Umrah Packages')`). The guard had been skipping almost every key on the site since it was written. The regex is widened now. Two lessons: assert on a *distinctive* phrase rather than one word when checking rendered copy, and when adding strings, check them against `resources/lang/en/messages.php` directly — `php -r "\$m=require 'resources/lang/en/messages.php'; var_dump(isset(\$m['Your key']));"` — rather than trusting a page test.

- **A foreign key gives a lock test a false pass, and the naive version of that test cannot tell.** Proving a row lock by "another session holds the row, so the allocator must throw" passes with `lockForUpdate()` deleted. InnoDB takes a **shared** lock on the parent row when writing a child row with a foreign key, so `StayAllocator` blocked on its `stays` UPDATE — a statement with nothing to do with the guarantee — threw, and looked correct. That incidental blocking does not scale to the case that matters: two allocators racing with no lock each take only a *shared* parent lock, shared locks are compatible with each other, and **both writes succeed**. The room is double-booked and nothing has thrown. So a lock test must assert **which statement blocked** (`$e->getSql()` naming the table and `for update`), not merely that something did. Only planting the defect found this — the test had already "passed" against the real code. Related, and the reason it matters more here than for seats: `departures` has a CHECK constraint under its lock, but no constraint can express the stays rule, which spans rows ("for every night, how many others overlap"). `StayLockTest` is the whole guarantee, not a confirmation of one.

- **A new table holding personal data has to be classified in *two* places, and the second one is the one you forget.** `Anonymisation::SCRUB` is the obvious half — `data:anonymise` refuses to run against a table nobody has classified, so it tells you. `Forgetting::REACHED` / `NOT_ONE_PERSONS` is the other half, and adding a table to `SCRUB` alone makes `Forgetting::unreached()` non-empty, which stops **`data:forget`** — the right-to-erasure command — with an exit code rather than a message about the table you just added. Adding `partners` cost fifteen `ForgetCustomerTest` failures that named a dry run and said nothing about guesthouses. Both lists are deliberate: `SCRUB` asks *"is this about a person?"*, `Forgetting` asks *"is it about **one** person?"*, and a supplier's contact record answers yes and no. Classify in both, in the same commit.

- **`./vendor/bin/pint` with no path argument reformats the whole tree.** This codebase carries many pre-existing style violations (CI only checks changed files), so a bare `pint` quietly adds a dozen unrelated files to the diff. Always name the files.

- **A Blade component tag needs `/>`.** `<x-thing ... >` is an *opening* tag and Blade then hunts for `</x-thing>`, failing with `syntax error, unexpected token "endforeach"` pointing at a line nowhere near the real one. Converting an `<img ...>` to `<x-stored-image ...>` is exactly where this bites.

- **`wasChanged()` is false on an insert.** Laravel populates `$changes` in `performUpdate` and not on create, so an observer with a single `saved()` that checks `wasChanged('cover_image')` silently misses **every first upload** — the common case. Use separate `created()` and `updated()` handlers.

- **Lighthouse gates CI now** (`lighthouse-budget.json`, `scripts/lighthouse-check.mjs`). Accessibility and SEO must be 100 on the homepage, packages list and guide; page weight is capped at 900 KB. Performance is reported and does not fail, because it is timing on a shared runner. Run it locally with `CHROME_PATH=/opt/pw-browsers/chromium npm run lighthouse -- http://127.0.0.1:8000`.

- **There are no expected test failures.** `php artisan test` is green; treat any failure as real. (This line used to list the Breeze `Auth`/`Profile` tests and `ExampleTest` as acceptable failures. They were fixed — `/profile` is routed and all of them pass — but the note stayed, which is how a real failure gets waved through.)
- **Playwright's `setOffline` does not stop the service worker's own fetches.** A page checked this way loaded fine "offline" because the worker went to the network as usual and the test read as a pass. The only honest offline check in this sandbox is to **stop `php artisan serve`** and navigate afterwards; do that before believing any claim that something works without data. Related: a cache holding what the *user* asked to save must not be version-keyed with the shell cache, or the next deploy deletes the guide a pilgrim saved the night before they flew.

- **Headless Chrome cannot load the live sites from an agent sandbox.** Outbound HTTPS goes through the agent proxy, and this Chromium does not trust its CA, so `chrome --dump-dom https://test.rihla.mv/...` silently returns a `<title>Privacy error</title>` interstitial — it looks like a successful fetch and reports zero console errors, because no page ever loaded. Browser-verify against `php artisan serve` on `127.0.0.1`, and use `curl` (which does trust the CA) for anything live. A "no violations" result from Chrome against an https URL here means nothing.
- **Dhivehi is partly machine-generated and being removed rather than trusted.** Fabricated entries were found in `resources/lang/dv/` and in the seeded Umrah guide steps, always with the same signature: many unrelated English strings mapping to one identical Dhivehi string. `TranslationQualityTest` fails if that pattern returns. Do not paraphrase religious text to fill a gap — leave it to fall back to English.

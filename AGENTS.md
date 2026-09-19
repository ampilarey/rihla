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
### TEST auto-deploy (like Bake & Grill)
- **After CI passes** on `main` (not on the push itself), GitHub Actions calls `POST https://test.rihla.mv/api/deploy/test-pull` (see `.github/workflows/deploy-test-immediate.yml`). A red CI run deploys nothing and says so.
- The workflow then polls `GET /api/health` with the deploy secret until it reports the commit it just deployed. A 202 from the webhook only means the pull *started*.
- Server script: `scripts/pull-deploy-test.sh` (path `/home/rihla/test.rihla.mv`). Production is never auto-deployed.
- Setup: `docs/TEST_AUTO_DEPLOY.md`. Requires GitHub environment `test` secret `TEST_DEPLOY_WEBHOOK_SECRET` and the same value in TEST `.env`. Cron fallback: `scripts/install-self-update-cron-test.sh`.
- Webhook needs public DNS for `test.rihla.mv`; cron works on-server without it.

- Blade layouts use `@vite`, so views require either the Vite dev server running OR a prior `npm run build`. Without one of these, page rendering (and any feature test that renders a view) throws "Vite manifest not found".
- Admin users are NOT seeded. Create one with `php artisan admin:create <email> <password>` (sets `is_admin=true`). `/admin` is gated by an admin check and redirects to `/login` when unauthenticated.
- `php artisan migrate --seed` seeds trips, media, settings, and the Umrah guide — needed for the homepage, which queries the `trips` table.
- Laravel 13 sets `cache.serializable_classes` restrictively. The homepage caches Eloquent `WhySection`/`WhyFeature` models (`HomeController`); those classes (plus Collection/Carbon) are allowlisted in `config/cache.php`. After upgrading or changing that list, run `php artisan cache:clear` (and clear the `cache` DB table if using the database cache driver).
- Production must run **PHP 8.3+** (Laravel 13 requirement). Confirm cPanel/StackCP PHP version before deploying.
- **There are no expected test failures.** `php artisan test` is green; treat any failure as real. (This line used to list the Breeze `Auth`/`Profile` tests and `ExampleTest` as acceptable failures. They were fixed — `/profile` is routed and all of them pass — but the note stayed, which is how a real failure gets waved through.)
- **Dhivehi is partly machine-generated and being removed rather than trusted.** Fabricated entries were found in `resources/lang/dv/` and in the seeded Umrah guide steps, always with the same signature: many unrelated English strings mapping to one identical Dhivehi string. `TranslationQualityTest` fails if that pattern returns. Do not paraphrase religious text to fill a gap — leave it to fall back to English.

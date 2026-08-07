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
- Blade layouts use `@vite`, so views require either the Vite dev server running OR a prior `npm run build`. Without one of these, page rendering (and any feature test that renders a view) throws "Vite manifest not found".
- Admin users are NOT seeded. Create one with `php artisan admin:create <email> <password>` (sets `is_admin=true`). `/admin` is gated by an admin check and redirects to `/login` when unauthenticated.
- `php artisan migrate --seed` seeds trips, media, settings, and the Umrah guide — needed for the homepage, which queries the `trips` table.
- Laravel 13 sets `cache.serializable_classes` restrictively. The homepage caches Eloquent `WhySection`/`WhyFeature` models (`HomeController`); those classes (plus Collection/Carbon) are allowlisted in `config/cache.php`. After upgrading or changing that list, run `php artisan cache:clear` (and clear the `cache` DB table if using the database cache driver).
- Production must run **PHP 8.3+** (Laravel 13 requirement). Confirm cPanel/StackCP PHP version before deploying.
- Pre-existing test failures unrelated to environment setup: the default Breeze `Auth`/`Profile` tests reference `dashboard`/`profile.edit` routes that this customized app removed (RouteNotFoundException), and `tests/Feature/ExampleTest.php` lacks `RefreshDatabase` so it hits a missing `trips` table. Tests that use `RefreshDatabase` and match the app's routes (e.g. `UmrahGuideTest`, most of `GuideTest`) pass.

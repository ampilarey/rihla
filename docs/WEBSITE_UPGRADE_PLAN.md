# Rihla Platform — Website Upgrade Plan

**Version:** 1.18
**Date:** 2026-09-18 (see revision history)
**Status:** Proposed — awaiting prioritisation decisions (see §13)
**Owner:** Rihla Travels (Reg. No. C11452023)
**Scope:** rihla.mv (production) and test.rihla.mv (staging)
**Companions:** [`BRAND.md`](BRAND.md) — the implemented colour system and the open logo decision. [`DOMAIN_MODEL_AND_BOOKING_ENGINE.md`](DOMAIN_MODEL_AND_BOOKING_ENGINE.md) — the detailed domain/booking-engine design that §5 and §8 depend on. Amendments R-1…R-8 from that document's §28.4 are applied here and marked **[R-n]**.

---

## How to read this document

This plan has three sources:

1. **The ChatGPT "Umrah Website Upgrade Ideas" thread** — 214 messages, 107 specification documents (`00-VISION` → `57-ENTERPRISE-REFERENCE-ARCHITECTURE`, appendices `A01`–`A33`, plus `META01`). Every one of those documents is accounted for in this plan; Appendix A maps each to a section here or records why it is deferred.
2. **An audit of the current codebase and the live sites** performed on 2026-09-17 (§1). Findings there are verified, not assumed.
3. **External research** into the 2026 Umrah market, Saudi regulatory reality, Maldivian payment/regulatory context, and Laravel tooling (§11, Appendix B).

The source thread is an *enterprise architecture manual*. It is excellent as a reference library and unusable as a build plan: it describes a 22-module platform with ~50 enterprise standards documents for what is today a 6-table brochure site run by a small Maldivian agency. **This plan keeps all of its ideas but re-sequences them by business value and cost**, and adds the things it missed — most importantly Nusuk compliance, which as of 2026 is not optional.

Sections §2–§10 are the plan. §12 is the phased roadmap with effort. If you read only one section, read §1 (what is broken now) and §12 (the sequence).

**Revision history**

| Version | Change |
|---|---|
| 1.18 | D44 fixed: every error status rendered Laravel's unbranded default page — no logo, no colour, no navigation, and English under `/dv/`. D45 found while fixing it: the exception handler never resolved the locale, because a URL that matches no route never reaches the web middleware group. Error page text is English in both locales and needs a translator. |
| 1.17 | D43 fixed: the Dhivehi guide PDF referenced a Thaana font that did not exist, so it downloaded as boxes. An alt-text finding was investigated and withdrawn — it was a false positive from a regex broken by Blade's `->`. |
| 1.16 | **Image upload was broken on every path** (D9, re-rated from Low to Critical) — Laravel 13 requires intervention/image ^4 and the project had ^2. Upgraded, and the call sites moved onto the framework's own `Image` facade. D42: filename collisions. Defect register corrected: D1–D8, D10, D12 and D13 were fixed during P0 but never struck through. |
| 1.15 | Write-path and schema tests added. D40 found by them: renaming a trip moved its public URL. D41: debug logging on every admin write, superseded by the audit log. |
| 1.14 | `RouteSmokeTest` added — it walks every GET route and found three more admin screens returning 500 on its first run (D37–D39), including the entire Umrah guide admin. |
| 1.13 | D18 fixed: the scholarly reference on each guide step is rendered on the page and in the PDF, and the admin form can finally edit it. |
| 1.12 | D36 added and fixed: security response headers, with CSP deliberately deferred rather than shipped permissive. |
| 1.11 | D35 added and fixed: rate limits on the five unthrottled auth endpoints, closing the mail vector that D32 opened. |
| 1.10 | D11 and D34 fixed — the database-held and inline-style colours finally migrated, and `App\Support\Brand` added so the next palette change cannot leave a component behind. D30–D33 recorded from the first CI runs. |
| 1.9 | Typography pass (`f30291b`). D26–D29 added and fixed — the headline one being that **Dhivehi never rendered in a Dhivehi font**, because the layout requested a Google font that does not exist while the real one sat unused in the repository. |
| 1.8 | Audit-log foundation (`2e81a34`). D24 and D25 added and fixed. TranslationTest's ratchet split by surface: 98 public / 120 admin, replacing one ceiling of 209 — the public number now binds more than twice as hard. |
| 1.7 | Authorisation replaced (`456abd6`) — roles, permissions and policies for the staff side, scoped to functionality that exists. D22 and D23 added and fixed: the base controller could not authorise at all, and four high-severity advisories sat in `league/commonmark`. |
| 1.6 | P0.6 implemented (`d9f60c9`) — **P0 is complete**. D19–D21 added and fixed: the layout rendered no styles/scripts stacks, the service worker could never install, and five referenced icons did not exist. |
| 1.5 | D14/D15 fixed (`86cbc7a`) — guide_steps migrated to the schema the application expects. D17 added and fixed (the fiqh-notes accessor shadowed its own cast). D18 added: `reference_text` is stored but never rendered. |
| 1.4 | P0.5 implemented. D14–D16 added: the Umrah guide admin is broken against its own schema (D14/D15), and registration is open (D16). Recorded the three places the implemented structured data deliberately differs from the plan. |
| 1.3 | P0.7 implemented. Recorded the four production defects the first CI run surfaced (`route('dashboard')` undefined, `ProfileController` unrouted, `<x-app-layout>` rendering blank, the guide API's locale guard never firing). |
| 1.2 | Colour system implemented (`488ffee`). D5 corrected — the Tailwind v4 package is unused, not a live conflict. D11–D13 added from the implementation pass. Phase 1's design-system item marked done, and §4.5 added recording the palette. |
| 1.1 | Re-audit. **Corrected D1/P0.1**: Laravel 13 falls back to `resources/lang` when it exists (`Illuminate\Foundation\Application::bindPathsInContainer`), so the directory location was *not* a bug — the dotless `__()` keys are the sole root cause. Filament recommendation moved from v4 to v5 (current). Package compatibility with Laravel 13 verified on Packagist. Roles and i18n aligned with the companion document. Added staging-PII rule, backup-before-migrate, email authentication, uptime monitoring, historical data import, and a waitlist entity. |
| 1.0 | Initial plan |

---

## Table of contents

- [1. Current state — audited](#1-current-state--audited)
- [2. Strategy: what Rihla should become](#2-strategy-what-rihla-should-become)
- [3. P0: production defects to fix first](#3-p0-production-defects-to-fix-first)
- [4. Workstream A — Public website & conversion](#4-workstream-a--public-website--conversion)
- [5. Workstream B — Booking engine & payments](#5-workstream-b--booking-engine--payments)
- [6. Workstream C — Pilgrim, Family, Tour Leader & Scholar portals](#6-workstream-c--pilgrim-family-tour-leader--scholar-portals)
- [7. Workstream D — Knowledge Centre, Ziyarah Guide & Learning Academy](#7-workstream-d--knowledge-centre-ziyarah-guide--learning-academy)
- [8. Workstream E — Back office: CRM, operations, finance](#8-workstream-e--back-office-crm-operations-finance)
- [9. Workstream F — Platform, architecture & delivery](#9-workstream-f--platform-architecture--delivery)
- [10. Non-functional requirements](#10-non-functional-requirements)
- [11. Integrations, vendors & costs](#11-integrations-vendors--costs)
- [12. Phased roadmap](#12-phased-roadmap)
- [13. Decisions needed from you](#13-decisions-needed-from-you)
- [14. Risks](#14-risks)
- [Appendix A — Coverage map of the 107 source documents](#appendix-a--coverage-map-of-the-107-source-documents)
- [Appendix B — Reference sites, APIs and reading](#appendix-b--reference-sites-apis-and-reading)
- [Appendix C — Proposed data model](#appendix-c--proposed-data-model)

---

## 1. Current state — audited

### 1.1 What exists

| Layer | Reality as of `da9b36d` |
|---|---|
| Framework | Laravel 13, PHP 8.3+, Blade + Alpine.js 3 + Tailwind, Vite 7 |
| Models (8) | `Trip`, `Media`, `Setting`, `GuideStep`, `HeroBanner`, `WhySection`, `WhyFeature`, `User` |
| Migrations | 19 |
| Blade views | 68 |
| Public routes | `/`, `/trips`, `/trips/{slug}`, `/gallery`, `/social`, `/contact`, `/guide`, `/guide/pdf`, `/api/guide-steps`, `/lang/{locale}` |
| Admin | Hand-rolled Blade CRUD at `/admin` for trips, media, guide steps, hero banners, why-sections, settings |
| Auth | Laravel Breeze; authorisation is a single `is_admin` boolean + `can:admin` gate |
| i18n | English + Dhivehi (Thaana, RTL), per-column `*_dv` fields on `trips` |
| Payments | **None** |
| Bookings | **None** |
| Hosting | cPanel shared hosting (`/home/rihla/test.rihla.mv`), MySQL/MariaDB, no Node on server — `public/build` is committed to git |
| Deploy | GitHub Actions → webhook → `scripts/pull-deploy-test.sh`; cron fallback; verified green 2026-09-17 |
| CI | **None** — no test/lint workflow exists |

### 1.2 Verified defects on the live site

These were confirmed by fetching `https://rihla.mv/` on 2026-09-17, not inferred.

| # | Severity | Finding | Evidence | Root cause |
|---|---|---|---|---|
| D1 | ~~**Critical**~~ **fixed** (`fc94a4e`) | The production homepage renders raw translation keys to visitors: `hero_title`, `hero_sub`, `cta_trips`, `cta_whatsapp`, `section_upcoming`, `section_memories`, `memories_sub`, `join_next_title`, `contact_whatsapp` | Live HTML of `rihla.mv` | All 21 calls are **dotless** — `__('hero_title')`. A dotless key is a *JSON* translation lookup (`lang/en.json`), and no JSON translation file exists anywhere in the repo; the strings live in PHP array files (`resources/lang/{en,dv}/messages.php`) and can only be reached as `__('messages.hero_title')`. So Laravel returns the key itself. *(v1.1 correction: the directory location is **not** a factor — Laravel 13 explicitly falls back to `resources/lang` when that directory exists; verified in `Illuminate\Foundation\Application`.)* |
| D2 | ~~**High**~~ **fixed** (`fc94a4e`) | Production is advertising demo seed data — "Maldives Island Hopping Adventure", "Luxury Resort Experience" — instead of Umrah packages, on a site whose `<title>` is "Islamic Travel & Umrah Services" | Live HTML | `TripSeeder` demo rows were seeded to production and never replaced |
| D3 | ~~**High**~~ **fixed** (`031351c`) | No structured data (`ld+json`) anywhere; no `sitemap.xml`; no `hreflang` tags despite two locales | `grep` across `resources/views`, `routes/`, `public/` | Never implemented |
| D4 | ~~**Medium**~~ **fixed** (`d9f60c9`) | PWA is half-wired: `public/sw.js`, `public/manifest.json` and `public/offline.html` exist, but the service worker is registered **only** on `/guide` and the manifest is not linked from `layouts/app.blade.php` | `resources/views/pages/guide.blade.php:78` | Partial implementation |
| D5 | ~~**Low**~~ **fixed** (`fc94a4e`) | `package.json` carries both `tailwindcss ^3.1.0` and `@tailwindcss/vite ^4.0.0` | `package.json` | **Corrected in v1.2:** not a live conflict. `vite.config.js` never loads `@tailwindcss/vite` and `app.css` uses v3 `@tailwind` directives, so v3 builds and `tailwind.config.js` is authoritative. The v4 package is an unused dependency to drop, not a broken build. |
| D6 | ~~**Medium**~~ **fixed** (`fc94a4e`) | No CI. Nothing runs tests, Pint or Larastan before code reaches `main` — and `main` auto-deploys to test | `.github/workflows/` contains only `deploy-test-immediate.yml` | Never set up |
| D7 | ~~**Medium**~~ **fixed** (`fc94a4e`) | Known-failing tests are documented as acceptable in `AGENTS.md` (Breeze `Auth`/`Profile` tests reference removed routes; `ExampleTest` lacks `RefreshDatabase`) | `AGENTS.md` | Drift after customisation |
| D8 | ~~**Low**~~ **fixed** (`fc94a4e`) | Debug scaffolding is routed in production: `admin/media/{medium}/debug` and `admin/test-video` | `routes/web.php` | Leftovers |
| D9 | ~~**Low**~~ **was Critical; fixed** | **Every image upload was broken.** `intervention/image ^2.7` is not merely behind — Laravel 13 ships its own `Illuminate\Image` service bound to the same container key `image`, and its driver calls `ImageManager::usingDriver()`, which exists only in Intervention **v4**. So `Image::make()` resolved the framework's driver and threw on every upload: guide-step images, hero banners and media photos alike. Rated Low on the assumption it was a version-currency issue; nothing had ever uploaded an image in a test. | `composer.json`, `app/Http/Controllers/Admin/*Controller.php` | A major-version gap that had become a hard incompatibility |
| D10 | ~~**Low**~~ **fixed** (`fc94a4e`) | `tailwind copy.config.js` is committed at the repo root | repo root | Stray file |
| D11 | ~~**Medium**~~ **fixed** | Hero banner colours are stored **in the database**, not in CSS. `hero_banners.primary_cta_bg_color` defaults to `#0ea5e9` at the schema level, so existing rows still carry the old sky blue after the palette change | migration default | Colours made admin-editable per row |
| D12 | ~~**Medium**~~ **fixed** (`fc94a4e`) | A `debug-info` banner renders on mobile on the public `/guide` page | `resources/views/pages/guide.blade.php:142` | Debug scaffolding left in |
| D13 | ~~**Low**~~ **fixed** (`fc94a4e`) | `welcome.blade.php` is a dead, unrouted Laravel starter page carrying an inlined Tailwind **v4** build and 43 instances of starter orange | `resources/views/welcome.blade.php` | Never deleted after scaffolding |
| D14 | ~~**Critical**~~ **fixed** (`86cbc7a`) | **The Umrah guide admin cannot save a step.** `guide_steps` has one body-text column, `description`. `GuideStep::$fillable` does not list it, and instead lists four names that are not columns at all (`summary`, `details`, `video_url`, `image_path`). `Admin\GuideStepController` validates `summary` as **required** and writes `summary`/`details`, so creating or editing a guide step throws `SQLSTATE[HY000]: no column named summary` — a hard 500. | `app/Models/GuideStep.php`, `app/Http/Controllers/Admin/GuideStepController.php:43,55,106,118`, `database/migrations/*_guide_steps_table.php` | Model and controller written against a schema that was never migrated |
| D15 | ~~**Medium**~~ **fixed** (`86cbc7a`) | The public guide page renders `$step->summary`, `$step->details`, `$step->video_url` and `$step->image_url`, all of which read `null` because the columns do not exist. Every step therefore shows its title and nothing else. `/api/guide-steps` returns the same four keys as `null` for every step. | `resources/views/pages/guide.blade.php`, `app/Http/Controllers/PageController.php` | As D14 |
| D16 | **Low** *(found during P0.5)* | Registration is open to anyone and creates a non-admin account. Until `3a72d0a` every such signup 500'd, which hid it. | `routes/auth.php` | Breeze default, never closed |
| D17 | ~~**High**~~ **fixed** (`86cbc7a`) | `GuideStep::getFiqhNotesAttribute()` read `$this->fiqh_notes` — its own attribute — shadowing the `array` cast and always returning `[]`. Fiqh notes were written to the database and could never be read back. The admin controller also validated `fiqh_notes` as a string while the form submits an array, rejecting every multi-note entry. | `app/Models/GuideStep.php`, `app/Http/Controllers/Admin/GuideStepController.php` | Accessor written as if it wrapped a different attribute |
| D18 | ~~**Medium**~~ **fixed** | `reference_text` is a real column, is written by `UmrahGuideSeeder`, and has a translated label (`guide.Reference`), but no view renders it. Content is stored and never shown. | `resources/views/pages/guide.blade.php`, `database/seeders/UmrahGuideSeeder.php` | Feature half-built |
| D19 | ~~**Medium**~~ **fixed** (`d9f60c9`) | `layouts/app.blade.php` rendered no `@stack('styles')` or `@stack('scripts')`, while `pages/guide.blade.php` pushed to both. The guide's print stylesheet and all of its scripts — including the only service-worker registration in the codebase — were silently discarded. | `resources/views/layouts/app.blade.php` | Stack never added to the layout |
| D20 | ~~**High**~~ **fixed** (`d9f60c9`) | `sw.js` precached `/css/app.css`, `/js/app.js` and `'/images/guide/'`, none of which exist. `cache.addAll()` rejects the whole batch on one 404, so the worker never installed and offline support never worked. It was also cache-first for navigations, which would serve stale trip pages indefinitely. | `public/sw.js` | Written against a pre-Vite asset layout |
| D21 | ~~**Medium**~~ **fixed** (`d9f60c9`) | `apple-touch-icon.png`, `favicon-32x32.png`, `favicon-16x16.png` and the manifest's two icons were referenced but absent — four 404s on every page load. | `public/`, `public/manifest.json` | Referenced before being produced |
| D22 | ~~**High**~~ **fixed** (`456abd6`) | `app/Http/Controllers/Controller.php` did not use `AuthorizesRequests`, so `$this->authorize()` was an undefined method — any controller that tried to check a permission would have fataled instead. Nothing had tried yet. | `app/Http/Controllers/Controller.php` | Laravel 11+ ships a bare base controller |
| D23 | ~~**High**~~ **fixed** (`b1f91ed`) | `league/commonmark` 2.9.0 carried four high-severity advisories — three DoS and one XSS where the `AttributesExtension`'s `on*` filter is bypassed with a U+000C form feed. Pre-existing, transitive via laravel/framework, and enough to fail CI's dependency-audit job on `main`. | `composer.lock` | Never audited before CI existed |
| D24 | ~~**High**~~ **fixed** (`2e81a34`) | `Trip::boot()`'s sitemap-busting listener was `fn () => Cache::forget('sitemap.xml')`. `Cache::forget()` returns **false** when the key is not cached, and a model-event listener returning false halts the remaining listeners — so that closure silently suppressed every later listener on Trip's `saved` and `deleted` events. Introduced in `031351c`; found because the audit observer stopped firing. | `app/Models/Trip.php` | Arrow function returning the cache call's result |
| D25 | ~~**Medium**~~ **fixed** (`2e81a34`) | Deleting the acting user (the "delete my account" route, which exists today) wrote an audit row whose foreign key pointed at the row just deleted, so the insert was rejected — a 500 on a live route once auditing was on. | `app/Observers/AuditObserver.php` | Ordering of the `deleted` event against the delete itself |
| D26 | ~~**High**~~ **fixed** (`f30291b`) | **Dhivehi never rendered in a Dhivehi font.** The layout asked Google Fonts for "Faruma", which has never been hosted there — verified 400, twice per page load, one of them an `@import` inside a `<style>` block that blocks rendering until it fails. A second `@font-face` pointed at a hand-written `fonts.gstatic.com` URL returning 404. Thaana fell through to `MV Waheed` (absent outside the Maldives) and then to generic sans-serif, while the real font sat unused in `public/fonts/A_faruma.ttf`. | `resources/views/layouts/app.blade.php`, `resources/css/dhivehi-fonts.css` | Font names copied without checking the font existed |
| D27 | ~~**Medium**~~ **fixed** (`f30291b`) | Cairo (9 weights) and Tajawal (7) loaded on every page as Dhivehi "fallbacks". Arabic families carry no Thaana, so they could never render a Dhivehi character, and no view referenced either. | `resources/views/layouts/app.blade.php` | Fallback stack assembled by script family name rather than by coverage |
| D28 | ~~**Low**~~ **fixed** (`f30291b`) | `.font-test-afruama` — a debug rule forcing red 24px text — shipped in the production CSS bundle, alongside `html[lang="dv"] *` with `!important`, which made the font unoverridable anywhere in the Dhivehi UI. | `resources/css/dhivehi-fonts.css` | Debug scaffolding left in |
| D29 | ~~**Low**~~ **fixed** (`f30291b`) | Du'a text had no Arabic face: neither Inter nor a Thaana font covers Arabic, so supplications rendered in whatever the device happened to have, and carried no `lang="ar"` for screen readers. | `resources/views/pages/guide.blade.php` | Never specified |
| D30 | ~~**High**~~ **fixed** (`b29ba77`) | **Static analysis had never run.** `composer analyse` invoked `vendor/bin/larastan`, which Larastan v3 does not ship — it is a PHPStan extension — so the script exited 127. There was also no `phpstan.neon` at all, so even the right command had no configuration, paths or extension to load. | `composer.json`, `phpstan.neon` | Script written against Larastan v2 |
| D31 | ~~**High**~~ **fixed** (`b29ba77`) | `/admin/guide-steps/{id}` returned a 500: it renders `admin.guide-steps.show`, a view that has never existed. Found by Larastan's view-string check on its first run. | `app/Http/Controllers/Admin/GuideStepController.php` | Resource controller scaffolded without the view |
| D32 | ~~**Medium**~~ **fixed** (`b29ba77`) | `User` never implemented `MustVerifyEmail`, though the column, routes, view and a test for the flow all shipped, so `verified` middleware would have passed anyone through unchecked. **Side effect of the fix: registration now sends a verification email**, because Laravel's listener keys off exactly that interface. | `app/Models/User.php` | Marker interface never added |
| D33 | ~~**Critical**~~ **fixed** (`9d2af43`) | 16 npm advisories (2 critical, 11 high) across vite, rollup, postcss, shell-quote and others. Most were build-time, but **axios was shipped to every visitor** — imported in `bootstrap.js`, assigned to `window.axios`, and called by nothing: every AJAX path in the codebase uses `fetch()`. Removing it took `app.js` from 79.94 kB to 44.27 kB. | `package.json`, `resources/js/bootstrap.js` | Breeze scaffolding never taken up; no audit before CI existed |
| D34 | ~~**Medium**~~ **fixed** | **The palette reached the stylesheets and stopped.** `components/section-why.blade.php` still fell back to `#2563eb`, so the live homepage rendered a blue call-to-action; the trips tabs and gallery filters used a pre-rebrand green (`#0e7a57`); and the admin colour pickers offered the old palette as their starting value, so choosing "the default" put blue back. | `resources/views/components/section-why.blade.php`, `resources/views/trips/index.blade.php`, `resources/views/media/gallery.blade.php`, `resources/views/admin/why/edit.blade.php` | Colours held in the database and in inline styles, out of reach of a Tailwind class sweep |
| D35 | ~~**High**~~ **fixed** | **Five auth endpoints had no rate limit.** Registration and password-reset request both send mail to whatever address the request names, so an unauthenticated caller could deliver to an arbitrary inbox as fast as the server answered; reset-submission, password-confirmation and password-update accept credentials and could be guessed without a cap. Login was already throttled by `LoginRequest`, which is why the gap was easy to miss. Registration's half only became a mail vector when `User` took on `MustVerifyEmail` (D32). | `routes/auth.php`, `app/Providers/AppServiceProvider.php` | Breeze throttles login only |
| D36 | ~~**Medium**~~ **fixed** | **No security headers at all.** No `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy` or HSTS — verified against the live response. Every response also carried `x-powered-by: PHP/8.4.24`, naming the exact patch version. Framing matters most for the admin panel, where a framed page can be used to trick a signed-in member of staff. | `app/Http/Middleware/SecurityHeaders.php` | Never configured |
| D37 | ~~**High**~~ **fixed** | **The whole Umrah guide admin was unreachable.** Its three views extend `layouts.admin`, a layout that has never existed, so index, create and edit each returned "View [layouts.admin] not found" — a 500. Every other admin view extends `layouts.app`, and these use only the `title` and `content` sections that layout provides. | `resources/views/admin/guide-steps/*.blade.php` | Layout referenced but never written |
| D38 | ~~**Medium**~~ **fixed** | `GET /admin/why-sections/{section}/features` was registered by the resource route but `WhyFeatureController` has no `index()` method, so it returned a 500. Features are listed and added from the section's own edit screen, so there is nothing for a separate index to show. | `routes/web.php` | `->except()` list missed one |
| D39 | ~~**Medium**~~ **fixed** | The guide-step edit form rendered `fiqh_notes` — a JSON array — straight into a textarea, raising `htmlspecialchars(): must be of type string, array given`. The matching half: validation required an `array` while the form posts a newline-separated string, so every note an editor typed was rejected. The form now takes one note per line and the controller converts. | `resources/views/admin/guide-steps/{create,edit}.blade.php`, `app/Http/Controllers/Admin/GuideStepController.php` | Form written against a string column that was cast to an array |
| D40 | ~~**High**~~ **fixed** | **Renaming a trip moved its public URL.** `TripController::update()` regenerated the slug from the title on every save, so correcting a typo took `/en/trips/ramadan-umrah-2026` out from under every shared link, bookmark and indexed page — and overwrote a slug an editor had deliberately set, though the form validates that field for uniqueness. Directly undermines the sitemap and canonical work in `031351c`. | `app/Http/Controllers/Admin/TripController.php` | Slug treated as derived rather than as the page's address |
| D41 | ~~**Low**~~ **fixed** | Nine `Log::info` debug calls fired on every admin write, one of them logging `$request->all()`. Superseded by the audit log, which records the same events with the actor and without the whole request body. | `app/Http/Controllers/Admin/{HeroBanner,Media}Controller.php` | Debug scaffolding left in |
| D42 | ~~**Medium**~~ **fixed** | Guide-step image filenames were `step_` . `time()` . `.webp`, so two images uploaded in the same second resolved to the same path and the second silently overwrote the first — leaving one step showing another step's picture. Media filenames used `time()` plus the client's own filename, which is attacker-supplied. Both now carry a random component and the client filename is not used. | `app/Http/Controllers/Admin/{GuideStep,Media}Controller.php` | Timestamp treated as unique |
| D43 | ~~**High**~~ **fixed** | **The Dhivehi guide PDF had no Thaana font.** Its stylesheet pointed at `storage/fonts/Faruma.ttf`, a file that has never existed — and neither had the directory, so dompdf's font cache was unwritable too. dompdf does not report a missing `@font-face` source; it falls back, and the fallback has no Thaana glyphs, so the download was boxes. Unlike a web page, a PDF is what a pilgrim carries with them and cannot be fixed by reloading. | `resources/views/pdf/guide.blade.php`, `storage/fonts/` | Font path written for a file that was never added |
| D44 | ~~**Medium**~~ **fixed** | **Every error page was Laravel's default.** A 404 returned `<title>Not Found</title>` in a grey box: no logo, no brand colour, no navigation, and nothing to click — a visitor from a stale link had to retype the domain. Grepping the response for `Rihla`, `rihla-logo` and `WhatsApp` returned zero of each. The 301s and the sitemap shipped in 1.12 make arriving at a 404 a normal event, not an edge case. The replacements are deliberately self-contained — no parent layout, no bundled CSS, no database — because `layouts.app` now emits Organization JSON-LD that reads the settings table, and a 500 page must not need the database to explain that the database is down. | `resources/views/errors/` | Framework default left in place |
| D45 | ~~**Medium**~~ **fixed** | **Error pages ignored the locale in the URL.** Route middleware never runs for a URL that matches no route, so `SetLocale` had not fired by the time an error rendered: `/dv/anything-misspelt` came back as an English, `dir="ltr"` document with both of its links back into the site pointing at `/en`. Found by writing the test for D44. | `bootstrap/app.php`, `app/Http/Middleware/SetLocale.php` | Locale resolution tied to route matching |

> **D1 and D2 together mean the live homepage currently shows untranslated placeholder labels above holiday-resort packages.** Everything else in this plan is worth less than fixing those two, and both are hours of work, not weeks.

### 1.3 Honest gap assessment

The source thread assumes a platform that does not exist yet. Measured against it, Rihla today has: the public website (partially), a CMS-ish admin, and i18n scaffolding. It has **none** of booking, payments, documents, visa/permit tracking, portals, learning, CRM, operations, finance, BI, or AI. That is not a criticism — it is the reason the roadmap in §12 front-loads booking and operations rather than the enterprise standards the thread spent 60 documents on.

---

## 2. Strategy: what Rihla should become

### 2.1 Positioning

> **The complete digital companion for Maldivian pilgrims — before, during, and after the journey.**

That framing comes from the source thread and it is the right one. The differentiator is not the booking form; every agency has one. It is that Rihla *prepares* pilgrims (learning), *accompanies* them (portal, Ziyarah guide, offline), *reassures their families* (family portal, daily updates), and *keeps the relationship* afterwards (memories, loyalty, repeat and referral).

### 2.2 The three jobs the site must do

| Job | Who | Success looks like |
|---|---|---|
| **Choose with confidence** | Prospective pilgrim comparing agencies | Transparent inclusions, real hotel walking distances, named tour leader and scholar, visible licence, verified reviews, seat counts |
| **Prepare properly** | Booked pilgrim, 0–90 days out | Learning path completed, documents uploaded and verified, visa/permit status visible, packing done, rituals understood |
| **Stay connected** | Pilgrim in-country + family at home | Daily itinerary and updates, offline Ziyarah guide, emergency contacts, family photo feed |

### 2.3 What the source thread missed — and it matters

The thread never mentions **Nusuk**. As of 2026 this is the single biggest operational reality for any Umrah operator:

- Nusuk is the Saudi government's mandatory digital gateway. **Without a Nusuk-issued permit a pilgrim cannot enter the Mataf or the Rawdah**, regardless of visa type.
- The 2026 "any visa is an Umrah visa" policy means tourist/transit/visit visa holders may perform Umrah **only if** the visa is linked to Nusuk and a permit is issued before entering Makkah.
- Accommodation and transport must be booked through Nusuk or a licensed provider handling Nusuk compliance **before** visa application.

**Implication for the plan:** the "Visa Tracker" the thread describes must become a **Visa & Permit Tracker** covering Nusuk account linkage, permit issuance, and Rawdah slot booking — and the operations module must treat Nusuk-compliant accommodation/transport booking as a hard gate in the journey workflow (§5.4, §8.3).

Second omission: **Maldivian regulatory context.** The Ministry of Islamic Affairs licenses Umrah operators (57 companies held valid permits at last count), publishes approved-agent lists, and fines non-compliant operators — up to MVR 30,000, with licence suspension and police referral in serious cases, and far larger fines for unlicensed operators. This is a *marketing asset* (publish the licence prominently, link the Ministry's approved list) and a *compliance requirement* (auditable records of what each pilgrim was promised and paid).

Third omission: **payments actually available in the Maldives.** The thread's finance module is currency-agnostic enterprise boilerplate. In practice the decision is BML Connect (§11.1), and it got materially cheaper in Nov 2025 when BML cut its merchant discount rate from 2.5% to 1%.

---

## 3. P0: production defects to fix first

Nothing in §4–§9 should start before this section is done. Estimated total: **4–7 developer-days** (was 3–5 before P0.7 was added by **[R-1]**).

### P0.1 — Fix the broken translations (D1)

One fix, applied once:

1. **Namespace the call sites.** Convert all 21 dotless calls to the file they live in: `__('hero_title')` → `__('messages.hero_title')` (check `app.php` and `guide.php` too — three PHP translation files exist per locale). One mechanical pass over `resources/views/`.
2. *Optional, cosmetic:* move `resources/lang/` → `lang/` to match current Laravel convention. **This is not part of the fix** — Laravel 13 already reads `resources/lang` when it exists — so do it only if you want the conventional layout, and do it in its own commit.
3. **Prevent recurrence:** add a lightweight test that walks `resources/views/` and fails on any `__('…')` call without a dot — a one-line regex that would have caught this before it shipped.

**Acceptance:** `curl -s https://test.rihla.mv/ | grep -c 'hero_title'` returns 0; the Dhivehi homepage renders Thaana for every one of the 21 keys; a feature test asserts the homepage contains the translated string, not the key, in both locales. **Add that test** — this bug reached production precisely because nothing asserted it.

### P0.2 — Replace demo content (D2)

Remove the resort/island-hopping seed rows from production. Publish real Umrah packages, or if none are ready, an honest "next departures announced soon" state. Guard **every demo seeder** — `TripSeeder`, `MediaSeeder`, `HeroBannerSeeder`, `WhySectionSeeder` — so demo data cannot run in production (`if (app()->isProduction()) return;`). `SettingsSeeder` and the Umrah guide seeders carry real content and may stay unguarded.

**Acceptance:** no non-Umrah package appears on rihla.mv; seeders are environment-guarded.

### P0.3 — Stand up CI (D6, D7)

Add `.github/workflows/ci.yml` running on pull requests and pushes to `main`: `composer install`, `npm ci && npm run build`, `php artisan test`, `composer lint:php`, `composer analyse`, plus `composer audit` and `npm audit --audit-level=high` for dependency vulnerabilities.

**[R-2]** Add a **second job running the suite against a MySQL service container**, not just the in-memory SQLite in `phpunit.xml`. The booking engine's capacity guarantee rests on `SELECT … FOR UPDATE` row locking, which SQLite cannot exercise — a concurrency test that only ever runs on SQLite reports green while proving nothing (see the companion document §8.4, §25.6).

Because `main` auto-deploys to test, CI must be **required to pass before merge**. Fix or delete the known-failing Breeze tests (D7) in the same PR so the suite is green and stays meaningful — a permanently red suite trains everyone to ignore it.

**Acceptance:** CI green on `main`; branch protection requires it; `AGENTS.md`'s "pre-existing test failures" paragraph is deleted because it is no longer true.

**Status: done** (commits `fc94a4e`, `3a72d0a`). Five jobs: tests on PHP 8.3/8.4, tests against MySQL 8, Pint on changed files plus Larastan, a check that the committed `public/build` matches a fresh build, and `composer audit` / `npm audit`.

Running the suite for the first time surfaced four defects that were live in production, not merely test failures:

- `dashboard` was never a defined route name — the only dashboard route is named `admin.dashboard` because it sits inside the `admin.` group. Five auth controllers redirect to `route('dashboard')`, so registration, email verification and password confirmation each threw `RouteNotFoundException` and returned a 500.
- `ProfileController` and `resources/views/profile/` shipped with the app but were never routed, so the controller was unreachable and `profile.edit` — referenced by the profile forms themselves — did not resolve.
- `layouts/app.blade.php` echoed only `@yield('content')`. The Breeze views render it as `<x-app-layout>` and pass their body as `$slot`, which nothing echoed, so those pages came out blank.
- The guide API's locale guard called `request()->json(...)`, which reads the *request* body and never produces a response. An unknown locale fell through the guard and was answered with 200 and an empty step list instead of 400.

All 51 tests now pass. Note that CI triggers on `pull_request` and `push: main`, so it does not run on feature-branch pushes — it will first execute when this branch is opened as a PR.

### P0.4 — Clean up (D8, D10, D5)

- Delete the `admin/media/{medium}/debug` route and `admin/test-video` route + view.
- Delete `tailwind copy.config.js`.
- Stop caching Eloquent models. `HomeController` caches `WhySection`/`WhyFeature` model instances, which is why `config/cache.php` carries a `serializable_classes` allowlist that must be maintained by hand (per `AGENTS.md`). Cache plain arrays or DTOs instead and drop the allowlist — it is a fragility waiting for the next model added to the homepage.
- **[v1.2 correction]** Drop the unused `@tailwindcss/vite` dependency. The earlier recommendation to migrate to v4 was based on a misreading: `vite.config.js` never loads the v4 plugin and `app.css` uses v3 directives, so the project is cleanly on v3 and `tailwind.config.js` is live. A v4 migration is optional housekeeping, not a fix — and now that the colour system is implemented against v3, it should not be attempted casually.
- Delete `welcome.blade.php` (D13) — unrouted, and it ships a second inlined Tailwind build.
- Remove the `debug-info` banner from `/guide` (D12).

### P0.5 — SEO essentials (D3)

> **[R-1] Depends on P0.7.** `hreflang`, per-locale canonicals and a two-locale sitemap are inert while locale lives in the session and both languages share one URL. Do P0.7 first.

- `sitemap.xml` (route-generated, cached): home, trips index, each published trip, guide, gallery, contact — both locales.
- `hreflang` alternates for `en` / `dv` in `layouts/app.blade.php`, plus `x-default`.
- JSON-LD in the layout: `Organization` (with `identifier` = Reg. No. C11452023, licence, contact), `BreadcrumbList` on content pages, `FAQPage` on `/guide`, and `Product` + `Offer` + `AggregateRating` on each trip page (`TouristTrip` in addition — no rich result today, but AI search surfaces read it).

**Acceptance:** Rich Results Test passes for a trip page; Search Console shows both locales; dates emitted as ISO 8601.

**Status: done** (commit `031351c`), with three deliberate departures, each because the honest output differs from the one specified above:

- **No `AggregateRating`.** The site holds no reviews. A rating in structured data that no visitor can see on the page is a Google structured-data policy violation and a lie to customers. `SeoTest` asserts no page claims one, so it cannot be added back without the reviews to support it. Revisit when the review system in Workstream C exists.
- **`AggregateOffer` with `lowPrice`, not `Offer` with `price`.** `price_from_mvr` is a "from" figure; as an exact price it would put a number in search results that no customer can actually book at.
- **`HowTo`, not `FAQPage`, for the guide.** Its entries are ordered ritual steps, not questions with answers — `Question`/`acceptedAnswer` would describe content the page does not contain. Google retired the HowTo rich result in 2023, so this wins no carousel either way; it is there because AI search surfaces read it and because it is what the page is.

Also: `Seo::json()` uses `JSON_HEX_TAG`, because every string in these blocks is author-supplied through the admin panel and a title containing `</script>` would otherwise close the block. The first version of that method shipped an escaping call that was a silent no-op; the test is what caught it.

**Still open:** the Rich Results Test and Search Console halves of the acceptance need the site deployed and the property verified — neither can be checked from the repository.

### P0.6 — Finish the PWA wiring (D4)

Link `manifest.json` from the main layout, register the service worker site-wide (not only `/guide`), and define an explicit offline strategy: app shell + guide + Ziyarah content cached, everything else network-first with the existing `offline.html` fallback.

**Status: done** (commit `d9f60c9`). Every part existed; none of it was connected.

- **The manifest was never linked from any page**, so the site could not be installed at all — and it declared `scope`/`start_url` of `/guide`, which since P0.7 is a 301 rather than a page.
- **Its two icons did not exist** (`logo-192.png`, `logo-512.png`), nor did its two screenshots. Chrome refuses to install a manifest whose icons 404, so even a linked manifest would have failed.
- **The service worker was never registered.** Its registration sat in a `@push('scripts')` block on the guide page, and the layout renders no `scripts` stack. The same layout renders no `styles` stack, so the guide's print stylesheet never reached a page either (**D19**). Both stacks now exist and registration moved to the layout.
- **The worker could not install.** `cache.addAll()` rejects the whole batch on a single 404 and three of its five entries were wrong — `/css/app.css` and `/js/app.js` (Vite emits hashed files under `/build/assets/`) and `'/images/guide/'`, a directory. Every install threw and the cache stayed empty, so nothing ever worked offline (**D20**).
- It was cache-first for navigations, which would serve a stale trip page — wrong prices, dates and seat counts — indefinitely.

Rewritten to network-first for navigations (cached page, then `offline.html`, as fallbacks), cache-first for hashed build assets, and untouched for non-GET, cross-origin and `/admin`, `/dashboard` requests — the last so a cached response cannot be shown to the wrong user after logout.

Icons are generated from the mark in the **existing** logo, not redrawn, per the standing instruction to preserve the logo: separate `any` and `maskable` sets, the maskable ones inset to 60% on cream so Android's circular crop does not cut the mark. This also supplies `apple-touch-icon.png`, `favicon-32x32.png` and `favicon-16x16.png`, which the layout has referenced all along without them existing — every page was requesting four 404s (**D21**).

`theme_color` and `background_color` move from the pre-rebrand blue to ink and cream. **The logo itself is untouched**; it remains the original blue/gold/black and is still the open decision noted in §13.

**Deferred:** caching Ziyarah content, which does not exist yet (Workstream D).

### P0.7 — Locale-prefixed routing **[R-1, new]**

`app/Http/Middleware/SetLocale.php` resolves the locale from `session('app_locale')`, so `/trips/hajj-2026` serves English or Dhivehi depending on a cookie. There is no distinct URL per language, which means a crawler cannot index the Dhivehi site, `hreflang` has nothing to point at, and the canonical tag already emitted (`url()->current()`) is identical for both languages.

Move locale into the URL: a locale-prefixed route group (`/en/…`, `/dv/…`), with `/` redirecting on stored preference or `Accept-Language`, and every current path kept as a 301. `SetLocale` then reads the route parameter, falling back to the session only for the bare root.

**Acceptance:** `/en/trips` and `/dv/trips` both resolve and render their own language; `hreflang` alternates point at real URLs; canonical differs per locale; both appear in the sitemap. Estimated 1–2 days.

**Status: done** (commit `f4c41cc`). Public routes sit under a `{locale}` prefix constrained to `en|dv`. `SetLocale` resolves path → session → `Accept-Language`, calls `URL::defaults(['locale' => …])` so no view had to change, and drops the parameter so controllers keep their signatures. `/` forwards with a 302 (a 301 would be cached and pin the visitor to one language); the six pre-prefix paths and `/trips/{slug}` return 301. The switcher's route parameter was renamed `{locale}` → `{code}`, which is load-bearing: `URL::defaults()` is substituted before positional arguments, so left as `{locale}` both switcher links would have pointed at the language already being read. Admin, auth and the JSON API stay unprefixed. Covered by `tests/Feature/LocaleRoutingTest.php`, verified against cached routes too. The `hreflang`/canonical/sitemap half of the acceptance belongs to P0.5 and is still open.

---

## 4. Workstream A — Public website & conversion

*Sources: `01-INFORMATION-ARCHITECTURE`, `02-DESIGN-SYSTEM`, `03-HOMEPAGE`, `04-PACKAGES-BOOKING` (browse half), `24-MARKETING-CMS-SEO`, `A15-UX-DESIGN-SYSTEM-STANDARDS`.*

### 4.1 Information architecture

Adopt the thread's IA, trimmed to what Rihla can actually maintain:

```
Home
Packages        (was /trips — keep /trips as a 301 alias)
  └ Package detail
Learn           (Learning Academy)
History         (Knowledge Centre)
Ziyarah         (Interactive guide)
Hotels
About           (licence, team, scholars, tour leaders)
Reviews
Gallery
Blog
Contact
Login → Portal
```

**Rule from the source, worth keeping:** no page more than three clicks from home; booking reachable from every relevant page; full RTL for Dhivehi and (later) Arabic.

Mobile: fixed bottom navigation — Home / Packages / Learn / Bookings / Account.

### 4.2 Homepage rebuild

Ordered sections (from `03-HOMEPAGE`, pruned to 12 — the original 21 is too long for a 3G phone in Malé):

1. Announcement bar (admin-scheduled, multilingual)
2. Hero — cinematic, one headline, three CTAs: *View Packages* / *Learn About Umrah* / *Talk to an Advisor*
3. Hero widgets — next departure, seats remaining, years serving, pilgrims served, rating
4. Smart search / package finder (month, budget, duration, hotel distance, group type)
5. Upcoming packages — price, dates, airline, hotel + walking distance, **seats-remaining bar**, countdown, compare & save
6. Why Rihla — licence, guides, scholars, verified hotels, transparent pricing, emergency support
7. Journey timeline — enquiry → booking → payment → visa & Nusuk permit → preparation → departure → Madinah → Makkah → return
8. Learning Centre preview
9. Ziyarah / Historical spotlight (rotating location)
10. Scholars & tour leaders (photo, bio, languages, groups led)
11. Testimonials — video, photo, written, verified-pilgrim badge
12. Stats, FAQ, newsletter, contact, footer

Deferred to later phases but designed for now: personalisation for returning users, seasonal Ramadan/Dhul-Hijjah theming, prayer times + Qibla widget, live Haram stream.

### 4.3 The conversion features that actually differentiate

From the thread, ranked by impact-per-effort:

| Feature | Why it wins | Effort |
|---|---|---|
| **Interactive package comparison** | Every competitor ships PDFs; a diff table is instantly better | S |
| **Hotel distance explorer** | "300m / 4 min walk to Haram" with a map beats "5-star hotel" | M |
| **Live seat availability bar** | `████████░░ 18 of 24 booked` — honest urgency | S |
| **Departure countdown** | Cheap, emotionally effective | XS |
| **Flight timeline visual** | Malé → Dubai → Madinah → bus → hotel, as a timeline | S |
| **Day-by-day package itinerary** | The single most requested thing pilgrims ask about | M |
| **Group leader & scholar profiles** | Pilgrims choose people, not packages | S |
| **Trust dashboard** | Licence, Ministry approval, partner airlines/hotels, verified review counts | S |
| **One-click WhatsApp with package pre-filled** | Matches how Maldivians actually enquire | XS |
| **Cost calculator with instalments** | Converts the "can I afford it" hesitation | S |
| **Package difficulty / accessibility rating** | Walking distances, stairs, wheelchair suitability — nobody publishes this | S |
| **"Is this package right for me?" wizard** | Recommends from month/budget/party/hotel preference | M |

### 4.4 CMS

The current hand-rolled admin cannot carry blog, destination pages, landing pages and a page builder. See §9.2 — the recommendation is to move admin to Filament and treat CMS as resources within it rather than building a visual page builder (the thread's `24-MARKETING-CMS` proposes one; it is a multi-month project and Rihla does not need it).

### 4.5 The colour system — **implemented** ✅

Full specification: [`BRAND.md`](BRAND.md).

The old palette failed accessibility at the two jobs it did most. White text on the sky blue
scored **2.95:1** and on the gold **2.62:1**, against the 4.5:1 this plan commits to in §10.2 —
so every primary button on the site was below standard. It has been replaced:

| Role | Token | Hex |
|---|---|---|
| Primary — CTAs, active nav, links | `wine-500` | `#8E2653` |
| Accent — rules, icons, premium detail | `gold-500` | `#D2A03C` |
| Body text and dark UI | `ink` | `#2E2621` |
| Warm background | `cream` | `#FBF6EC` |
| Semantic | `success` / `warning` / `error` | `#0F7A54` / `#9E6A0D` / `#D92D20` |

White on wine now measures **8.23:1**. Hierarchy runs **cream → wine → gold → ink**.

**Shipped in `488ffee`:** tokens in `tailwind.config.js`; Tailwind's cool default `gray` scale
overridden with a warm ramp matched step-for-step to the original lightness, which warmed ~800
existing `gray-*` classes with no markup change and no contrast change; the component layer in
`app.css` consolidated (`.btn-primary`, `.card` and `.badge-gold` had each been declared twice,
with the later plain-CSS rule silently winning); three competing primary buttons reduced to one;
and all `brand-*`, blue, red and green usages migrated across 48 Blade views.

**Two fixes worth noting.** Gold fills carried white text at 2.4:1 in the header CTAs and a home
badge — gold fills now take ink text at 6.2:1. And the sky-to-gold and sky-to-amber gradients are
now single-hue wine.

**Completed since (D11, D34).** The palette had reached the stylesheets and stopped there.
`hero_banners`' column defaults were still sky blue, so every banner created after the rebrand
arrived in the old brand; those defaults and the rows still holding them are migrated, while
colours an editor chose deliberately are left alone. `components/section-why.blade.php` had been
missed entirely — its fallback was `blue-600`, which is what the live homepage was rendering —
along with a pre-rebrand green on the trips tabs and gallery filters, and the admin colour
pickers, which offered the old palette as their starting value.

`App\Support\Brand` now names the hex values once, for the two places a Tailwind class cannot
reach: inline styles and database defaults. `BrandColourTest` asserts that no view carries a
retired colour, that the constants match `tailwind.config.js`, that a new banner defaults to the
brand, and that white-on-wine and ink-on-gold clear 4.5:1 while white-on-gold does not.

**Still open:** the logo is deliberately untouched and its asset gaps are unresolved
(`BRAND.md` §4).

### 4.6 Typography — **implemented** ✅

The colour system's counterpart, and it carried a worse defect than any colour did: **Dhivehi never rendered in a Dhivehi font** (D26).

`layouts/app.blade.php` requested `family=Faruma` from Google Fonts, where that font has never been hosted — verified **400**, twice per page load, one of them an `@import` inside a `<style>` block, which blocks first paint until it fails. A second `@font-face` pointed at a hand-written `fonts.gstatic.com` URL returning **404**. Thaana therefore fell through to `MV Waheed` — a system font absent from every device outside the Maldives — and then to generic sans-serif. The real font was in the repository the whole time, at `public/fonts/A_faruma.ttf`.

**Shipped in `f30291b`:**

| | Before | After |
|---|---|---|
| Stylesheet requests per page | 5 | 2 |
| Requests that always failed | 2 | 0 |
| Font weights fetched | ~16 (Cairo 9 + Tajawal 7) | 2 (Cairo) |
| Thaana font | none that resolved | self-hosted, 12 KB WOFF2 |

A_Faruma is self-hosted and converted to WOFF2 (28 KB → 12 KB), preloaded only on Dhivehi pages. The face holds 50 Thaana glyphs and 3 Latin, so its `unicode-range` confines it to Thaana: Latin and digits inside Dhivehi text still come from Inter, and an English page never fetches it. Cairo survives at two weights and is now actually applied — du'a text had no Arabic face at all, and now carries `lang="ar"` and `dir="rtl"` for screen readers too.

Also removed: `html[lang="dv"] *` with `!important`, and `.font-test-afruama`, a debug rule forcing red 24px text that had shipped in the production bundle. Thaana line height and tracking are now set deliberately, because its vowel marks sit above and below the base letter and collide at line heights chosen for Latin.

`display-sm` … `display-xl` added to the Tailwind scale, each pairing a size with its line height and tracking. **Added**, not a redefinition of `text-*` — overriding those keys would silently resize every heading on the site.

**Still open:** the spacing rhythm. That is cosmetic refactoring across 48 views and wants a designer's eye more than an engineer's, so it is deliberately not bundled here.

---

## 5. Workstream B — Booking engine & payments

*Sources: `04-PACKAGES-BOOKING`, `16-FINANCE-ACCOUNTING-PAYMENTS`, `25-DOCUMENT-MANAGEMENT-DIGITAL-WALLET`.*

This is where the business value is. Today every booking is a WhatsApp conversation and a spreadsheet.

### 5.1 Domain model

Replace the single `trips` table with a proper package/departure split. The full design — every entity, field, aggregate boundary and the ERD — is the companion document's §4; the shape is:

- `packages` + `package_translations` — the reusable product (type, duration, difficulty/accessibility; per-locale title, summary, inclusions, exclusions, slug and SEO)
- `departures` — a dated instance (dates, airline, `capacity_total/held/confirmed`, status, tour leader, scholar) with `price_tiers` per occupancy × pax type, `departure_hotels`, `itinerary_items`
- `customers` and `travellers` — separate entities; a customer manages many travellers, a traveller may have no login
- `bookings` (reference `RIH-B-2026-0417`) as the aggregate root over `booking_travellers`, `booking_lines`, `seat_holds`, `booking_status_transitions`, with a **package snapshot** so later edits cannot change what was sold
- `waitlist_entries` — per departure, with auto-promotion when a seat is released
- `payments` → `payment_transactions` → provider driver; `payment_plans`, `refunds`
- `documents` → `document_versions`; `visa_applications`; `nusuk_permits`
- `notifications`, `notification_templates`, `audit_logs`

**Migration path:** additive and reversible — companion §25. `trips` stays readable through the transition, each row backfilled into one package + one departure with lineage recorded, `/trips/{slug}` kept as a 301, and `trips` retired only after a full season runs on the new model.

### 5.2 Booking flow

```
Package → Departure → Select room type & occupancy → Traveller details (per person)
 → Optional extras → Review & terms → Deposit payment → Confirmation
 → Account created → Pilgrim Portal
```

Rules to encode explicitly: seat holds with expiry (15 min), overbooking prevention under concurrency (DB-level constraint, not just an app check), waiting list with auto-promotion, group/family bookings under one payer, minor/mahram rules, cancellation and refund policy per package.

### 5.3 Payments

- **Gateway:** BML Connect via `javaabu/bml-connect-laravel` (actively maintained; v0.7.0, Feb 2026). MVR and USD; 3-D Secure; BML Pay, Apple Pay, Google Pay, Visa/Mastercard/Amex/UnionPay. MDR 1% since Nov 2025.
- **Instalments:** deposit + scheduled instalments with automated reminders (email + WhatsApp), late-payment escalation to CRM tasks, and a hard rule that final documents/permits are gated on full payment.
- **Offline methods:** bank transfer with slip upload and manual reconciliation — this will remain a large share of Maldivian payments; do not treat it as an afterthought.
- **Outputs:** invoices, receipts, statements as PDFs (the app already has `barryvdh/laravel-dompdf`), plus a customer-facing payment history in the portal.
- **[R-7] Store all money as integer minor units** (laari for MVR, cents for USD) — never floats, never decimals-as-strings. A booking carries one currency, fixed at creation; any FX rate applied is stored on the line item, never recomputed later. Retrofitting this after the first real payments is painful and error-prone.
- **Provider independence:** `Booking → Payment → PaymentTransaction → provider driver`; BML appears in exactly one class. Callback idempotency rests on a unique constraint over the provider event ID. Full design: companion document §10.

### 5.4 Visa and Nusuk permits — **two deliverables, not one [R-4]**

This replaces the thread's simpler "visa tracker". The end-to-end sequence is:

```
Documents collected → Nusuk account linked → Accommodation & transport recorded (Nusuk-compliant)
 → Visa applied → Visa issued → Umrah permit issued → Rawdah slot booked → Ready to travel
```

…but it must be built as **two independent workflows**, because they are different authorisations from different systems with different failure modes. Under the 2026 rules a traveller can hold a **valid visa and still be barred from the Mataf and the Rawdah** without a Nusuk permit; one combined status field cannot represent that state — and that is precisely the state that strands a pilgrim.

**5.4a — Visa applications.** One per traveller per booking, with its own state machine, assigned visa officer, evidence document and audit trail. Re-application after rejection is a first-class path.

**5.4b — Nusuk permits.** Separate records for the Umrah permit and any Rawdah slot, with a prerequisite gate (Nusuk-compliant accommodation and transport recorded) before a request can be made. **All Saudi requirements — passport validity window, prerequisites, permitted visa types, slot lead times — live in versioned configuration, never as constants in code**, so a mid-season policy change is a config edit rather than a deployment.

Each stage: owner, timestamp, evidence document, pilgrim-visible status, and an SLA alert to operations when it stalls. **Gate:** a departure cannot be marked "ready" while any traveller lacks a permit. Travel readiness is **computed** from these records, never stored as a booking field.

Full design: companion document §13 (visa) and §14 (permits).

### 5.5 Digital document wallet

Per-traveller secure storage with categories (travel, identity, financial, learning, medical-optional), verification workflow, expiry alerts (passport < 6 months validity is a blocker — check it automatically, with the window configurable), QR codes for check-in, offline access on mobile, and a full audit trail.

**[R-8] Document versioning is in scope for Phase 3, not later.** A replaced passport creates version 2 and supersedes version 1; it never overwrites it. Files are checksummed, stored on a private disk, and reachable only through short-lived signed URLs, with every *download* audited. This is a schema decision, not a feature toggle — retrofitting versioning onto flat document rows means migrating live passport data. Apple/Google Wallet passes for the boarding-style journey card are a nice Phase 5 addition.

---

## 6. Workstream C — Pilgrim, Family, Tour Leader & Scholar portals

*Sources: `05-PILGRIM-PORTAL`, `06-FAMILY-PORTAL`, `07-TOUR-LEADER-PORTAL`, `08-SCHOLAR-PORTAL`, `13-COMMUNICATION-COMMUNITY`, `21-MOBILE-APPS-PWA`, `31-SAFETY-HEALTH-EMERGENCY`.*

### 6.1 Pilgrim Portal (Phase 3 — highest retention value)

Dashboard, booking overview, travel timeline, digital wallet, **visa & permit tracker**, flight centre, hotel centre, daily itinerary, learning progress, Ziyarah companion, packing checklist, payments & balance, announcements, emergency contacts, downloads, settings.

Standouts worth building from the thread's "additional features":
- **Pilgrim Readiness Score** — one number combining documents verified, payments current, learning modules completed, checklist done. It turns nagging into a game and cuts operations' chase-work.
- **"Where am I?" companion** — location-aware explanation of the significance of the site the pilgrim is standing in.
- **Personal journal + Digital Memory Box** — private reflections, photos, returned to them after the trip.

### 6.2 Family Portal (Phase 4 — the marketing engine)

Families at home are the strongest referral channel Rihla has. Journey progress, current status, daily schedule, announcements, official group photo album, flight info, prayer requests, emergency information — **with privacy controls the pilgrim owns** (no individual live location unless explicitly enabled; group-level status only by default).

### 6.3 Tour Leader Portal (Phase 4 — operations leverage)

Group roster, pilgrim profiles, attendance (with bus/room manifests), daily itinerary, announcements, media upload, incident reports, emergency centre, expenses. **Must work offline** — connectivity in transit is unreliable; design for queued writes and sync.

### 6.4 Scholar Portal (Phase 5)

Profile, content authoring with review workflow, authentic references (Qur'an/Hadith with citations), audio/video library, live sessions, "Ask a Scholar" queue, learning-path curation. Editorial review before publish is non-negotiable for religious content.

### 6.5 Safety & emergency (Phase 4, partial in Phase 3)

One-tap SOS, emergency profile with medical info, incident workflow by severity, missing-person and lost-passport playbooks, heat/hydration advisories, group-wide emergency broadcast. Even a minimal version (emergency contacts + broadcast) belongs in the first portal release.

---

## 7. Workstream D — Knowledge Centre, Ziyarah Guide & Learning Academy

*Sources: `10-RELIGIOUS-HISTORICAL-KNOWLEDGE-CENTRE`, `11-INTERACTIVE-ZIYARAH-GUIDE`, `12-UMRAH-LEARNING-ACADEMY`.*

This is the thread's best strategic insight: **education as differentiation**. Almost no Umrah agency does it well, it is cheap to build relative to a booking engine, it is the single best SEO asset a travel site can own, and it is genuinely useful.

### 7.1 Knowledge Centre

History of Makkah and Madinah; the Kaaba, Hajar al-Aswad, Maqam Ibrahim, Hijr Ismail, Zamzam, Safa & Marwah; Masjid al-Haram and Masjid an-Nabawi; Quba, Qiblatain, Jannatul Baqi, Jannatul Mu'alla; Hira and Thawr; Badr, Uhud, Khandaq, Hudaybiyyah, the Conquest, the Farewell Pilgrimage; prophets and companions; an interactive Islamic timeline and atlas; duas with Arabic, transliteration, translation and audio.

**Editorial standard:** every claim carries a source (Qur'an reference or graded Hadith), every article has a named scholar reviewer, and disputed/weak narrations are labelled as such. Build this into the CMS as required fields, not as a style guide people forget.

### 7.2 Interactive Ziyarah Guide

Map-first. Each location: history, significance, references, photos, video, audio guide, GPS directions, nearby locations, best visiting time, duas, etiquette, **common misconceptions** (the thread flags this and it is genuinely valuable — it prevents the innovations pilgrims are warned about). Plus scholar-curated walking routes (Around Masjid al-Haram; Madinah Essentials), "Before you visit / After you visit" prompts, and **full offline support** — this is the feature pilgrims use with no data in Saudi Arabia.

### 7.3 Umrah Learning Academy

Learning paths (beginner / intermediate / advanced / women's / family / children's), modules on ihram, miqat, talbiyah, tawaf, sa'i, halq & taqsir, common mistakes, packing, health, etiquette. Quizzes, certificates, progress tracking, downloadable audio, pronunciation practice, and a **personalised study plan keyed to the departure date** (60 / 30 / 7 days out). Link modules to the itinerary: if the trip visits Uhud, surface Uhud's history the week before.

**Business tie-in:** completion feeds the Pilgrim Readiness Score (§6.1) and gives sales a real answer to "why Rihla".

---

## 8. Workstream E — Back office: CRM, operations, finance

*Sources: `14-CRM-SALES`, `15-OPERATIONS-MANAGEMENT`, `16-FINANCE`, `17-BI-ANALYTICS`, `26-PARTNER-B2B`, `27-LOYALTY`, `28-SUPPLIER-PROCUREMENT`, `29-JOURNEY-PLANNING-CAPACITY`, `30-REAL-TIME-OPERATIONS-COMMAND-CENTER`, `32-EXECUTIVE-OKR`, `33-CONFIG-FEATURE-FLAGS`.*

The thread specifies ten enterprise modules here. For an agency of Rihla's size, build them in this order and stop when the pain stops.

### 8.1 CRM (Phase 3, minimal; Phase 5, full)

Leads from web forms and WhatsApp, lead pipeline, follow-up tasks and reminders, quotations, communication history, customer 360 view, tags and preferences, referral tracking, post-Umrah re-engagement. **Minimal version first**: every enquiry becomes a tracked lead with an owner and a next action — that alone beats a shared inbox.

### 8.2 Journey planning & capacity (Phase 4)

Journey workspace per departure: capacity, flight allocation, hotel blocks, **room allocation** (intelligent grouping by family/gender/age), transport, staff and scholar assignment, checklists, conflict detection, waitlist, readiness score. Room allocation and rooming lists are, in practice, where operator time disappears.

### 8.3 Operations (Phase 4)

Pre-departure checklists, airport operations, flight monitoring, hotel operations, transport/bus and seat management, attendance, incident management, daily operations log, meeting-point manager, supplier coordination. **Nusuk compliance gate** (§5.4) lives here.

Defer the thread's real-time "Command Center" with live maps (`30-...`) to Phase 6+; it presumes staffing Rihla does not have.

### 8.4 Finance (Phase 5)

Customer payments and instalments, invoices/receipts, refunds, supplier payments, expenses, **per-journey profitability**, package cost builder, multi-currency (MVR/USD/SAR), approvals, bank reconciliation, audit trail, tax configuration. Per-journey profitability is the report that changes pricing decisions.

### 8.5 BI (Phase 6)

Executive dashboard, sales/marketing/customer/financial/operational/learning analytics, forecasting, smart alerts. Start with ~10 KPIs (§10.5), not a warehouse.

### 8.6 Deferred to "when the business asks"

Partner/B2B agent portal, loyalty & membership, supplier procurement, OKR/executive platform, multi-tenancy, plugin marketplace. All are real ideas from the thread; none earns its build cost before Rihla has a working booking and operations platform.

---

## 9. Workstream F — Platform, architecture & delivery

*Sources: `19-INTEGRATION-API`, `20-SECURITY-PRIVACY-GOVERNANCE`, `22-DEPLOYMENT-INFRASTRUCTURE-DEVOPS`, `36-PLATFORM-ARCHITECTURE-STANDARDS`, `37-EVENT-DRIVEN-ARCHITECTURE`, `38-IAM`, and appendices `A01`–`A33`.*

### 9.1 Hosting — the decision that unblocks everything

**Current constraint:** cPanel shared hosting with no Node, which is why `public/build` is committed to git and why the deploy script is a `git pull` + artisan cache rebuild.

That is a perfectly reasonable arrangement for a brochure site. It will not carry: queue workers (payment webhooks, notification fan-out, media processing), a scheduler beyond simple cron, Redis (cache/queue/session/locks), full-text search, or WebSockets for live updates.

**Recommendation:** move to a small VPS (2 vCPU / 4 GB is ample to start) before Phase 2, using Laravel Forge or Ploi for provisioning. Concretely this buys: Redis, real queue workers with Horizon, Node on the server (so `public/build` leaves git), zero-downtime deploys, and proper log/metric access.

**If the VPS move is rejected**, the plan still works but with named compromises: keep the database queue driver with a cron-driven `queue:work --stop-when-empty` every minute; keep committing built assets; use database cache/locks; use MySQL full-text instead of Meilisearch; poll instead of WebSockets. Say so explicitly in the ADR rather than discovering it during Phase 3.

### 9.2 Admin panel — adopt Filament

The hand-rolled Blade admin is ~20 view files for six models. The plan adds 25+ models. Rebuilding that by hand is months of CRUD.

**Recommendation: Filament v5** (current line — v5.8.x as of September 2026; requires Livewire 4 and supports Laravel 13, verified on Packagist). MIT-licensed, actively maintained, the default choice for Laravel admin in 2026, and it collapses standard CRUD from days to hours. Cost: the team must learn Livewire. Migrate incrementally — new modules (bookings, travellers, payments, journeys) in Filament first, existing trip/media/settings CRUD ported afterwards. *(v1.0 said v4; v4.13 also supports Laravel 13 but is the previous major — start on v5.)*

### 9.3 Authorisation

Replace the `is_admin` boolean with real roles and permissions (`spatie/laravel-permission`). **Staff roles** (nine, each a real Rihla job function — companion §17.1): Super Admin, Operations Manager, Booking Staff, Finance, Visa Staff, Pilgrim Support, Content Manager, Tour Leader, Reporting (read-only). Permissions are verbs (`booking.create`, `refund.approve`, `document.verify`, …) with separation of duties enforced in code — the same person may not request and approve one refund.

**Customer-side access is not role-based.** What a customer, traveller or family member can see is decided by their *relationship* to a booking (payer, participant, invited family) through policies, not by assigning them a role. A boolean cannot express any of this; neither can a flat role list that mixes staff functions with customer relationships.

**Status: staff side done** (commit `456abd6`). `spatie/laravel-permission` added; `App\Support\Access` holds the nine roles and the permission verbs; policies cover all seven models; every admin action authorises explicitly. A migration backfills Super Admin from `is_admin` and seeds the roles first, because a fresh database runs migrations before seeders.

Scoped deliberately: **only verbs for functionality that exists are defined.** Booking, refund, payment, visa and permit permissions belong with the features that introduce them — defining them now would produce inert strings that look enforced and are not. Booking Staff, Finance, Visa Staff and Pilgrim Support therefore hold nothing but `admin.access` today. They are seeded anyway so staff can be assigned now, and so the gap between a job function and what the software supports stays visible.

Two things found while doing it. The base `Controller` had no `AuthorizesRequests` trait, so `$this->authorize()` was an undefined method — a fatal error at the exact moment a permission check should happen (**D22**). And `authorizeResource()` is unusable on Laravel 13, because it calls `$this->middleware()`, which the framework removed from the base controller; each action authorises explicitly instead.

`is_admin` is **not dropped**. Until the backfill has run against production it is the only way back. Nothing reads it — a test asserts the flag alone grants nothing, so it cannot become a second source of truth that keeps access alive after a role is removed. Dropping it is a follow-up once production is confirmed migrated.

**Still open:** the customer side. It needs bookings to exist first, so it belongs with Phase 3.

### 9.4 Internationalisation — redesign now, cheaply

The current `*_dv` column pattern does not survive contact with packages, itineraries, learning modules, Ziyarah locations and articles. Adopt the **hybrid** the companion document settles on (§19): **translation tables** for the content entities that need per-locale slugs, SEO metadata or independent publication state (`packages`, `articles`, `locations`, `notification_templates`), and **JSON translatable columns** via `spatie/laravel-translatable` for CMS furniture (hero banners, why-features, guide steps, itinerary titles). Names already language-specific (`name_latin`, `name_dhivehi`, `name_arabic`) are data, not translations. Support en / dv / ar with full RTL. Doing this in Phase 1, while there are eight models, costs days; doing it in Phase 5 costs weeks. **Locale-in-URL (P0.7) is the prerequisite.**

### 9.5 Architecture standards — the useful 10% of appendices A01–A33

Adopt now, as short repo documents, not 50 manuals:

| Adopt | From | Form |
|---|---|---|
| Naming & database conventions | A01, A02 | One page in `docs/` + enforced by Pint/Larastan |
| API design standards | A03, `19-...` | One page; versioned `/api/v1`, consistent envelope, cursor pagination |
| ADR practice | A11 | `docs/adr/NNNN-title.md`, one per significant decision |
| Coding standards | A10, `36-...` | Pint config + Larastan level, in CI (already partly present) |
| Testing standards | A08, `49-...` | Test pyramid targets in §10.3 |
| Security engineering | A06, `20-...` | Checklist in §10.4 |
| Observability | A07, `39-...` | Laravel Pulse + Sentry; log levels and retention |
| Deployment & release | A09, `51-...` | Extend the existing deploy doc |

Defer (record the decision, do not build): event-driven architecture (A04, `37-...`), data lakehouse (A28), multi-tenancy (`47-...`), plugin marketplace (`55-...`), enterprise meta-model (META01), reference models RM01–RM10, reference architectures RA01–RA25. These describe an organisation with an architecture function. Rihla should revisit them if it franchises or white-labels.

### 9.6 AI features — scoped honestly

*Source: `18-AI-PLATFORM`, `54-AI-GOVERNANCE`, `A26`.*

The thread proposes ten AI assistants. Build **two**, well:

1. **Pilgrim assistant (RAG over Rihla's own content)** — answers about packages, visa/Nusuk steps, documents, payments, and religious guidance **strictly from scholar-approved Knowledge Centre content**, with citations and a hard "I'll connect you to an advisor" fallback. Never let it improvise rulings.
2. **Staff drafting assistant** — quotations, itinerary text, announcement translation (en/dv/ar) with human review before send.

Governance, in one page not forty: approved use cases, prohibited behaviours (no independent fatwa, no medical/legal advice, no personal data in prompts), human-in-the-loop for anything customer-facing, logged prompts/responses, and a visible "AI-assisted" label.

---

## 10. Non-functional requirements

### 10.1 Performance

| Metric | Target | Notes |
|---|---|---|
| LCP (mobile, 4G) | < 2.5 s | Homepage hero must not block it; use a poster image, lazy-load video |
| CLS | < 0.1 | Reserve space for hero, cards, images |
| INP | < 200 ms | Alpine is fine; avoid heavy JS on the homepage |
| Lighthouse performance (mobile) | ≥ 90 | `npm run lighthouse` already exists — put it in CI |
| Server response (TTFB) | < 400 ms | Cache package/departure queries; index them properly |
| Image delivery | WebP/AVIF, responsive `srcset`, CDN | Currently unoptimised |

### 10.2 Accessibility — WCAG 2.2 AA

Keyboard navigation throughout, visible focus states, screen-reader labels, adjustable text size, high-contrast mode, reduced-motion support, correct `lang`/`dir` per locale (the layout already switches `dir` — good), and Thaana font legibility at small sizes. The `skip to main content` link already present is a good sign; extend the discipline.

**Colour contrast is now done** (§4.5). Every interactive combination in the palette is verified against WCAG 2.2 AA, and the focus ring is a single consistent wine across the whole site — previously it was sky blue, green, gold, blue, indigo and grey depending on the screen. The contrast reference table is in [`BRAND.md`](BRAND.md) §3. What remains in this section is the non-colour half: keyboard order, ARIA labelling, and a screen-reader pass in both locales.

### 10.3 Testing

| Level | Target |
|---|---|
| Unit | Business rules: pricing, instalments, seat holds, readiness score |
| Feature | Every public route in both locales; full booking flow; payment webhook handling; portal authorisation (each role sees only its own data) |
| Regression | The homepage-translation assertion from P0.1 — permanently |
| Browser (later) | Booking flow and portal on mobile viewport |
| Coverage | No number worth policing; instead require that **every bug fixed gets a test** |

### 10.4 Security

Roles/permissions (§9.3) **— done**; audit log **— foundation done (`2e81a34`): create/update/delete on all eight models, secrets excluded, actor snapshotted so it survives staff deletion, read-only viewer behind `audit.viewAny`. Bookings, payments and documents join the audited list when they exist**; MFA for staff; session security and device management; encryption at rest for passport/identity documents; signed, expiring URLs for document access (never public storage paths); rate limiting on auth **— done (D35): registration, password reset (capped per caller *and* per address), reset submission, password confirmation and password update; login was already covered by `LoginRequest`**, booking and payment endpoints; CSRF on all forms (Laravel default — verify on the new AJAX paths); **security response headers — done (D36): `nosniff`, `SAMEORIGIN`, `strict-origin-when-cross-origin`, a `Permissions-Policy` denying the features the site does not use, HSTS over HTTPS only, and `X-Powered-By` removed.**

**Content-Security-Policy is deliberately not shipped yet.** The site has inline `<script>` and `<style>` blocks, and admin-chosen colours are written into inline `style` attributes, so an enforcing policy needs those moved onto nonces first. A policy loose enough to allow `'unsafe-inline'` would look like protection while providing almost none, which is worse than having none — it is the header a checklist marks green. Doing it properly is its own piece of work: nonce the inline blocks, move the database colours onto CSS custom properties, then enforce. HSTS is sent without `includeSubDomains` or `preload`, because both extend the promise to hosts this application does not control and preload is effectively permanent; audit log for every admin action on bookings, payments and documents; dependency scanning in CI; secrets only in `.env` (the deploy webhook secret pattern already in place is the right model); documented backup **and tested restore**.

Personal data: passports, photos, medical notes and payment records for minors and adults. Define retention and deletion policy, and honour deletion requests.

**Added in v1.1:**
- **Staging never holds unmasked production data.** `test.rihla.mv` auto-deploys from `main` and is reachable on the public internet. Once real passports and payments exist, production data must never be copied to it except through an anonymising export (fake names, scrubbed passport numbers, masked contacts). Write that export before Phase 3 ships, not after the first request to "just copy prod to test".
- **Backup before every production migration.** `pull-deploy-test.sh` runs `migrate --force` automatically, which is fine for test. The production promotion procedure must take a database snapshot first, and every migration in the booking domain must be either reversible or explicitly forward-fix-only in its ADR.
- **Email authentication.** Booking confirmations and payment receipts that land in spam are a support cost and a trust cost. Set SPF, DKIM and DMARC for `rihla.mv` before the first transactional email is sent from the platform, and send through a dedicated transactional provider, not the cPanel mail server.
- **Uptime monitoring on production.** The deploy workflow smoke-tests `test.rihla.mv` only. Point an external monitor (BetterStack / UptimeRobot free tier) at `https://rihla.mv/up` — the health route already exists in `bootstrap/app.php` — with WhatsApp/email alerts.
- **Dependency scanning** in CI (`composer audit`, `npm audit`) — added to P0.3.

### 10.5 The KPIs worth instrumenting

Booking conversion rate (visit → enquiry → booking); enquiry response time; deposit-to-full-payment conversion; seats sold per departure vs capacity; document-verification cycle time; permit-issued-before-departure rate (target 100%); learning-module completion among booked pilgrims; portal weekly-active pilgrims; family-portal engagement; repeat + referral share of bookings; NPS after return; per-journey margin.

---

## 11. Integrations, vendors & costs

### 11.1 Payments — BML Connect

`javaabu/bml-connect-laravel` (Packagist, v0.7.0 / Feb 2026). MVR + USD, 3-D Secure, cards + BML Pay + Apple/Google Pay. MDR **1%** since Nov 2025 (down from 2.5%). Merchant onboarding via BML's Merchant Portal. Budget a sandbox cycle — gateway onboarding is usually the long pole, start it early.

### 11.2 Messaging — WhatsApp Business Cloud API

No subscription; per-delivered-template pricing by category and country. Utility templates inside the 24-hour service window are free **until 1 October 2026**, after which service and utility messages inside the window are charged — model the cost before making WhatsApp the primary notification channel. Utility/authentication categories get volume discounts; marketing does not. Consider a BSP (adds ~$0.003–0.010/message) versus direct Cloud API.

Use it for: booking confirmation, payment reminders, document requests, visa/permit status, departure reminders, daily group announcements. Keep email as the durable channel for invoices and SMS as the emergency fallback.

### 11.3 Islamic content APIs

- **AlAdhan** — prayer times by coordinates/city with multiple calculation methods, and a Qibla direction API. Free, no key.
- **Islamic Network / UmmahAPI** — Qur'an, Hadith, duas, Hijri calendar; free, no key.

Cache aggressively and store canonical text locally; do not put a third-party API on the critical path of an offline-first Ziyarah guide.

### 11.4 Maps

Google Maps Platform for hotel location, walking distance/time to the Haram, Ziyarah locations and Street View. Costs scale with loads — render static maps where interaction is not needed, and pre-compute walking distances into the `hotels` table rather than calling the Distance Matrix API per page view.

### 11.5 Laravel packages worth adopting

Laravel 13 compatibility verified against Packagist on 2026-09-17:

| Need | Package | Laravel 13 | Note |
|---|---|---|---|
| Admin panel | `filament/filament` **v5** (5.8.x) | ✓ (`illuminate/contracts ^11.28\|^12\|^13`) | Livewire 4 |
| Roles & permissions | `spatie/laravel-permission` 8.x | ✓ | PHP ^8.3 |
| Translations (JSON) | `spatie/laravel-translatable` 6.x | ✓ | For CMS furniture only — see §9.4 |
| Media conversions | `spatie/laravel-medialibrary` 11.x | ✓ | Replaces the hand-rolled `Media` model; also fixes D9 |
| Sitemap | `spatie/laravel-sitemap` 8.x | ✓ | **Requires PHP ^8.4.** `composer.json` says `^8.3`; the deploy scripts put `ea-php84` on the PATH, suggesting the server already runs 8.4 — confirm, then bump `composer.json`, or pin sitemap 7.x |
| Backups | `spatie/laravel-backup` 10.x | ✓ | Ship backups off-box (S3-compatible), not to the same cPanel disk |
| Payments | `javaabu/bml-connect-laravel` 0.7 | ✓ | Broad constraint (`^5.5 … ^13`) — read the source before trusting edge cases |
| Search | Laravel Scout + Meilisearch (VPS) or MySQL full-text (shared hosting) |
| Monitoring | Laravel Pulse + Sentry |
| PDFs | `barryvdh/laravel-dompdf` (already present) |

### 11.6 Indicative recurring cost

| Item | Monthly (USD) |
|---|---|
| VPS (2 vCPU / 4 GB) + backups | 25–45 |
| Forge or Ploi | 12–20 |
| Sentry (team) | 0–29 |
| WhatsApp templates (~2,000/mo) | 20–60 |
| Google Maps | 0–50 (with static maps + caching) |
| Email (Postmark/SES) | 0–15 |
| Object storage (documents/media) | 5–20 |
| **Total** | **~65–240** |

Payment processing is transactional at 1% MDR, not a fixed cost.

---

## 12. Phased roadmap

Estimates assume **one full-time Laravel developer** plus the owner for content and decisions. Add ~40% if the developer is part-time; halve the elapsed time (not the effort) with two developers on separate workstreams.

| Phase | Outcome | Contents | Effort |
|---|---|---|---|
| **P0 — Stabilise** | The live site stops embarrassing itself | §3: translations, demo content, CI (incl. MySQL job **[R-2]**), cleanups, locale-prefixed routing **[R-1]**, SEO essentials, PWA wiring | **4–7 days** |
| **1 — Foundations** | Ready to build on | i18n redesign (§9.4), ~~roles/permissions + policies (§9.3)~~ **— staff side done (`456abd6`); customer-side relationships wait for bookings (Phase 3)**, ~~audit-log foundation~~ **— done (`2e81a34`)**, Filament adoption (§9.2), ~~design-system pass~~ **— colour system done (§4.5) and typography done (`f30291b`); spacing remains**, hosting decision + move (§9.1), media library, observability | **3.5–5.5 weeks** |
| **2 — Public website** | A site that sells | IA + homepage rebuild (§4.2), package/departure model (§5.1), comparison, hotel distance explorer, itinerary, seat bars, countdowns, leader/scholar profiles, trust dashboard, WhatsApp CTA, cost calculator, blog, full SEO | **6–8 weeks** |
| **3 — Booking & payments** | Money online, spreadsheets retired | Booking flow (§5.2), BML Connect (§5.3), instalments, invoices, document wallet **with versioning** (§5.5) **[R-8]**, **visa applications (§5.4a)** and **Nusuk permits (§5.4b)** as separate deliverables **[R-4]**, minimal CRM (§8.1), **import of historical customers/pilgrims from spreadsheets with duplicate detection** (companion §5.3), Pilgrim Portal v1 (§6.1) | **8–10 weeks** |
| **4 — Operations & portals** | The journey runs on the platform | Journey planning & capacity (§8.2), room allocation, operations (§8.3), Tour Leader Portal (§6.3), Family Portal (§6.2), safety & emergency (§6.5), notifications | **8–10 weeks** |
| **5 — Knowledge & learning** | The differentiator ships | Knowledge Centre (§7.1), Ziyarah Guide with offline (§7.2), Learning Academy (§7.3), Scholar Portal (§6.4), readiness score, full CRM + finance (§8.1, §8.4) | **10–12 weeks** |
| **6 — Intelligence** | Decisions from data | BI dashboards (§8.5), forecasting, pilgrim AI assistant (§9.6), staff drafting assistant, personalisation | **6–8 weeks** |
| **7 — Expansion** | New revenue | Hajj, partner/B2B portal, loyalty & referrals, Arabic locale, native app shells, marketplace | **open-ended** |

**MVP definition (if the timeline must compress):** P0 + Phase 2 + the booking half of Phase 3 (booking flow, deposit payment, document upload, permit tracker, pilgrim dashboard). That is a sellable platform in roughly four months and it is where the compounding starts.

**Release discipline throughout:** every phase ends with CI green, a test for each new business rule, an ADR for each significant decision, and a deploy to test.rihla.mv verified before production. Production stays manually promoted — do not extend the auto-deploy webhook to rihla.mv.

---

## 13. Decisions needed from you

These block or reshape the plan; everything else I can proceed on with stated assumptions.

1. **Hosting (§9.1)** — move to a VPS before Phase 2, or stay on cPanel shared hosting and accept the named compromises? *Recommendation: move.*
2. **Admin (§9.2)** — adopt Filament, or keep hand-rolled Blade admin? *Recommendation: Filament.*
3. **Scope ambition** — the MVP-in-four-months path, or the full Phase 1–6 programme (~10–12 months at one developer)?
4. **Payments** — is BML merchant onboarding already in progress? It gates Phase 3 and has the longest external lead time.
5. **Nusuk (§2.3, §5.4b)** — **[R-6]** is Rihla an approved Nusuk-integrated operator, or does it work through a licensed intermediary, and who owns permit issuance operationally? This decides whether 5.4b is a staff workflow with forms or a system integration, and it materially changes the Phase 3 estimate. Currently modelled as a manual staff workflow with an optional API later.
6. **Content ownership** — who writes and who *religiously reviews* the Knowledge Centre and Academy? Phase 5 is content-bound, not code-bound.
7. **[R-5] Production runtime versions** — which MySQL/MariaDB version does the cPanel account run, and is PHP really 8.4 (the deploy scripts reference `ea-php84`)? The DB version matters because The capacity invariant `capacity_held + capacity_confirmed <= capacity_total` is enforced with a CHECK constraint, which needs MySQL 8.0.16+ or MariaDB 10.2+. On an older engine the row lock becomes the sole defence and that must be recorded deliberately. Verify before the booking tables are created.
8. **[R-1] Locale in the URL (P0.7)** — approve moving locale into the route. Without it the P0.5 SEO work ships tags that do nothing.
9. **Logo** — the colour system is live but the mark is untouched. Three candidates exist (`BRAND.md` §4): recolour the current mark, the dhoni with the Kaaba, or the two-sail dhoni alone. Whichever is chosen, the asset set is the same job and it clears six broken references — an empty `favicon.ico` and five 404s. Independent of everything else in this plan.

---

## 14. Risks

| Risk | Impact | Mitigation |
|---|---|---|
| Scope collapse under the weight of the source spec | High | This plan's phasing; refuse Phase 6–7 features until Phase 3 ships |
| Nusuk policy changes mid-build | High | Model permits as first-class data with a status machine; isolate Saudi-specific rules behind one service class |
| BML onboarding delays | High | Start onboarding in Phase 1; build against the sandbox; keep bank transfer as a working fallback |
| Shared-hosting ceiling hit mid-Phase 3 | Medium | Decide hosting in Phase 1 (§13.1) |
| Single-developer bus factor | High | ADRs, tests, and this document kept current; no undocumented server-side manual steps |
| Religious content accuracy | High | Mandatory scholar review workflow; sources required per article; label weak narrations |
| Personal data breach (passports) | High | Encryption at rest, signed URLs, audit log, least privilege, tested restores |
| Auto-deploy pushes a broken `main` to test | Medium | CI required before merge (P0.3) — currently the gap that lets this happen |
| WhatsApp cost change Oct 2026 | Low–Medium | Model costs before committing; keep email/SMS fallbacks |
| Real passport data copied to the public staging site | High | Anonymising export only (§10.4); never a raw dump |
| Historical pilgrim data imported with duplicates and bad passports | Medium | Import in dry-run mode with a review queue; duplicate detection with human merge (companion §5.3) |
| Package major-version churn (Filament v5, Livewire 4, sitemap needing PHP 8.4) | Low | Versions pinned in `composer.json`; upgrade in their own PRs with CI green |

---

## Appendix A — Coverage map of the 107 source documents

Every document from the ChatGPT thread, mapped. "Deferred" means deliberately not built now, with the reason.

### Core specification series

| Source document | Covered in |
|---|---|
| `00-VISION` | §2 |
| `01-INFORMATION-ARCHITECTURE` | §4.1 |
| `02-DESIGN-SYSTEM` | §4, Phase 1 (§12) |
| `03-HOMEPAGE` | §4.2, §4.3 |
| `04-PACKAGES-BOOKING` | §4.3 (browse), §5.1–5.2 (book) |
| `05-PILGRIM-PORTAL` | §6.1 |
| `06-FAMILY-PORTAL` | §6.2 |
| `07-TOUR-LEADER-PORTAL` | §6.3 |
| `08-SCHOLAR-PORTAL` | §6.4 |
| `09-ADMIN-PORTAL` | §9.2, §8 |
| `10-RELIGIOUS-HISTORICAL-KNOWLEDGE-CENTRE` | §7.1 |
| `11-INTERACTIVE-ZIYARAH-GUIDE` | §7.2 |
| `12-UMRAH-LEARNING-ACADEMY` | §7.3 |
| `13-COMMUNICATION-COMMUNITY` | §6.2, §6.3, §11.2 — *community forum deferred* |
| `14-CRM-SALES-CUSTOMER-LIFECYCLE` | §8.1 |
| `15-OPERATIONS-MANAGEMENT` | §8.3 |
| `16-FINANCE-ACCOUNTING-PAYMENTS` | §5.3, §8.4 |
| `17-BUSINESS-INTELLIGENCE-ANALYTICS` | §8.5, §10.5 |
| `18-AI-PLATFORM-INTELLIGENT-AUTOMATION` | §9.6 — *scoped from 10 assistants to 2* |
| `19-INTEGRATION-API-DEVELOPER-PLATFORM` | §9.5, §11 — *public developer portal & SDKs deferred* |
| `20-SECURITY-PRIVACY-GOVERNANCE` | §10.4 |
| `21-MOBILE-APPLICATIONS-PWA` / `21-MOBILE-APPS-OFFLINE` | §3 P0.6, §6, Phase 7 native shells |
| `22-DEPLOYMENT-INFRASTRUCTURE-DEVOPS` | §9.1, §12 |
| `23-PRODUCT-ROADMAP-IMPLEMENTATION-PLAN` | §12 — *re-sequenced* |
| `24-MARKETING-CMS-SEO-DIGITAL-EXPERIENCE` | §3 P0.5, §4.4 — *visual page builder deferred* |
| `25-DOCUMENT-MANAGEMENT-DIGITAL-WALLET` | §5.5 |
| `26-PARTNER-ECOSYSTEM-B2B-AGENT-PORTAL` | §8.6, Phase 7 |
| `27-LOYALTY-MEMBERSHIP-REFERRALS` | §8.6, Phase 7 |
| `28-SUPPLIER-VENDOR-PROCUREMENT` | §8.6 |
| `29-JOURNEY-PLANNING-CAPACITY-RESOURCE` | §8.2 |
| `30-REAL-TIME-OPERATIONS-COMMAND-CENTER` | §8.3 — *deferred to Phase 6+: presumes staffing Rihla lacks* |
| `31-SAFETY-HEALTH-EMERGENCY-MANAGEMENT` | §6.5 |
| `32-EXECUTIVE-MANAGEMENT-OKR-STRATEGY` | §8.6 — *deferred* |
| `33-CONFIGURATION-FEATURE-FLAGS-BUSINESS-RULES` | §9.5 — *feature flags yes; full rules engine deferred* |
| `34-WORKFLOW-PROCESS-ORCHESTRATION` | §5.4, §8.3 as explicit state machines — *generic engine deferred* |
| `35-MASTER-DATA-MANAGEMENT-DOMAIN-MODEL` | Companion document §3–§4 (Appendix C is superseded) |
| `36-PLATFORM-ARCHITECTURE-STANDARDS` | §9.5 |
| `37-EVENT-DRIVEN-ARCHITECTURE-MESSAGING` | *Deferred* — Laravel events/queues suffice at this scale |
| `38-IDENTITY-ACCESS-MANAGEMENT` | §9.3, §10.4 |
| `39-ENTERPRISE-OBSERVABILITY-MONITORING` | §9.5, §11.5 |
| `40-DATA-GOVERNANCE-LIFECYCLE-RECORDS` | §10.4 (retention/deletion) |
| `41-ENTERPRISE-NOTIFICATION-COMMUNICATION` | §11.2 |
| `42-UNIFIED-SEARCH-KNOWLEDGE-DISCOVERY` | §11.5 (Scout) |
| `43-ENTERPRISE-DIGITAL-ASSET-MANAGEMENT` | §11.5 (medialibrary) |
| `44-ENTERPRISE-SCHEDULING-CALENDAR-RESOURCE` | §8.2 |
| `45-ENTERPRISE-FORMS-DYNAMIC-DATA-COLLECTION` | Filament forms (§9.2) — *builder deferred* |
| `46-ENTERPRISE-REPORTING-DOCUMENT-GENERATION` | §5.3 (PDFs), §8.5 |
| `47-ENTERPRISE-MULTI-TENANCY` | *Deferred* — revisit only for franchise/white-label |
| `48-BUSINESS-CONTINUITY-DISASTER-RECOVERY` | §10.4 (backups + tested restore) |
| `49-ENTERPRISE-TESTING-QUALITY-ASSURANCE` | §10.3 |
| `50-DATA-MIGRATION-IMPORT-EXPORT` | §5.1 migration path; CSV import for legacy pilgrim records |
| `51-PRODUCT-LIFECYCLE-RELEASE-MANAGEMENT` | §12 release discipline |
| `52-AUDIT-COMPLIANCE-INTERNAL-CONTROLS` | §10.4, §2.3 (Ministry compliance) |
| `53-LOCALIZATION-I18N-REGIONALIZATION` (×2) | §9.4 |
| `54-AI-GOVERNANCE-RESPONSIBLE-AI` | §9.6 |
| `55-EXTENSION-PLUGIN-MARKETPLACE` | *Deferred* |
| `56-FUTURE-EXPANSION-ROADMAP` | Phase 7 |
| `57-REFERENCE-ARCHITECTURE-MASTER-SUMMARY` | This document serves the role |

### Appendices A01–A33, META01, RM/RA series

`A01` data dictionary, `A02` database standards, `A03` API standards, `A06` security engineering, `A07` observability, `A08` testing, `A09` DevOps, `A10` coding standards, `A11` ADRs → **adopted in condensed form** (§9.5).

`A04` event-driven, `A05` workflow, `A12` data governance, `A13` integration, `A14` continuity, `A15` UX standards, `A16` config/feature flags, `A17` reporting, `A18` notification, `A19` search, `A20` IAM/zero-trust, `A21` audit, `A22` rules engine, `A23` scheduling, `A24` file storage, `A25` API gateway, `A26` AI/ML, `A27` service management, `A28` data lakehouse, `A29` domain model, `A30` platform roadmap, `A31` governance/operating model, `A32` reference models, `A33` architecture repository, `META01` meta-model, `RM01–RM10`, `RA01–RA25` → **deferred**, with the decision recorded here. These describe a company with a dedicated architecture function. The source thread's own final messages reach the same conclusion: *"What remains isn't new standards — it is implementation."*

---

## Appendix B — Reference sites, APIs and reading

**Saudi platform & rules (mandatory reading before Phase 3)**
- [Nusuk App Guide 2026 — HalalBooking](https://halalbooking.com/en/umrah/plan/nusuk)
- [Umrah Visa Guide 2026 — HalalBooking](https://halalbooking.com/en/umrah/plan/visa-guide)
- [Nusuk App 2026 — Wego](https://blog.wego.com/nusuk-app/)
- [Nusuk — Wikipedia](https://en.wikipedia.org/wiki/Nusuk)
- [Umrah 2026 Visa Rules & Entry Dates](https://loveumrah.com/blog/umrah-2026-visa-rules-entry-dates)
- [Umrah Visa & Nusuk Platform Guide — Makarem](https://makaremhotels.com/en/news/umrah-visa-and-the-nusuk-platform)

**Competitor / comparable platforms to study**
- [Umrahme](https://www.umrahme.com/home/en-eu) — package customisation, [top-reviewed packages](https://www.umrahme.com/packages/en-us/top-reviewed-umrah-packages-booking) (social proof done well)
- [Funadiq](https://www.funadiq.com/) — Ministry-appointed Umrah OTA; visa issuance tied to hotel booking
- [Umrah Companions](https://umrahcompanions.com/) — 700+ hotel network, packaging presentation
- [HalalBooking — Umrah hotels](https://halalbooking.com/en/umrah-hotels) — hotel comparison UX
- [UmrahBooking](https://www.umrahbooking.com/) — hotels + Haramain train + transfers + eSIM bundling
- [Choosing Umrah booking software — PHPTravels](https://phptravels.com/blog/how-to-choose-the-best-umrah-booking-software) — useful checklist of operator workflows

**Maldives context**
- [BML Merchant Portal & mPOS](https://www.bankofmaldives.com.mv/merchant-portal-mpos) · [BML merchant services](https://www.bankofmaldives.com.mv/business-and-sme/business-banking-services/merchant-services)
- [`javaabu/bml-connect-laravel`](https://packagist.org/packages/javaabu/bml-connect-laravel)
- [Payment gateways in Maldives 2026](https://nowpayments.io/blog/payment-gateways-maldives)
- [Maldives authorises Umrah companies — Edition](https://edition.mv/news/9296) · [Approved Umrah agents list — MMTV](https://en.mmtv.mv/373) · [Ministry warning on penalties — Iruvaru](https://en.iruvaru.com/minister-shaheem-warns-umrah-tour-operators-of-severe-penalties-for-violations/)
- [Ministry of Islamic Affairs (Maldives)](https://en.wikipedia.org/wiki/Ministry_of_Islamic_Affairs_(Maldives))

**APIs**
- [AlAdhan Prayer Times API](https://aladhan.com/prayer-times-api) · [AlAdhan Qibla API](https://aladhan.com/qibla-api)
- [Islamic Network APIs](https://islamic.network/api/) · [UmmahAPI](https://ummahapi.com/) · [Best free Islamic APIs 2026](https://ummahapi.com/blog/best-free-islamic-apis-2026)

**Engineering**
- [Filament — best Laravel admin panel 2026](https://mantraideas.com/filament-php-laravel-admin-panel/) · [Filament guide](https://medium.com/@ElevenDev_MuslimCoder/complete-guide-to-laravel-filament-admin-panel-from-zero-to-production-ready-dashboard-f2d7ac10d66c) · [Admin panel comparison](https://backpackforlaravel.com/articles/basics/best-laravel-admin-panel)
- [WhatsApp Business API pricing 2026](https://respond.io/blog/whatsapp-business-api-pricing) · [2026 changes](https://blueticks.co/blog/whatsapp-business-api-pricing-2026)
- [schema.org/TouristTrip](https://schema.org/TouristTrip) · [Tour operator schema guide](https://hamzaliaqat.com/blog/tour-operator-schema-markup-guide) · [Travel schema strategies](https://blackbearmedia.io/11-powerful-schema-markup-strategies-for-travel-websites/)

---

## Appendix C — Proposed data model

> **[R-3] Superseded.** The sketch that stood here has been replaced in full by
> [`DOMAIN_MODEL_AND_BOOKING_ENGINE.md`](DOMAIN_MODEL_AND_BOOKING_ENGINE.md), which specifies every entity,
> its fields, the aggregate boundaries, the booking state machine, capacity locking, the payment model and a
> Mermaid ERD. Keeping a second, shorter model here would only drift out of step with it.
>
> Three corrections that document makes to the original sketch, worth knowing without opening it:
>
> - `visa_status` no longer lives on a combined `permits` table — **visa applications and Nusuk permits are separate aggregates** (§5.4a / §5.4b above).
> - `bookings` gains **snapshot fields** (`package_snapshot`, `terms_version`, and per-traveller identity with a *masked* passport number), so later edits to a package cannot alter a contract already agreed.
> - Booking status covers the **commercial lifecycle only**; document, visa and permit readiness is a computed projection over their own records, never a stored booking field.

---

*Prepared 2026-09-17 from the "Umrah Website Upgrade Ideas" thread (214 messages / 107 documents), an audit of `ampilarey/rihla@da9b36d`, live inspection of rihla.mv and test.rihla.mv, and 2026 market research. Section §1.2 findings are verified against the running site; effort estimates in §12 are assumptions and should be re-based once §13 is answered.*

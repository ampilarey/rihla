# Rihla Platform — Website Upgrade Plan

**Version:** 1.41
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
| 1.41 | **Full layout audit, every public page rendered and read.** The headline defect was invisible to every test and obvious in a screenshot: `.card` carried no padding, so on every page the heading of every card sat flush against its left border — measured at 0px in the browser (D88). Also found: the hero's primary button was wine on the wine hero, so the secondary outranked it (D89); the homepage "Learn More" pointed at `/about`, which does not exist (D90); Contact rendered an empty "Follow Us" card and sold "hidden gems" and "your travel style" on an Umrah page (D91); one trip or one social link sat alone at the left of a three-column grid (D92). Colour enhancement stays inside the palette: gold takes the primary action it is permitted (with ink text), section headings share the 404 page's gold-rule motif, and the home page alternates cream and cream-deep instead of white and near-white. One finding withdrawn on checking: `gray-*` here is already remapped to a warm scale, so the ~950 grey classes are on-palette. |
| 1.40 | **rihla.mv is live on this work** — five weeks of it, promoted by hand because the tooling ships with the release it deploys. The homepage now reads "Umrah Made Simple for Maldivian Pilgrims" instead of `hero_title`, the resort holidays are gone, and all four security headers are present. The first phone screenshot of it found D87: the fixed WhatsApp and Catalog buttons are wine-filled and float over wine sections, where they have **1.00:1** against their own backdrop — the circle vanishes and the icon hangs in mid-air. |
| 1.39 | **The three remaining invented social links cleared, on the owner's instruction**, and two things built for the people who have to finish this work. `rihla:translations:export` writes every Dhivehi string the site needs into a spreadsheet — 281 of them, 114 missing entirely and 167 carrying a value no speaker has checked — and `rihla:translations:import` reads it back, **refusing** any sheet in which unrelated strings would render identically, which is the rule that caught the original fabrication. `docs/DHIVEHI_TRANSLATION.md` is the brief for a translator; `docs/PRODUCTION_PROMOTION.md` now opens with what rihla.mv is actually serving today — `hero_title` and `cta_whatsapp` as raw keys, and an island-hopping beach holiday as a current trip, both of them defects D1 and D2 still live five weeks on — followed by the exact commands for the first promotion. |
| 1.38 | **The fabricated Dhivehi reached the homepage too** (D86). The "Why Choose Rihla" block's three Dhivehi feature titles were 24–26 characters long and shared a **19-character suffix**; two of the three bodies shared **72 of their 77 characters**, where the English copy for the same three points — guides, accommodation, pricing — shares two and twelve, which is ordinary overlap. Removed, with `HomeController` falling back to the English section so `/dv` keeps the block, and the per-locale cache cleared by the migration so the deletion takes effect immediately rather than in an hour. Two of my own findings were **withdrawn** on checking: the feature descriptions are not empty (I queried `description`, and the column is `text`), and the view does render them. |
| 1.37 | **The social links were four guesses, and one was dead** (D85). `SettingsSeeder` typed a single handle into four platforms — `facebook.com/rihlatravels`, `instagram.com/rihlatravels`, `tiktok.com/@rihlatravels`, `viber.com/rihlatravels` — and none was ever checked. TikTok answers "Page not available", so the public social page had been sending visitors to a profile that does not exist. Facebook and Instagram sit behind login walls and Viber returns a generic page for any path, so **no script can confirm the other three** — only the owner can. The dead one is cleared, the seeder invents none of them any more, and `rihla:preflight` now *warns* (not fails) while any still holds the seeded guess, so the decision reaches the person who can make it instead of being made silently in either direction. |
| 1.36 | **The Dhivehi was partly fabricated, and the Umrah guide was the worst of it** (D81–D83). Ten seeded Dhivehi guide steps carried the *same* `dua_text` and the same `reference_text` as each other, where the ten English steps carry ten real supplications and citations like "Quran 2:196" — so a Dhivehi pilgrim reading the du'a for Niyyah, Ihram, Tawaf and Sa'i was given one identical meaningless sentence each time, under a heading claiming it was the supplication for that rite. `resources/lang/dv/guide.php` held 19 keys with 3 distinct values; one word appeared **31 times** on the live Dhivehi guide page as the label for Step, Checklist, Fiqh Notes, Du'a, Print, Previous Step and ten more. `dv/messages.php` had 91 keys sharing a value with an unrelated key. None of it was paraphrased — inventing religious text would be a worse defect than the one being fixed — so it is removed and the guide falls back to English, which is correct. Also corrected: `AGENTS.md` still listed test failures as expected that had long since been fixed (D84). |
| 1.35 | **Content-Security-Policy shipped, enforcing** (§10.2). `script-src` names a per-request nonce and **no** `'unsafe-inline'`, so a browser runs the scripts this application marked and refuses every other one — including any arriving inside a trip title, a guide step or a media caption. Verified in Chrome across 22 public and admin pages with the policy enforced: zero violations, and the detector was proved by planting a violation first. Two concessions are recorded rather than hidden: `'unsafe-eval'` for Alpine's standard build, and `'unsafe-inline'` in `style-src` only, because admin-chosen colours are written into inline `style` attributes that no nonce can cover. Found while writing it: SortableJS was loaded from jsDelivr with no integrity attribute on the admin page that reorders hero banners (D80) — it ships with the application now, so the policy needs no third-party script origin at all. |
| 1.34 | **A media item with an apostrophe in its title was deleted without asking** (D76). The delete button built a JavaScript string literal out of the title, inside an HTML attribute; Blade escapes an apostrophe to `&#039;` and the HTML parser turns it back before JavaScript sees it, so the handler never compiled. Checked in a browser: `onclick` stayed `null`, and a handler that does not compile cannot `return false` — the button was still `type="submit"`, so the form went through. All 36 inline `onclick` and `onsubmit` attributes are gone, which is also the prerequisite for a Content-Security-Policy. Found alongside: nineteen `console.log` calls shipping to production, one of them printing the CSRF token on every admin page load (D77); two guest layouts both answering to `<x-guest-layout>`, with the worse one winning (D78); and a deploy that leaves a stale autoloader classmap whenever a class is deleted (D79). |
| 1.33 | **The test deploy raced the test suite, and usually won** (D73). `deploy-test-immediate.yml` fired on the push to `main`, so a merge with failing tests was live on `test.rihla.mv` before GitHub had finished saying it was broken — the risk register's own "currently the gap that lets this happen". It now runs on CI concluding successfully. Found alongside: the webhook answers `202` the moment it spawns the pull script, so a deploy that failed on the server left the old code serving and reported green (D74) — the smoke check curled the homepage, which the old code answers just as well. `/api/health` now reports the running commit to a caller holding the deploy secret, and the workflow polls until it sees the commit it deployed. The webhook itself — the only endpoint in this application that runs shell commands — had no test of any kind (D75). |
| 1.32 | **A production promotion path exists** — the reason five weeks of work has sat on test. `rihla:backup`, `rihla:preflight` and `scripts/deploy-production.sh`, wired to nothing so promotion stays a decision someone makes. The script refuses to run without a backup it has opened and checked (`rihla:backup:verify` — gzip integrity, mysqldump's completion marker, every live table present), refuses to move production backwards, and cannot seed. `docs/PRODUCTION_PROMOTION.md` is the checklist. |
| 1.31 | **The social page embedded a YouTube playlist that does not exist** (D72) — `PLxxxxxxxxxx`, the third placeholder found live, and the one that mattered most: `SettingsSeeder` has no production guard because it seeds real configuration. The test now looks for the *shape* of a placeholder rather than the instance. A sweep of the rest of the codebase found nothing else, and the mail From address was checked and is correct. |
| 1.30 | **The trips were selling a honeymoon** (D71) — "Luxury Resort Experience: overwater villas, private beaches, perfect for honeymooners and luxury travelers", live on an Umrah operator's site, each trip on its own indexed URL. Same defect as the gallery, in the more prominent place, and missed when the gallery was cleaned. |
| 1.29 | **First visual audit of the admin panel** — it had never been looked at. The signed-in navigation was losing links off the edge of the screen between 768px and 1279px (D69): three of them below 1000px, silently, with no scrollbar. Visitor call-to-actions were also appearing on the staff panel (D70). |
| 1.28 | D66 reopened and finished: the seeder fix stopped the placeholder videos being *created*, but deploys run `migrate --force` and not `db:seed`, so the rows already written stayed in the test database and stayed on the page. A migration deletes them by the exact values the seeder wrote. Verified against the live site rather than assumed — which is how the gap was found. |
| 1.27 | **First visual audit of the rendered site** — every public page screenshotted at a true 390px viewport in a real browser. Found D66 (an Umrah operator's gallery playing Rick Astley and Gangnam Style, live on the public test site), D67 (every gallery photo rendering as a broken-image icon) and D68 (the guide's Print and Download buttons showing as two unlabelled icons on mobile). Two false alarms of my own are recorded with them. |
| 1.26 | **The logo carries the company name again** (D65) — set beneath the dhoni and justified to its width, as real text rather than artwork. A browser screenshot during the work caught the name rendering ink-on-ink in the footer, the same defect as the hull and for the same reason. |
| 1.25 | **Three defects found in one phone screenshot of the footer** (D62–D64): the logo's hull is ink and so is the footer, so the hull vanished and the logo rendered as two sails above nothing; the tagline was `text-gray-600` on `bg-ink` at **1.96:1**, unreadable; and the floating buttons sat on top of the last line of the page. All three were invisible to a test suite that checked structure and markup but never looked at a rendered page. |
| 1.24 | **The dhoni is the logo** (D61). Confirmed by the owner as final, it now appears everywhere a logo does — header, footer, login, tab, home screen, and the social preview card. One 461-byte vector covers every size; the Kaaba-and-calligraphy wordmark is retired but kept, in both its original and recoloured form. |
| 1.23 | **The wordmark was the last thing on the site still in the pre-rebrand colours** (D60) — `#097EDD` bright blue and pure black, neither in the palette, at the top of every page. Recoloured into wine and ink with no shape changed; the untouched original is kept and asserted unchanged. This is the answer to "still I see the old logo", asked four times: the logo genuinely had not changed. |
| 1.22 | **The dhoni mark is adopted as the app and browser icon** (D59). Its vector geometry was extracted from the palette document rather than traced, and the whole icon set — favicon, .ico, Apple touch icon, PWA icons and maskable variants — is now cut from that one 461-byte master. The header keeps the wordmark: the mark carries no company name. `docs/brand/Rihla-Palette.pdf` is committed as the colour source of truth, and every token in it was verified against `tailwind.config.js` value by value. |
| 1.21 | **The logo was 1.44 MB and loaded twice per page** (D56) — 6250 × 2976, drawn 80 pixels tall, seventeen times the weight of the whole CSS and JavaScript bundle. Resampled from the same artwork to 5 KB; the original is untouched and still shipped. The header's `w-50` was not a Tailwind class and emitted no CSS (D57), and 30 images carried no loading or decoding hint (D58). |
| 1.20 | **§10.2's other half done — the non-colour side of WCAG 2.2 AA** (D51–D55). 105 icons were exposed to screen readers with no name; six controls lost their only label below the `sm` breakpoint, on the phones most of this site's visitors use; the trips tabs looked like tabs but carried none of the wiring; the header never said which page you were on; and headings skipped a level on three pages. `AccessibilityTest` checks all of it against the rendered DOM of twelve pages. |
| 1.19 | **The business's own phone number had fourteen homes** (D46). Twelve hard-coded copies ignored Admin → Settings entirely, one was the literal placeholder `1234567890` on the live guide page (D47), and one was the `telephone` in the structured data Google caches. All of them now read `App\Support\Contact`. Found alongside: nine identical `settings` queries per page (D48), a settings row missing a key taking down every public page (D49), and twelve new-tab links without `rel="noopener"` (D50). |
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
| D46 | ~~**High**~~ **fixed** | **The WhatsApp number was written out by hand in fourteen places.** Twelve hard-coded `9607972434` and never consulted the number in Admin → Settings — among them the floating button on every page, the sticky contact bar and the footer, which also printed `+960 797 2434` as literal text beside a link that could disagree with it. Changing the number in the admin panel moved four links and left the rest on the old one: a defect nobody notices until the enquiries stop. The thirteenth was `Seo::CONTACT_PHONE`, the `telephone` in the structured data Google caches and shows in the knowledge panel. All of them now read `App\Support\Contact`. The admin field's own hint said "without + or country code" while the placeholder beside it showed `9607972434`, which is the country code followed by the number — anyone who followed the hint would have broken every link. Five components under `resources/views/components/ui/` were referenced by nothing and carried the fourteenth copy; they were deleted. | `app/Support/Contact.php`, 9 views, `app/Support/Seo.php` | No single owner for a business fact |
| D47 | ~~**High**~~ **fixed** | **The guide's "I need help" button pointed at a placeholder.** It read `config('app.whatsapp', '1234567890')`, and no such config key has ever existed — not in `config/app.php`, not anywhere. The default in the second argument hid it completely, so the live button on the most-read page of the site, in both languages, opened a chat with `1234567890`. | `resources/views/pages/guide.blade.php` | A config default masking a missing key |
| D48 | ~~**Medium**~~ **fixed** | `Setting::getSocialSettings()` queried on every call and every page calls it several times — the footer, the contact bar, the floating button and `Seo::organization()` all want the same row. The contact page ran **nine identical selects** against `settings` to render once, which was its entire query budget. Memoised per request and dropped on any write. | `app/Models/Setting.php` | A read modelled as a query, not a value |
| D49 | ~~**Medium**~~ **fixed** | **A settings row missing one key took down every public page.** The stored value is a free-form JSON blob and the views read it with direct array access (`$socialSettings['facebook_url']`, no fallback), so a partial write — a seeder, a partial form post, a future partial-update screen — produced `Undefined array key` on every request. `getSocialSettings()` now merges over a declared default shape. | `app/Models/Setting.php` | Shape hoped for rather than guaranteed |
| D50 | ~~**Low**~~ **fixed** | Twelve links opened a new tab with no `rel`, handing `window.opener` to the destination. Found by a DOM-level audit of the rendered pages. | 6 views | — |
| D51 | ~~**Medium**~~ **fixed** | **105 icons were announced to screen readers as unnamed graphics**, 117 of them on a single render of the guide page. Every one sits beside text or inside a labelled control, so every one is decorative; they now carry `aria-hidden` and `focusable="false"`. | 20 views | Icons treated as pictures, not as decoration |
| D52 | ~~**High**~~ **fixed** | **Six controls had no name on a phone.** Their labels sat in a `hidden sm:inline` span, and Tailwind's `hidden` is `display: none` — which takes text out of the accessibility tree as well as off the screen. The three header CTAs (Message us, Call us, Browse Catalog) and the guide's Print and Download PDF buttons were unlabelled icons below the `sm` breakpoint; three social links on the contact page and the guide's own WhatsApp button had no text at any width. Rated High because Maldivian traffic is overwhelmingly mobile, so the broken case was the common one. | `layouts/app`, `pages/{guide,contact}`, `components/whatsapp-fab` | A responsive utility mistaken for a visual one |
| D53 | ~~**Medium**~~ **fixed** | **The trips tabs were three unrelated buttons.** No `role`, no `aria-selected`, nothing linking a button to the panel it controls, and no keyboard handling beyond Tab — a screen-reader user could not tell which view was showing. Now a real tablist with arrow-key, Home and End navigation and a single tab stop, the state driven from one flag so the visible and announced states cannot drift apart. | `resources/views/trips/index.blade.php` | A widget styled, not built |
| D54 | ~~**Low**~~ **fixed** | **Nothing said which page you were on** — every header item looked identical on every page, in ink and in markup. `aria-current="page"` plus a wine label and gold underline, both from the same flag. The gallery's filter pills and the Breeze `x-nav-link` had the same gap. | `components/site-nav-link.blade.php`, `layouts/app`, `media/gallery`, `components/nav-link` | — |
| D55 | ~~**Low**~~ **fixed** | Headings skipped from `h1` to `h3` on the trips, gallery and guide pages, and `h2` to `h4` within each guide step, breaking the outline screen-reader users navigate by. | `trips/_trip-card`, `media/gallery`, `pages/guide` | Heading level chosen for its size |
| D56 | ~~**Critical**~~ **fixed** | **The logo was 1.44 MB, served twice on every page.** `public/images/rihla-logo.png` is 6250 × 2976 and was sent untouched to the header, the footer and every social scraper as `og:image` — to be drawn 80 pixels tall. One file outweighed the entire compiled CSS and JavaScript bundle **seventeen times over**, and it was the first thing a visitor on a Maldivian mobile connection had to download. Rated Critical because it dominates every other performance number on the site: nothing else in §10.1 matters while the first paint waits on 1.4 MB of PNG. Resampled from the same artwork to 200/400/600 px in PNG and WebP — 5 KB where the browser needs it — with `srcset`, intrinsic `width`/`height`, and lazy loading for the footer copy. **The original file is byte-for-byte untouched and still shipped**; only the resolution handed to a browser changed. | `components/brand-logo.blade.php`, `public/images/`, `app/Support/Seo.php` | A source asset shipped as a delivery asset |
| D57 | ~~**Low**~~ **fixed** | The header logo carried `w-50`. Tailwind's spacing scale has no 50, so the class compiled to nothing and the logo silently fell back to its intrinsic width. A class that emits no CSS is worse than no class: it reads like a constraint that is being honoured. | `resources/views/layouts/app.blade.php` | — |
| D58 | ~~**Low**~~ **fixed** | 30 images carried no `loading` or `decoding` hint, so images far below the fold were fetched eagerly, competing with the hero for a mobile connection's first seconds. | 12 views | — |
| D59 | ~~**Medium**~~ **fixed** | **The app and browser icons were the wrong artwork for their size.** They carried the Kaaba-and-calligraphy mark, whose detail turns to mud at 16 px. `docs/BRAND.md` §4 had recorded "Dhoni alone (two-sail)" as the preferred direction while also noting "No SVG master anywhere in the repository", so the decision had been stuck for want of a file. The master now exists — lifted from the vector paths inside the palette PDF, so the curves are the designer's own data rather than a trace — and every icon is cut from it. The maskable variants carry extra padding because Android keeps only the middle 80%; at the standard inset the dhoni lost the tip of a sail. The header wordmark is unchanged. | `public/images/rihla-mark.svg`, `public/favicon.*`, `public/images/icon-*` | A decision blocked on a missing file |
| D60 | ~~**Medium**~~ **fixed** | **The wordmark was still wearing the pre-rebrand colour scheme.** Sampling its pixels found `#097EDD` bright blue (~336,000 px) and pure `#000000` black (~1.5 M px) — neither in the palette, and the blue in nothing else on the site. The colour work moved everything else to wine, gold and ink and deliberately left the logo alone, so it sat at the top of every page looking exactly as it always had while the page around it had changed completely. Four separate reports of "still I see the old logo" were all correct, and were read as caching or deployment until the pixels were actually sampled. Recoloured with two hues remapped and no geometry touched; the original is preserved and asserted unchanged. | `public/images/rihla-logo-brand*.png`, `components/brand-logo.blade.php` | An instruction to preserve, honoured past the point it still served |
| D61 | — **done** | **The dhoni became the logo.** Confirmed final by the owner, it replaces the Kaaba-and-calligraphy wordmark in the header, footer, login page and social preview card, having already taken the icon slots. One 461-byte vector now covers every size from the 16-pixel favicon to the header — the wordmark needed six rasters for the same job. The wordmark is retired rather than deleted: both the original and the recoloured version stay in the repository, asserted unchanged, for print and for the company seal. Noted for the record: the dhoni carries no company name, so the header no longer spells out "Rihla Travels"; if that ever needs fixing, the answer is type beside the mark, not a return to the old lockup. | `components/brand-logo.blade.php`, `layouts/app.blade.php`, `app/Support/Seo.php` | Not a defect — a brand decision, recorded here because it changes every page |
| D62 | ~~**High**~~ **fixed** | **The logo disappeared into the footer.** Its hull is ink `#2E2621`; the footer is `bg-ink`, the same `#2E2621`. On the footer the hull rendered invisible and the logo showed as two sails floating above nothing. Shipped and live, found by the owner looking at a phone. A `rihla-mark-inverse.svg` variant carries a cream hull and `<x-brand-logo on="dark">` selects it; a test asserts the two variants differ in the hull fill and nothing else. | `public/images/rihla-mark-inverse.svg`, `components/brand-logo.blade.php` | A one-colour logo on a surface of that colour |
| D63 | ~~**High**~~ **fixed** | **Footer text at 1.96:1.** The tagline was `text-gray-600` on `bg-ink` — not dim, unreadable — and the divider above it `border-gray-700` at **1.44:1**, an invisible line. Tailwind's grey scale is built for white backgrounds and several of its steps are illegible on ink. Now `gray-400` (5.84:1) and `gray-500` (3.07:1, the floor WCAG sets for a non-text line). The §10.2 accessibility pass missed both because it checked structure, not colour, and §4.5 checked the palette, not Tailwind greys used against it. | `resources/views/layouts/app.blade.php` | Two audits that each assumed the other covered this |
| D64 | ~~**Medium**~~ **fixed** | The floating WhatsApp, call and catalogue buttons are fixed to the viewport, so at the bottom of the page they covered the footer's closing lines. 208 px of clearance on small screens, matching the button stack's real height; unchanged on desktop, where they sit clear of centred text. | `resources/views/layouts/app.blade.php` | — |
| D65 | — **done** | **The logo carries the company name again.** When the dhoni replaced the wordmark, the header stopped saying "Rihla Travels" anywhere — a visitor from search saw a boat and had to read the browser tab to learn whose site it was. The name is now set beneath the mark, justified to its width, as real text: indexed, selectable, read aloud, and sharp at any density. Two findings from building it. Sizing in `em` put the name at 2.6px, because `em` resolves against the parent's font size and not its width; `cqw` is the unit that tracks the lockup. And a browser screenshot caught the footer name rendering ink-on-ink — `text-cream` had never been compiled into the stylesheet — which is the hull defect again, and again invisible to every assertion that only read markup. | `components/brand-logo.blade.php` | Not a defect — the cost of D61, now paid |
| D66 | ~~**High**~~ **fixed** | **The gallery was playing Rick Astley.** *(Fixed in two parts — the first was incomplete: removing the rows from the seeder stopped them being created again, but a deploy runs `migrate --force`, not `db:seed`, so the rows already in test.rihla.mv's database were untouched and still on the page. A migration now deletes them by the exact video URLs and file paths the seeder wrote, never by title, so a real row sharing a name survives. Caught by checking the live page after deploying instead of trusting the change.)* `MediaSeeder` shipped two videos titled "Cultural Heritage Tour Highlights" and "Local Market Experience", pointing at `dQw4w9WgXcQ` and `9bZkp7q19f0` — both carrying a `// Replace with actual video` comment, neither replaced. Alongside them, "Maldives Sunset" and "Crystal Clear Waters": resort-holiday copy on a pilgrimage site. All of it was live on test.rihla.mv, public and under Rihla's branding. The seeder already refused to run in production — that guard was added after demo content reached production once — but a guard on *where* demo data runs is not a guard on *what it says*. No video is seeded now: every YouTube id is somebody's real video, so there is no placeholder one. | `database/seeders/MediaSeeder.php` | A guard that fixed the blast radius, not the content |
| D67 | ~~**Medium**~~ **fixed** | **Every gallery photo was a broken-image icon**, verified live: `/storage/media/*.jpg` returned 404 for all of them. `storage:link` is in the deploy script, so the symlink was fine — the rows simply pointed at files nobody had uploaded. The same hole is open in production for a failed upload or a file cleared from storage. `<x-stored-image>` checks the disk and renders the mark on cream when the file is absent, so a visitor sees "no picture yet" rather than a torn page. | `components/stored-image.blade.php`, `media/gallery`, `trips/_trip-card` | A missing file treated as impossible |
| D68 | ~~**Low**~~ **fixed** | On mobile the guide's **Print** and **Download PDF** buttons rendered as two large empty buttons each holding one small icon. A media query makes them full-width and stacked, which is right, but their labels were `hidden sm:inline` — so the layout the CSS intended never had any words in it. Labels now show at every width. | `resources/views/pages/guide.blade.php` | Two rules for the same element, written apart |
| D69 | ~~**High**~~ **fixed** | **The admin navigation lost links off the edge of the screen.** Signed-in staff see eight items where a visitor sees six, and both used the same `md` breakpoint — so the desktop bar appeared from 768px while its content needed 1280px. The header carries `overflow-x-hidden`, so the surplus was clipped rather than scrolled: no scrollbar, nothing to drag, the links simply absent. Measured in a browser: **768–1000px lost Settings, View Site and Logout** (283px cut); 1100–1250px lost Logout. That is every iPad in landscape, every small laptop and every half-screen window. Nothing failed and every test passed, because a clipped link is still in the HTML. The signed-in bar now waits for `xl`; below it the menu carries the same links. | `resources/views/layouts/app.blade.php` | One breakpoint for two different navigations |
| D70 | ~~**Low**~~ **fixed** | The visitor call-to-actions — Message us, Call us, Browse Catalog — and the floating WhatsApp stack rendered on every admin screen, in three separate places, with the floating buttons sitting on top of the dashboard's own cards. Staff do not need to WhatsApp themselves. | `resources/views/layouts/app.blade.php` | The admin panel reusing the marketing layout wholesale |
| D71 | ~~**High**~~ **fixed** | **The trips were selling a honeymoon.** `TripSeeder` shipped "Maldives Island Hopping Adventure" (white sandy beaches, water sports), "Luxury Resort Experience" (overwater villas, private beaches, "perfect for honeymooners and luxury travelers") and "Cultural Heritage Tour" — all live on test.rihla.mv, each on its own indexed URL under Rihla's branding. This is the more prominent half of D66: trips are listed on the homepage, listed again on the trips page, and each has a page of its own. Cleaning the gallery and stopping there missed it. Replaced with three Umrah departures, and a migration deletes the old rows by exact slug — because a seeder change governs the next seed and nothing else, which is the same gap that left the placeholder media on the page after its seeder was cleaned. A test now checks the seeders' **words**, not just their rows: a seeder can be rewritten and still describe a beach holiday. | `database/seeders/TripSeeder.php`, cleanup migration | Demo content written for a different business |
| D72 | ~~**Medium**~~ **fixed** | **The social page embedded a YouTube playlist that does not exist.** `SettingsSeeder` seeded `PLxxxxxxxxxx` with a "replace with actual playlist ID" comment nobody acted on, and the page embedded a player pointed at it — a 404 inside an iframe where the videos should be, verified live. This is the third placeholder found on the running site and the one that mattered most: unlike the trip and media seeders this one has **no production guard**, because it seeds real configuration rather than demo content, so a placeholder here reaches the live site. Now null, which the view already handles by hiding the whole Videos section. The test looks for the shape of a placeholder — runs of x's, `your-*-here`, `example.com`, `1234567890` — rather than the specific instance, because finding these one at a time is how three of them shipped. | `database/seeders/SettingsSeeder.php`, cleanup migration | A comment used as a reminder |
| D73 | ~~**Medium**~~ **fixed** | **The test deploy raced the test suite, and usually won.** `deploy-test-immediate.yml` triggered on `push` to `main`, so the webhook fired in parallel with CI and the server had pulled long before the tests finished. A merge with failing tests was live on the public test site before GitHub finished saying it was broken — CI was advisory in practice, whatever the branch rules said. The trigger is now `workflow_run` on CI concluding **success** for a **push** (a pull request's CI run verifies a merge commit that is not on `main` and must never reach a server), and it deploys `workflow_run.head_sha` rather than `GITHUB_SHA`, which under that trigger is `main`'s tip and not the commit CI verified. A red run deploys nothing and annotates itself, because a gate nobody can see is indistinguishable from no gate. | `.github/workflows/deploy-test-immediate.yml` | The workflow predated CI and was never revisited when CI arrived |
| D74 | ~~**Medium**~~ **fixed** | **A failed deploy reported green.** The webhook spawns `pull-deploy-test.sh` with `nohup` and answers `202` immediately, so `202` means the deploy *started*. When the pull then failed on the server — a conflict, a failed `composer install` — the old code kept serving, and the workflow's smoke check curled the homepage, which the old code answers exactly as well as the new code would. Nothing in the pipeline could distinguish a deploy that landed from one that only started. `/api/health` now reports the running commit, read from `.git` without shelling out (cPanel accounts commonly disable `exec`), and the workflow polls until it sees the commit it deployed. The commit is returned **only** to a caller presenting the deploy secret: the response for everyone else is unchanged, and if the repository is ever made private, naming the running revision will not become a disclosure. | `.github/workflows/deploy-test-immediate.yml`, `app/Support/DeployedCommit.php`, `app/Http/Controllers/Api/HealthController.php` | Asynchronous trigger, synchronous assumption |
| D75 | ~~**Medium**~~ **fixed** | **The one endpoint that runs shell commands had no test.** `POST /api/deploy/test-pull` executes `pull-deploy-test.sh` on the server, and its three refusals — no secret configured, unrecognised host, wrong secret — were the only thing standing between the internet and that script. None had ever been executed by a test. All three are now covered, as is the case where no secret is configured and none is presented: both are empty, and a naive comparison would have let anybody through on an unconfigured host. The secret's definition moved to `App\Support\DeploySecret` so the health endpoint and the webhook cannot drift apart — the same mistake as the phone number in fourteen places (D46). | `tests/Feature/DeployPipelineTest.php`, `app/Support/DeploySecret.php` | Written before the project had tests |
| D76 | ~~**High**~~ **fixed** | **A destructive action lost its guard on exactly the titles a person would write.** The media delete button carried `onclick="return confirmAndSubmit(event, {id}, '{{ $item->title }}')"` — a JavaScript string literal, built inside an HTML attribute, out of admin-entered text. Blade escapes an apostrophe to `&#039;`; the HTML parser decodes it before JavaScript ever sees the attribute; the handler then fails to parse. Verified in a browser rather than reasoned about: for a title of `Ahmed's "Umrah" photo` the element's `onclick` property was `null`. A handler that never compiles cannot `return false`, and the button was still `type="submit"` inside the form — so clicking Delete deleted the item with no confirmation at all. The confirmation is now `data-confirm` on the form, where Blade's escaping is correct and an apostrophe is just an apostrophe, and it is handled by one delegated listener. Putting it on the form rather than the button also closes a second hole: submitting with the Enter key never fired the button's handler. | `resources/views/admin/media/index.blade.php`, `resources/js/interactions.js` | Three layers of quoting in one attribute |
| D77 | ~~**Medium**~~ **fixed** | **Nineteen `console.log` calls shipped to production**, across the public guide, the login page, the trip form and the media index. The media one printed the CSRF token, every form action and every form field to the browser console on each admin page load; the login page shipped a self-check reporting whether its own CSS had loaded. This is the third time debug scaffolding has been found live (D12, D28), so it is now a test rather than a sweep. | `resources/views/**`, `tests/Feature/InlineHandlerTest.php` | Left in after debugging |
| D78 | ~~**Low**~~ **fixed** | **Two guest layouts both answered to `<x-guest-layout>`, and the worse one won.** `App\View\Components\GuestLayout` takes precedence over the anonymous component of the same name, so `layouts/guest.blade.php` rendered: it loaded **Figtree**, which no stylesheet or Tailwind config references, did not load the **Inter** that `font-sans` actually asks for, carried no `dir` attribute for Dhivehi and showed no logo. The shadowed file had all four right. Password reset, registration and email verification were the pages affected. The duplicate pair is removed and the correct component kept; `layouts/navigation.blade.php`, dead Breeze scaffolding referenced only from the vendor stub, went with them. | `app/View/Components/GuestLayout.php`, `resources/views/layouts/guest.blade.php` | Breeze ships both forms and nothing chose between them |
| D79 | ~~**Medium**~~ **fixed** | **A deploy could leave an autoloader pointing at a file that is gone.** Both deploy scripts run `composer install --optimize-autoloader` only when `composer.json` or `composer.lock` changed. `--optimize-autoloader` writes a class ⇒ file map, so a commit that merely deletes a PHP class leaves that map naming a file which no longer exists. Nothing references the class, so nothing looks wrong — until `class_exists()` is called on it, the autoloader includes the missing file and the page fatals. Blade does exactly that for every `<x-component>` tag, which is how this was found: deleting the duplicate above worked locally and would have taken down the auth pages on the server. Both scripts now rebuild the classmap on every deploy. | `scripts/pull-deploy-test.sh`, `scripts/deploy-production.sh` | An optimisation whose invalidation was never considered |
| D80 | ~~**Medium**~~ **fixed** | **The admin panel ran a script from a CDN with no integrity check.** `admin/hero-banners/index` loaded SortableJS from `cdn.jsdelivr.net` with no `integrity` attribute, so a compromised or hijacked CDN would have had code execution in a signed-in administrator's browser — the one session on this site that can edit every page — and an unreachable one simply broke banner ordering. Subresource Integrity would have covered the first case; vendoring the file covers both, removes the dependency on a third party being up, and means the Content-Security-Policy needs no third-party script origin. A test now fails if any view loads a script from another origin. | `resources/views/admin/hero-banners/index.blade.php`, `public/vendor/sortablejs/` | A CDN copied from a tutorial |
| D81 | ~~**Critical**~~ **fixed** | **The Dhivehi Umrah guide gave the same du'a for every rite.** All ten seeded Dhivehi steps carried one identical `dua_text` and one identical `reference_text`, while the ten English steps carry ten real supplications (the Talbiyah among them) and real citations. The page presented that one meaningless sentence under a heading naming the supplication for Niyyah, for Ihram, for Tawaf and for Sa'i in turn. Rated Critical rather than High because this is religious instruction a pilgrim acts on, not marketing copy, and because it was live on the public test site. The steps are **deleted, not rewritten**: inventing replacement religious text would be a worse defect than the one being fixed. `PageController::guideStepsFor()` now falls back to the English steps for any locale with none, so a Dhivehi visitor reads the correct guide in English rather than an empty page, and a real translation entered in the admin panel wins the moment it exists. | `database/seeders/UmrahGuideSeeder.php`, `app/Http/Controllers/PageController.php`, `2026_09_19_080000_remove_fabricated_dhivehi_guide_steps.php` | Placeholder content generated to fill a schema |
| D82 | ~~**High**~~ **fixed** | **One Dhivehi word labelled fifteen different things.** `resources/lang/dv/guide.php` held 19 keys with **3** distinct values: "Step", "of", "Table of Contents", "Checklist", "Fiqh Notes", "Du'a", "Reference", "Previous Step", "Next Step", "Print", "Download PDF", "Watch Video", "Show Details" and more all rendered identically, appearing **31 times** on the live `/dv/guide`. `dv/messages.php` had 91 keys sharing a value with an *unrelated* key — a file-upload instruction shared one with "Edit" — and seven keys defined twice in the same file, which PHP silently collapses. The fabricated entries are removed so those labels fall back to readable English. `TranslationQualityTest` fails if unrelated keys ever share a value again; it compares the **English meaning behind each key**, because `en/messages.php` legitimately dual-keys some strings (`'Manage Trips'` and `'manage_trips'`) and a blunter rule deleted eight real translations. | `resources/lang/dv/*.php`, `tests/Feature/TranslationQualityTest.php` | Generated translation file, never read by a speaker |
| D83 | **Open — needs a translator** | **147 of 246 dotless keys have Dhivehi values that never reach the page.** Views call `__('Trips')`, which Laravel resolves as a JSON lookup against a `dv.json` that does not exist, so the key renders as English — D1's root cause, still present in the site chrome. Rewiring them to `__('messages.Trips')` was written, measured (the Dhivehi trips page went from 86 to 701 Thaana characters, the navigation turned Dhivehi) and then **withdrawn**: the audit above proved the file that would supply those strings is partly fabricated, so wiring 147 more of them sight-unseen would have replaced readable English with nonsense. The rewiring is a half-hour change once a Dhivehi speaker has validated `resources/lang/dv/messages.php`. | `resources/views/**`, `resources/lang/dv/messages.php` | Two defects that cancelled each other out, hiding both |
| D84 | ~~**Low**~~ **fixed** | **`AGENTS.md` told the next developer to expect failing tests.** It listed the Breeze `Auth`/`Profile` tests and `ExampleTest` as pre-existing failures "unrelated to environment setup". All of them pass and have for some time — `/profile` is routed and exercised. A standing note that some failures are acceptable is how a real one gets waved through, which is the same drift D7 recorded. | `AGENTS.md` | Documentation not updated when the code was |
| D85 | ~~**Medium**~~ **fixed** | **Four invented social accounts, one provably dead.** `SettingsSeeder` seeded `facebook.com/rihlatravels`, `instagram.com/rihlatravels`, `tiktok.com/@rihlatravels` and `viber.com/rihlatravels`: one handle typed into four platforms, none verified. TikTok returns "Page not available" for that handle, so the live social page linked visitors to a profile that does not exist — the fifth placeholder found live on this site, after the demo trips, the gallery videos, the media photos and the YouTube playlist. That one is cleared by migration, matched on the exact seeded value so an owner-entered URL is never touched. The other three cannot be checked by any script — Facebook and Instagram answer a login wall, Viber answers a generic account page whether or not the account exists — and clearing a link that works would be its own defect, so they are left in place and `rihla:preflight` warns about them at deploy time. A dead social link on a travel operator's site costs more than a missing one, and the social page already hides each link that is not set. **Closed (v1.39):** the owner confirmed all four were guesses; the remaining three are cleared by `2026_09_19_110000_clear_the_seeded_social_links.php`, matched on the exact seeded values so anything entered by hand survives. The social page shows only what is real, which for now is WhatsApp. | `database/seeders/SettingsSeeder.php`, `app/Console/Commands/Preflight.php`, `2026_09_19_090000_clear_the_dead_tiktok_link.php` | Plausible-looking values invented to fill a settings row |
| D86 | ~~**High**~~ **fixed** | **The homepage carried fabricated Dhivehi.** The "Why Choose Rihla" section's three Dhivehi feature titles shared a 19-character suffix out of 24–26 characters, and two of its three bodies shared **72 of their 77 characters** — while the English copy for the same three selling points shares two and twelve, which is what incidental overlap looks like. The same signature as the guide steps (D81) and the language files (D82), on the most-visited page. Deleted rather than paraphrased and matched on the exact seeded titles; `HomeController` now falls back to the English section for a locale with none, so `/dv` keeps the block with the real selling points in English. The migration also clears `why_section_active_{locale}`, which `HomeController` caches for an hour — without that the homepage would have kept serving the deleted section, or done so indefinitely on a driver that survives a deploy. A test fails if two features in one section ever share more than half their characters; it was checked against the original data first (72 of 77, threshold 38.5). | `database/seeders/WhySectionSeeder.php`, `app/Http/Controllers/HomeController.php`, `2026_09_19_100000_remove_fabricated_dhivehi_why_section.php` | Placeholder content generated to fill a schema |
| D87 | ~~**Medium**~~ **fixed** | **The floating buttons disappeared against wine sections.** The WhatsApp, Call and Catalog buttons are `position: fixed`, so they pass over every section of every page. Two are wine-filled, and a wine circle over a wine section is **1.00:1** against its own backdrop: the disc vanishes and the icon is left hanging with no button around it. Found in the first phone screenshot of the newly promoted production site — no contrast check would have caught it, because every *text* pair on the button is fine (white on wine is 8.23:1, and the Call button's gold-700 icon on cream is 5.90:1). It is the button's own shape that had no contrast. A 2px cream ring gives both wine buttons an edge on wine (7.64:1) and is invisible on cream, where the wine fill supplies its own; the Call button needs none, its cream fill already being 7.64:1 against wine. A test asserts every wine-filled floating button carries a cream ring, and was checked against the unfixed component first. | `resources/views/components/whatsapp-fab.blade.php` | A fixed element judged against one background |
| D88 | ~~**High**~~ **fixed** | **Every card on the site had its content flush against its left border.** `.card` was `bg-white rounded-2xl border shadow overflow-hidden` and nothing else — no padding — and no view added any. Computed in the browser: `padding: 0px 0px 0px 0px` on every card on every page. The trip card's image carries `rounded-2xl` of its own, which only makes sense inset inside a rounded card, so the markup had always expected padding the class never gave it. Noticed only in a full-page screenshot: every assertion in the suite reads markup, and the markup was fine. `.card` now carries `p-6 md:p-8`. | `resources/css/app.css` | A component class that described the frame and forgot the inside |
| D89 | ~~**Medium**~~ **fixed** | **The hero's primary button was drawn in the colour of its own background.** The fallback hero is a wine gradient and its primary action was `.btn-primary` — wine on wine — so the cream secondary outranked the thing the page most wants clicked. It is now `.btn-gold`: gold fill with ink text, the one fill the palette permits gold to be, and the rule the owner set (never gold with white text) is kept. | `resources/views/components/hero-banner.blade.php` | Hierarchy inherited from a page that had a different background |
| D90 | ~~**Medium**~~ **fixed** | **The homepage's "Learn More" was a 404.** `WhySectionSeeder` wrote `secondary_cta_url => '/about'`; no route has ever answered it, and it sat on the most-visited page for anyone who clicked. Remapped to `/guide` — the Umrah guide is what "learn more" means on this site — by migration matched on the exact seeded value, with the per-locale cache cleared so it takes effect at once. | `database/seeders/WhySectionSeeder.php`, `2026_09_19_120000_point_learn_more_at_the_guide.php` | A link written before its page, which never came |
| D91 | ~~**Low**~~ **fixed** | **Contact showed an empty "Follow Us" card, and generic travel copy.** With every social link cleared (D85) the card rendered as a heading over nothing. It now renders only when a link exists. Beside it, "Born and raised in the Maldives, we know the best spots and hidden gems" and "customized to your preferences and travel style" — resort-brochure copy on an Umrah operator's contact page, the same genre as the demo trips that sold a honeymoon (D71). Replaced with three Umrah-specific points: a Dhivehi-speaking group leader, hotels within walking distance of the Haram, support through every rite. | `resources/views/pages/contact.blade.php` | Copy written for a different business |
| D92 | ~~**Low**~~ **fixed** | **One item in a three-column grid hugged the left edge.** The trips page with a single current trip, and the social page with only WhatsApp left, each showed one card at the far left of an otherwise empty row — it read as broken rather than sparse. Both now use a centred wrapped flex row, which centres one, two or three cards and behaves identically at full width. | `resources/views/trips/index.blade.php`, `resources/views/pages/social.blade.php` | A grid designed for a full row |

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

Deferred to later phases but designed for now: seasonal Ramadan/Dhul-Hijjah theming, prayer times + Qibla widget, live Haram stream.

**Personalisation for returning users is done** (`App\Support\Personalisation`), and it is deliberately one line. A signed-in customer who has travelled with Rihla is greeted with what actually happened — "You travelled with us once, most recently in January 2026" — and somebody with a journey already booked is pointed at their portal rather than sold another package, because offering one to a person about to fly is how a travel company reads as a shop rather than as the people taking them.

**There is exactly one input: a signed-in customer with bookings.** No cookie, no fingerprint, no behavioural profile, no "people like you also viewed". That is the same finding §10.5's dashboard records — nothing here logs a visit — and building the visit log in order to recommend from it would be building the surveillance first and asking whether anybody wanted it afterwards. For an operator whose whole differentiator is being trusted about religion, that is a bad trade at any conversion rate, and `whyNothingPersonal()` says so on the page.

**It recommends nothing**, and that was decided by looking at it. The first version offered "the journeys on sale you have not been on"; rendered on the homepage, that list was the same three departures the page already showed underneath. A "recommended for you" panel over the identical list below it is arithmetic dressed as insight, so it came out.

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

**Since done — see §4.7.** This said the spacing rhythm was "cosmetic refactoring across 48 views
and wants a designer's eye more than an engineer's". Measuring it first made it a much smaller job
than that: nine wrappers, not 48 views.

---

### 4.7 Section rhythm — **implemented** ✅

The vertical half of the design system, which had never been written down.

**What the audit found**, before changing anything:

| | Before |
|---|---|
| Rhythms across five public pages | **Four** — home `py-16`, trips and gallery `py-8`, trip detail `py-8`, guide `py-6 md:py-8 lg:py-12` |
| Pages whose rhythm changed with the viewport | **One** (the guide) |
| Responsive vertical-padding declarations in the whole codebase | **6** |
| Section/container classes defined in `app.css` and used by no view | **Six** |

So a 375px phone was given the spacing chosen for a 1440px desktop, and the stylesheet already
held a section system nothing had adopted.

**Shipped:** `.section-y` (`py-10 md:py-14 lg:py-16`) and `.section-y-tight`
(`py-8 md:py-10 lg:py-12`), applied to nine page-level wrappers. The values were chosen so
**desktop changes as little as possible** — the homepage is still 64px at `lg`, the guide still
48px — while mobile tightens (the homepage 64 → 40px). The trips, gallery and trip pages gain
32 → 48px at `lg`, matching the rhythm the guide had already set.

`.section` and `.container-fluid` are gone: unused, undocumented, and `.section` read as the base
of the documented `.section-light` / `.section-dark` / `.section-wine` family, which it never was.
Eleven further component classes turned out to be undefined in `docs/BRAND.md`; that table is
complete now, and `SectionRhythmTest` fails if it drifts again.

**The numbers are a judgement call and can be argued with.** The point of the pass is that arguing
with them is now a two-line edit in `app.css` rather than a sweep through the views — which is the
engineer's half of the job the v1.0 plan wanted a designer for.

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

**Implemented — the domain and the capacity invariant.** `customers`, `travellers`, `bookings`, `booking_travellers`, `booking_lines`, `seat_holds` and `booking_status_transitions` exist, additive and reversible, with the package snapshot frozen at creation. Overbooking is prevented twice over, as the paragraph above requires:

- `App\Services\Booking\SeatAllocator` is the only thing that moves a departure's counters, and it takes a `SELECT … FOR UPDATE` row lock on the departure first **[R-2]**. `SeatLockTest` proves the lock is real by making a second connection ask for it and time out — it is skipped on SQLite and runs in the MySQL CI job, which exists for this.
- A capacity constraint on the table itself catches any path that forgets the lock. MySQL and PostgreSQL get a CHECK; SQLite, which cannot add one to an existing table, gets equivalent triggers, so the test suite exercises the rule on both engines rather than on neither.
- **[R-5] is now answered per host rather than assumed:** MySQL enforces CHECK only from 8.0.16 (MariaDB 10.2.1), and older versions parse and ignore it, so the migration installs it only where it will bite and `rihla:preflight` warns on every deploy when the backstop is absent. The row lock carries the guarantee either way.

Seat-hold expiry is deliberately **not** trusted to the scheduler: a lapsed hold is reclaimed inside the same row lock the next booking takes, so a departure heals itself whether or not cron ever runs on this cPanel account. `bookings:expire-holds` exists for departures nobody is looking at.

Capacity of `0` means nobody entered one — which is how every departure backfilled from `trips` starts — and the allocator refuses to sell on it rather than treating it as unlimited.

**Implemented — the public booking flow.** Package → departure → room → travellers → review → seats held, server-rendered, one page per step, no JavaScript required: this traffic is a phone on mobile data in Malé, and a checkout that needs a bundle to load fails for the people most likely to be booking.

The seats come off the departure at step one, because that is the moment the visitor committed to them — holding after the forms are filled in would mean losing the seat after ten minutes of typing. The hold is anonymous until the travellers are entered, which is why `seat_holds.booking_id` is nullable.

**Where it stops, and why.** At the seat hold. Taking money needs a BML Connect merchant account that does not exist yet (§5.3), and building a payment step against credentials nobody can test with produces a screen that looks finished and has never once worked. So the flow ends where it honestly can: the seats are held for the plan's fifteen minutes, the booking has a reference, and the customer is sent to WhatsApp with it. That is what happens today anyway — every Rihla booking is a WhatsApp conversation — except that it now arrives with the travellers entered and the seat already off the departure.

Three things are deliberately absent rather than guessed:

- **No deposit, instalment schedule or payment deadline.** How long a booking may sit unpaid is policy the owner has not set, and printing a plausible figure next to a real price is how this site once advertised social accounts that did not exist.
- **No terms and conditions.** Rihla has not published any. The confirmation checkbox states a fact — that the details are right and that payment is arranged with a person — rather than inventing a contract.
- **No mahram rule.** A child cannot travel on a booking with no adult on it, which is mechanical and needs nobody's policy. The mahram requirement is a Saudi rule with real nuance that §5.4b puts in versioned configuration; a half-remembered version of it in a checkout would turn away bookings that are perfectly allowed.

Child and infant pricing follows the age **on the departure date**, and only where the departure actually publishes a tier for it — a departure that prices only adults prices everybody as an adult. The age bands are configuration, not constants, and are the operator's to confirm.

A bidi defect found by rendering the Dhivehi page, and **already live on the package pages before this**: Thaana letters carry bidi class AL, so rule W2 retypes the year after a translated month as an Arabic number, rule N1 drags the surrounding neutrals into the right-to-left run, and "19 ނޮވެންބަރު 2026" renders as "19 2026 ނޮވެންބަރު" — inside an element marked `dir="ltr"`, because base direction was never the problem. `<x-local-date>` isolates the month in a `<bdi>`; `BidiTest` fails if any view prints a translated month directly, and was proved by planting the defect.

**Implemented — the staff booking admin.** Until this screen existed the checkout wrote rows nobody at Rihla could see: the customer got a reference and a WhatsApp message, and finding out what they had booked meant a database client. Three of the nine staff roles — Booking Staff, Finance, Pilgrim Support — existed on paper holding `admin.access` and nothing else.

`booking.*` and `customer.*` now exist, with **no `create` and no `delete` verb at all**: a booking is made by the checkout, which takes the seats under a row lock and freezes what was sold, and a booking is a financial record that is cancelled — a status, with a row saying who and why — never removed. Content Manager holds none of it; passport numbers and phone numbers are not content. Reporting holds `viewAny` without `view`, which is the whole reason those are separate permissions: it sees that bookings exist and how many, and does not open one.

Every staff action goes through the domain rather than writing a column — `Booking::transitionTo()` refuses an illegal move and records who and why, and `SeatAllocator` is the only thing that touches a departure's counters. Confirming takes the seats *before* marking the booking confirmed, so a booking can never promise seats the departure cannot supply; cancelling a confirmed booking returns them to the confirmed counter and not the held one, which would otherwise leave the departure permanently and invisibly full. "Extend the hold" issues a fresh hold with a new expiry rather than an open-ended one, because an indefinite hold is how a departure shows sold out while half empty.

Two things the screen says out loud rather than leaving to look like bugs: **Paid is always zero** until BML is connected, and **Confirm is labelled "payment received"** because a person is asserting that, not the system checking it.

**Implemented — the waiting list, with real auto-promotion.** A sold-out departure was a dead end: the page said "Fully booked" and the visitor left, while seats do come back — a hold lapses, a passport turns out to be expired, a family cancels — and nobody was told.

An offer **holds the seats**, through the same allocator, lock and constraint as any other booking. Marking somebody "next in line" and leaving the seats on sale is a race they lose while staff are still typing a message. Promotion fires from every path that releases seats — a cancellation, a released hold, the expiry command — and deliberately *not* from the reclaim inside `hold()`, because those seats are being taken by the customer who triggered that reclaim.

Order is oldest first, with one stated exception: a party that does not fit the seats that came back is skipped and keeps its place, and the next party that fits is offered instead. Holding two seats empty for a party of four who may never answer serves nobody. An unanswered offer expires rather than returning to the queue, or the same party would be handed the same seats for ever and nobody behind them would be reached.

**There is no notification channel, and this does not pretend otherwise.** Nobody has given SMTP credentials and there is no WhatsApp API, so an offer produces a **signed, expiring claim link** and appears in the staff admin as work: staff copy the link into the WhatsApp conversation they were going to have anyway, and it drops the customer straight into the checkout at the traveller step with the seats already theirs. A Mailable quietly posting into the log driver would look finished and reach nobody.

Two defects found by submitting the form and reading the page that came back, neither visible to a test asserting the session: **nothing rendered `session('status')`** outside the Breeze auth views, so joining the waiting list — and being told a lapsed hold had released your seats — said nothing at all; and **`back()` with no referer** falls through to the bare domain, which redirects again to pick a language, consuming the flash on the way. A third, found by the test: submitting twice created two customers and two entries, so one family could be offered seats twice. Joins are matched on the phone number.

**Implemented — the document wallet, versioned from the first row ([R-8]).** A replaced passport creates version 2 and supersedes version 1; it never overwrites one. Both rows and both files stay, because a visa was applied for against a particular passport, and when the traveller renews mid-process the only way to answer "which document did we send them?" is to still have it.

- **Files are checksummed.** The SHA-256 proves a file has not changed under us, and it catches the common case — a customer resending on WhatsApp, a staff member unsure the first attempt worked — where the "new" passport is byte-for-byte the old one. That produces no new version, because nothing changed.
- **The disk is private and `serve => false`,** so Laravel's built-in local-disk route cannot reach it. The only door is one controller, which checks a signed URL, asks the policy, and writes to the audit trail before streaming a byte. Filenames on disk are random: a predictable layout is one misconfiguration away from being an index.
- **Every *download* is audited**, not just every change. For most records the interesting event is an edit; for a passport scan it is a read, and "who has had a copy of this?" is the question asked after something goes wrong.
- **`download` is a separate permission from `view`.** Pilgrim Support can tell a caller "yes, we have your passport" without being able to put the scan in a downloads folder. There is no `document.delete` at all.
- Visa Staff finally has a job: the role has held `admin.access` and nothing else since it was defined.

**The first of the versioned Saudi requirements.** Passport validity lives in `config/documents.php`, not in a constant, and is measured from the *departure date* rather than today — §5.4b requires every Saudi rule to live in configuration so a mid-season change is a config edit and not a deployment. Whether a document is expiring is computed, never stored: a stored flag is wrong the moment the clock passes it.

Recorded while working: `PackageDepartureTest`'s list of migrations to roll back is maintained by hand, and deriving it made things worse. Re-applying the people migration adds foreign-key columns to `departures`, and **SQLite implements that by rebuilding the table, cascade-deleting every `price_tiers` row** — invisible on MySQL, where the ALTER touches nothing, and harmless in a real migration run where the table is empty.

**Implemented — visa applications (§5.4a), and only those [R-4].** One per traveller per booking, because a visa is granted to a person and not to a party, with its own state machine, an assigned officer, and an evidence document on every stage.

**Re-application after rejection is a first-class path, read literally.** A refusal is final; trying again opens attempt 2 as a new row. Reopening the refused record would destroy the only evidence of what was actually sent — its date, its reference, its stated reason. A submission *returned for more information* is different and goes back to preparing without burning an attempt.

**No permit state, anywhere on the record.** A test asserts the columns carry no `permit` or `ready` field, because one combined status cannot express "visa issued, still barred from the Rawdah" — the state that strands a pilgrim. Travel readiness will be computed from both workflows when permits arrive.

Permitted visa types and the per-stage service levels are `config/visa.php`, per §5.4b's rule that no Saudi requirement is a constant. What Saudi Arabia accepts is their rule, not this application's, and the list is marked as the operator's to confirm. A stalled application is computed on every render rather than flagged: a stored "overdue" column is wrong the moment the clock passes it. Working days are deliberately not modelled — nobody has said which days Rihla counts, and a guess that quietly shortens a deadline is worse than plain days.

**Implemented — Nusuk permits (§5.4b), as a second workflow beside visas and never inside them [R-4].** The Umrah permit and a Rawdah slot are **separate records**, not one record with two dates: they are granted separately, refused separately, and they fail differently — a missing Rawdah slot is a disappointment, a missing Umrah permit is a wasted journey. Folding them together would make those two outcomes indistinguishable.

**The prerequisite gate lives on the departure, not on the person.** Nusuk wants accommodation and transport recorded before a permit can be requested, and both are properties of the dated run. What is stored is *when Rihla recorded them in Nusuk* — whether what was entered is compliant is Nusuk's judgement, and this application does not claim to make it. Requesting a permit for a departure that lacks either is refused by the domain and said out loud on the screen; a request Nusuk would bounce for a reason visible from here is a wasted round trip and a status nobody can read.

**Travel readiness is computed across both workflows and never stored (§5.4a).** `App\Support\TravelReadiness` returns three independent answers — passport, visa, Umrah permit — rather than one score, because "not ready" is useless to whoever has to fix it while "visa issued, permit not requested" is a morning's work. A test asserts no readiness column exists to go stale. A Rawdah slot is deliberately **not** a requirement: counting it would make a pilgrim who cannot pray in the Rawdah look like one who cannot perform Umrah, and telling those two apart is the only useful thing the class does.

`config/nusuk.php` holds the prerequisites, the Rawdah slot lead time and the service levels, per §5.4b's rule that no Saudi requirement is a constant. **The lead time is used to warn, never to refuse** — Nusuk decides what it will accept, and a client-side rule that quietly blocks a valid request is worse than no rule. The number itself is marked as the operator's to confirm.

`departure.nusuk` is its own permission and is **explicitly excluded from the content set**. The content set is defined by inclusion precisely so a new verb under an existing prefix cannot be granted by accident, and this is that case arriving: a Content Manager who edits the website has no business asserting a dealing with a Saudi system.

Recorded while working: both new `departures` columns were neither in `$fillable` nor in `$casts`, and **every test still passed**, because `Factory::make()` runs unguarded while the admin form's `update()` does not. The form would have dropped both values without a word, and the only symptom would have been permits that could never be requested for a departure whose own form said they could. There is now a test that writes them through the guarded path.

Also recorded: a Filament relation manager's modal is rendered by the **page** that hosts it, so mounting the action on the relation-manager component yields HTML with no modal in it. A test written that way asserts against a page that never contained the thing it claims to check. The readiness view is rendered directly and the action's presence on the row is asserted separately.

**Implemented — invoices and receipts (§5.3's outputs).** Generated on demand, never stored: an invoice is a rendering of the booking as it stands, and a saved PDF is a second copy of the truth that drifts the moment a line changes. What is immutable is already in `booking_lines`, `payments` and `payment_transactions`. If a numbered, unalterable series is ever legally required, that is where this changes — nobody has said it is.

A receipt exists only for money somebody has checked. Issuing one for an unverified claim would hand a customer a document saying their payment was received before anybody looked at it. The customer reaches both through the portal session, and the receipt's payment id is checked against that session's booking rather than trusted.

**No tax line and no terms.** Whether an outbound Umrah package attracts Maldivian GST, at what rate, and whether Rihla is registered for it are unanswered; the terms document does not exist. Both are configuration, both ship empty — as `''` rather than null, so the existing guard against a view reading an undefined config key keeps working — and neither renders until somebody states what is true. A false line on a document a customer may hand to an accountant is worse than a missing one.

Recorded while working, and the reason this took three attempts: **the live Dhivehi Umrah guide PDF had its headings rendering as rows of question marks**, under a comment in that same file claiming the font problem was fixed. A_Faruma ships Regular only, so dompdf resolved every `<strong>` to Helvetica-Bold — a different family, with no Thaana glyphs — silently. The body text was perfect, which is presumably why it was checked and the headings were not. Fixed in the guide as well as in the new documents by declaring a bold face from the same file.

Two things about how that was found are worth keeping. The first pass at extracting text from a PDF in PHP read an embedded subsetted font's glyph runs as if they were Unicode and reported question marks that were not there — so the defect was nearly reported in the wrong place, and the real one was only confirmed with a proper PDF parser. And the guard written for it initially matched any `font-weight: bold` anywhere in the file, which the guide's own heading rules satisfied; it passed with the declaration deleted, and only planting the defect showed that.

**Implemented — the historical customer import.** `php artisan customers:import <file.csv>`, and it is a **dry run unless `--write` is given**, which is what the risk register asks for: an import that half-succeeds against a live customer table leaves the second half to be done by hand against a table that has already moved.

**It refuses to guess.** Three outcomes, and only one writes: *new* (nothing matches), *already on file* (exactly one customer has that number — nothing is overwritten, and any field the file disagrees about is named), and *needs a person* (more than one match, or no number to match on). A wrongly merged customer takes two bookings and two families with it, so nothing is merged automatically.

**Phone is the identity**, because in this market it is: an email address is often absent or shared across a whole family, and a name is written three different ways by three different members of staff. `App\Support\PhoneNumber` is the one place that decides `7712345`, `+960 771 2345`, `960-7712345` and `00960 7712345` are the same person — and that a longer number merely *starting* 960 is not, because stripping it would invent a match between two strangers. Name matching is case and spacing only; anything fuzzier is how an import merges two brothers.

Duplicates **inside the file** are caught as well as against the database. A spreadsheet edited by four people over six years contains the same person twice, and an import that checks only the database creates both.

**Implemented — the minimal CRM (§8.1).** The plan names exactly what the first version is: "every enquiry becomes a tracked lead with an owner and a next action — that alone beats a shared inbox." So the screen is built around finding the ones that have neither, and **opens on that list rather than on all enquiries**: a screen that opens on everything is a shared inbox with extra steps.

Four statuses — new, working, won, lost — and no more. A five-stage qualification funnel is a description of how a sales team works, and nobody has described this one. These four can be observed without asking anybody; adding stages later is a migration, while unpicking stages nobody uses is a habit.

An enquiry form sits on the contact page **under the WhatsApp button rather than instead of it**, because WhatsApp is how this operator's customers actually get in touch. A phone number *or* an email will do: demanding both loses the enquiries of people who do not use email, which here is a lot of them. Sending twice records a note on the enquiry that already exists rather than creating a second lead for two people to work in parallel — matched with the import's phone normalisation, so `7712345` and `+960 771 2345` are one person.

Marking one as booked reuses an existing customer with that number instead of creating a second copy, the lesson the import had just finished teaching. Losing one requires a stated reason: "too expensive" and "went with a competitor" are different problems, and reading them back in a year is the point of keeping lost enquiries at all. There is no `enquiry.delete`.

Recorded while working: the form was first placed as a second card inside the contact page's WhatsApp column, whose card carries `h-full` — which pins the column to its own height, so the new card escaped its parent and rendered **on top of the section below it**. Every test passed. It was visible only by rendering the page at phone width and looking at it. Also caught the same way: the honeypot field was declared with a `size:0` validation rule, so a bot that filled it got a validation error telling it exactly what had happened, instead of the thank-you everybody else gets.

Phase 3 is complete apart from what is blocked on the operator: BML merchant onboarding, bank account details, instalment terms, and whether Rihla charges GST.

### 5.3 Payments

- **Gateway:** BML Connect via `javaabu/bml-connect-laravel` (actively maintained; v0.7.0, Feb 2026). MVR and USD; 3-D Secure; BML Pay, Apple Pay, Google Pay, Visa/Mastercard/Amex/UnionPay. MDR 1% since Nov 2025.
- **Instalments:** deposit + scheduled instalments with automated reminders (email + WhatsApp), late-payment escalation to CRM tasks, and a hard rule that final documents/permits are gated on full payment.
- **Offline methods:** bank transfer with slip upload and manual reconciliation — this will remain a large share of Maldivian payments; do not treat it as an afterthought.
- **Outputs:** invoices, receipts, statements as PDFs (the app already has `barryvdh/laravel-dompdf`), plus a customer-facing payment history in the portal.
- **[R-7] Store all money as integer minor units** (laari for MVR, cents for USD) — never floats, never decimals-as-strings. A booking carries one currency, fixed at creation; any FX rate applied is stored on the line item, never recomputed later. Retrofitting this after the first real payments is painful and error-prone.
- **Provider independence:** `Booking → Payment → PaymentTransaction → provider driver`; BML appears in exactly one class. Callback idempotency rests on a unique constraint over the provider event ID. Full design: companion document §10.

**Implemented — the payment domain, bank transfer and cash (§5.3).** The chain the plan names is real: `Booking → Payment → PaymentTransaction → provider driver`, and a gateway appears in exactly one class. Everything above the `PaymentGateway` interface — the booking screen, the ledger, the reconciliation queue — is written once and does not change when BML is connected.

**Recording a payment and reconciling it are different acts by different people.** Booking Staff enters what a customer says they have sent; `payment.reconcile` is Finance's alone. Collapsing those into one permission is how an unchecked slip becomes a confirmed booking and a seat nobody paid for. The booking screen says so out loud: money that is claimed but not checked is named next to the paid figure rather than left to make the two numbers disagree silently.

**`bookings.paid_minor` is recomputed, never incremented, and only by `App\Services\Payments\Ledger` under `SELECT … FOR UPDATE`.** Two members of staff reconciling the same booking at once would otherwise both write a total derived from the same stale figure, and a booking paid twice would look like a booking paid once. This is the seat-counter hazard [R-2] wearing different clothes, and it gets the same answer — including a MySQL-only test that proves the lock is real, because SQLite has no row locks to take.

**A refund is a negative payment, not a status.** The original keeps its date, its reference and its slip — the evidence of what was actually received — and the paid total stays a plain SUM with nothing to special-case. Same discipline as a refused visa application being kept rather than reopened.

**Callback idempotency is a database constraint, not code.** `payment_transactions` is unique on `(provider, provider_event_id)`, so a webhook delivered twice fails the second insert. Tested against the constraint rather than a driver, because the driver that will use it does not exist yet — and the guarantee is the index.

**BML Connect is a seam that refuses loudly.** Merchant onboarding has not happened, so `isAvailable()` is false and the method is never offered; calling it anyway throws and says what is missing, ending with "Nothing has been charged." A driver that quietly returned a pending payment would put a "paid by card" row in front of staff for money nobody took. It stays off even with the switch on, because an enabled flag with no API key is a button that throws.

**No bank account number is invented.** `config/payments.php` ships empty and the confirmation page says nothing about where to send money while it is. A made-up account is not a placeholder — it is an instruction to a customer to send money somewhere, and it is the same class of defect as the fabricated social links and the `PLxxxxxxxxxx` playlist that reached the live site. Two tests hold that line.

**Instalments are deliberately not modelled.** §5.3 names deposit-plus-instalments, but the terms — how much deposit, how many instalments, how far before departure the balance falls due, what happens when one is missed — are the operator's commercial policy and nobody has stated them. A guessed schedule shown to a customer is a promise this application invented. Until then a booking takes any number of payments towards its total, which is what happens on the phone today.

The slip lives on the payment rather than in the document wallet: `documents.traveller_id` is NOT NULL and the person who pays is often not one of the travellers. It is on the same private disk, behind the same short-lived signed URL, through the same policy, and every *download* is audited.

Still to come in this phase: invoices and receipts as PDFs, the Pilgrim Portal, the minimal CRM, and the import of historical customers.

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

**Implemented — the Pilgrim Portal, first version.** Only what is backed by a record: the booking, what has been paid and what is still claimed, what documents are on file and whether they have been checked, where the visa and the Umrah permit have got to, the hotels and the itinerary. A section with nothing behind it does not render at all, and a test asserts that.

The rest of the list above — flight centre, learning progress, Ziyarah companion, packing checklist, readiness score as a single number — has no data behind it. A portal full of permanently empty panels, or of plausible invented content, is the defect that put fabricated social links and a `PLxxxxxxxxxx` playlist on the live site. They arrive as the records that feed them arrive.

**Access is a link staff issue and send on WhatsApp, and that is a decision rather than a shortcut.** There is no SMTP and no SMS gateway, so a password login would have **no password reset** and an emailed magic link would have nothing to send it with. A login somebody can be locked out of for ever is worse than no login. What does exist is a member of staff with WhatsApp open, which is how this operator already talks to every customer. When a delivery channel arrives, self-service sign-in goes behind the same portal and this stays as the fallback.

The link is a bearer credential and is treated as one: the token is **stored hashed** so a leaked backup is not a set of working keys; it **expires**; it can be **revoked immediately**, all of a booking's at once, because staff do not know which of the three they issued went to the wrong number; and every use is stamped. It is shown once — a system that can show you the link again can be made to show it to somebody else.

**Using a link spends it.** The token is swapped for a session and redirected away, so it does not sit in every screenshot, in the history of a shared phone, or in the Referer header of every outbound click. No portal URL carries an identifier at all: the session says which booking.

**The portal never hands a file back.** It says a passport is on file and whether it has been checked; it does not return the image. A link sent over WhatsApp will be forwarded into a family group chat, and the difference between "your passport is verified" appearing there and a passport scan appearing there is the whole point. Uploading is the other direction and is allowed — and uploading is not verifying: a document that arrives already ticked is a document nobody checked.

A slip sent from the portal is recorded as a **claim**, never as money received. A portal that could mark its own payments as received would be a portal that confirms bookings for free.

Recorded while working, all three found by rendering pages rather than by tests: concatenated translation keys (`__('messages.status.'.$status)`) cannot be checked by anything and would have shown a pilgrim a raw key the day a status gained a value — `TranslationTest` rejected them and they are now whole literals in `App\Support\PortalWords`; `{{ $hotel->city }}` was printing the raw value, so the **checkout review page** read "makkah: Swissotel Al Maqam" to real customers; and the footer carried two live bidi defects on every Dhivehi page — "© 2026 Rihla Travels. All rights reserved." rendered as ".Rihla Travels. All rights reserved 2026 ©". A new guard in `BidiTest` found a third the moment it was written.

### 6.2 Family Portal (Phase 4 — the marketing engine)

Families at home are the strongest referral channel Rihla has. Journey progress, current status, daily schedule, announcements, official group photo album, flight info, prayer requests, emergency information — **with privacy controls the pilgrim owns** (no individual live location unless explicitly enabled; group-level status only by default).

**Shipped (4.6): the Family Portal, and the controls that make it safe.**

A family link is minted **by the pilgrim, from the Pilgrim Portal** — there is no staff route to one, and a test asserts there is not. §6.2 puts these controls in the pilgrim's hands, and a control somebody else can exercise on your behalf is not one you own.

**Group-level by default, and that is a column that starts false.** Without the pilgrim ticking anything, a family sees where the group is and what the office announced. Both are about the whole departure, which is what makes them safe to show without asking. The one individual thing — *whether the group leader has counted them at the last head count* — is off unless they turn it on, and can be turned off again as easily.

**There is no location, and no column for one.** Nothing in this system records where anybody is, which is a stronger privacy control than any setting. The absence is deliberate and not a gap to tidy up later.

**Turning a link off closes it on whoever is already reading.** The access is re-read on every request rather than trusted from the session — a control that only takes effect at the next sign-in is not one you own.

**A family session is not a pilgrim session.** Separate middleware over separate session keys, not one gate with a mode: the two carry different amounts of somebody's life, and sharing the machinery is how they end up sharing a bug. Money, documents, passport details and other pilgrims' names never appear at any setting, because a family link *will* be forwarded into a group chat — that is the expected use, not a risk to mitigate.

**Journey progress is derived, never guessed.** The stage comes from the departure's dates and its recorded hotel nights. Where those do not answer the question it says "they are on the journey" rather than inventing a city from the day number — which would be wrong on the trip where the coach broke down, and that is the trip a family is refreshing the page on.

**Announcements** are the group-level news, with `publish` as a separate permission from `create`: a tour leader drafts what happened, and the office decides it goes in front of forty households. That step cannot be taken back, and the confirmation says so. Taking one down is honest about what it does — it comes off the page; anybody who read it has read it.

Still to come here: the group photo album, flight info and prayer requests.

### 6.3 Tour Leader Portal (Phase 4 — operations leverage)

Group roster, pilgrim profiles, attendance (with bus/room manifests), daily itinerary, announcements, media upload, incident reports, emergency centre, expenses. **Must work offline** — connectivity in transit is unreliable; design for queued writes and sync.

**Shipped (4.5): the roster, the room manifest, the head count and the offline queue.** At `/leader`, its own mobile-first Blade pages rather than a Filament panel — the panel serves a stylesheet with no Tailwind utilities in it, and this is used one-handed, on a phone, in a crowd, sometimes with no signal.

**Whose group.** A leader sees the departures their *profile* is assigned to. That needed a link that did not exist: `departures.tour_leader_id` points at a `Person` (the public profile) while a login is a `User`, and nothing joined them. An account with no linked profile now sees **nothing** and is told to ask the office — the failure mode of a missing link has to be less access, never more, because a roster carries pilgrim names, ages and who is sharing a room with whom.

**Offline, given no queue worker (ADR 0002).** The queue lives on the phone: every mark goes into IndexedDB and the screen updates *before* any request, so a leader at a coach door is never waiting on a spinner. Three things make the replay safe:

- an incident carries a `client_uuid` generated on the phone before the first attempt, so a retry is recognised rather than raising a second incident. A roll-call mark needs no such column — it is keyed on (roll call, traveller), so replaying sets the same value again;
- **the leader's clock decides, not the order things arrive in.** A mark carrying a time earlier than the one already stored is discarded, so a retry of a stale attempt cannot silently undo a correction. A phone set to next month is treated as now, so a wrong clock cannot pin a mark nothing can supersede;
- nothing is trusted because it came from the outbox. Every item is checked against what that user may do and the departure it claims — an outbox is a request body like any other.

The store is cleared on logout, because it holds pilgrim names and a shared phone that keeps them is the same disclosure as leaving the roster on a table.

Verified by driving a real browser offline, tapping, reconnecting, and reading the rows out of the database — not by asserting that a function exists.

Still to come here: announcements, media upload, expenses, and the itinerary.

### 6.4 Scholar Portal (Phase 5)

Profile, content authoring with review workflow, authentic references (Qur'an/Hadith with citations), audio/video library, live sessions, "Ask a Scholar" queue, learning-path curation. Editorial review before publish is non-negotiable for religious content.

**Shipped (5.4): one queue, and a question that is a private message until the asker says otherwise.**

- **"Waiting on you" is one screen.** By the end of Phase 5 there are three resources a scholar reviews and a question queue they answer, each behind its own navigation entry with its own badge. A reviewer who has to check four screens to find out whether anybody needs them checks none, and the editorial gate quietly becomes a bottleneck nobody can see the length of. The desk is that length: oldest first, with how long each thing has been waiting said out loud, and anything over a fortnight coloured.
- **It reads and does not act.** Signing off happens on the page being signed off, where the sources and the text are. A list you can approve from is a list of titles somebody presses a button on — which is the review turning into the formality §6.4 exists to prevent.
- **Ask a Scholar: consent is the asker's, given once, and nobody in the office can grant it.** People ask about a rite they think they got wrong, a marriage, an illness, money. The publish button is *absent* without consent rather than shown and refused, because a button that cannot be pressed teaches people that consent is an obstacle to get round. The model refuses too, from any path.
- **Every question ends.** Answered by a named scholar, or declined with a reason that tells the pilgrim who to ask instead. "We are not the right people to answer this" is honest and sometimes the only correct answer; silence is not one, and there is no path by which a question quietly goes away.
- **An answer carries sources**, through the same table and the same grading rule as everything else — more so than an article, because it is about something the person has already done.
- **Nothing answers automatically.** No template, no canned reply, no assistant. §9.6's pilgrim assistant is Phase 6 and is an assistant, not a mufti.

**Not shipped, and not code:** the audio/video library needs recordings and a storage decision nobody has made; live sessions need a platform and a schedule. The scholar's public profile already exists from §2.6.

### 6.5 Safety & emergency (Phase 4, partial in Phase 3)

One-tap SOS, emergency profile with medical info, incident workflow by severity, missing-person and lost-passport playbooks, heat/hydration advisories, group-wide emergency broadcast. Even a minimal version (emergency contacts + broadcast) belongs in the first portal release.

**Shipped (4.7): emergency contacts as a gate, and a broadcast that is honest about its reach.**

**A departure is held back while a confirmed traveller has nobody to ring.** Blocking on the departure board, because the moment this matters is the moment nobody has time to go looking for a phone number. Configurable, since it is an operational policy rather than a fact, and on by default because the safe default for a safeguarding check is on. A phone number with no name is not a contact.

**The broadcast reaches people through the portals, and says so.** That is the only channel that works on this host: a notice appears on the Pilgrim Portal and on every live family link with no credentials at all. It is why §6.5's "even a minimal version belongs in the first portal release" is satisfiable now rather than after somebody signs an SMS contract.

**Email and SMS are seams, and they refuse rather than pretend.** `MAIL_MAILER` is `log` here — a perfectly valid Laravel setup and a useless emergency channel — and no SMS provider has been chosen; in the Maldives that is a contract, not a config line. Both report themselves unavailable, say exactly what is missing, and record that reason **against every recipient** rather than logging it once. The question after an incident is *did her family get this*, and it needs an answer per household.

**One channel failing never stops another.** Every channel is attempted for every booking and every outcome is written down. There is no `sent` status — either it reached somewhere a person will see it, or it did not, because a row claiming the middle is how "we told everybody" becomes a thing nobody can check. `reached()` counts people, not attempts.

Sending is its own permission and only Operations holds it: a leader in the middle of an incident is the worst-placed person to decide that forty households should hear about it. The confirmation names the working channels and the missing ones, so the decision to phone people instead is made *before* the button. There is no delete verb — what was sent in an emergency is the record of what was said.

Still to come here: one-tap SOS, the missing-person and lost-passport playbooks, and heat advisories.

---

## 7. Workstream D — Knowledge Centre, Ziyarah Guide & Learning Academy

*Sources: `10-RELIGIOUS-HISTORICAL-KNOWLEDGE-CENTRE`, `11-INTERACTIVE-ZIYARAH-GUIDE`, `12-UMRAH-LEARNING-ACADEMY`.*

This is the thread's best strategic insight: **education as differentiation**. Almost no Umrah agency does it well, it is cheap to build relative to a booking engine, it is the single best SEO asset a travel site can own, and it is genuinely useful.

### 7.1 Knowledge Centre

History of Makkah and Madinah; the Kaaba, Hajar al-Aswad, Maqam Ibrahim, Hijr Ismail, Zamzam, Safa & Marwah; Masjid al-Haram and Masjid an-Nabawi; Quba, Qiblatain, Jannatul Baqi, Jannatul Mu'alla; Hira and Thawr; Badr, Uhud, Khandaq, Hudaybiyyah, the Conquest, the Farewell Pilgrimage; prophets and companions; an interactive Islamic timeline and atlas; duas with Arabic, transliteration, translation and audio.

**Editorial standard:** every claim carries a source (Qur'an reference or graded Hadith), every article has a named scholar reviewer, and disputed/weak narrations are labelled as such. Build this into the CMS as required fields, not as a style guide people forget.

**Shipped (5.1): the editorial standard, as the schema and the state machine.** The sentence above is the whole deliverable, and it is enforced rather than documented:

- an article **cannot be approved with no source**, and the screen says why rather than returning a validation code;
- a **hadith reference cannot exist without a grading** — enforced on the model, not in a form, because a form rule is bypassed by a seeder, a console command, or the next screen somebody writes;
- a **Qur'an reference is never given a grading**; one entered is dropped, because a grading on a verse is a category error a reader would take seriously;
- **weak, fabricated and disputed narrations are kept and labelled**, not deleted. Naming a weak narration as weak is what stops a pilgrim repeating it; removing it leaves them hearing it elsewhere with no correction. The label says what the grading *means*, not only what it is called;
- an article **cannot be published without having been approved**, and the approval **names a scholar** a reader can see.

**Approving and publishing are separate permissions held by different roles.** §6.4 calls editorial review before publish non-negotiable, and a reviewer who also holds the publish button performs the check on themselves. A test sweeps every role to prove none holds both — so a future widening fails CI rather than passing review.

A tenth role exists for this: **Scholar**, which is not a staff role. It reaches the Knowledge Centre and nothing else — no bookings, no customers, no money, no website.

**Not one article ships.** AGENTS.md records that fabricated Dhivehi and invented guide steps have already reached this codebase; religious text is the worst possible place to repeat that. The machinery is the deliverable and the content is a scholar's. A test asserts the seeders produce zero articles, and the factories carry deliberately non-religious placeholder text so no fixture can be mistaken for a real narration.

### 7.2 Interactive Ziyarah Guide

Map-first. Each location: history, significance, references, photos, video, audio guide, GPS directions, nearby locations, best visiting time, duas, etiquette, **common misconceptions** (the thread flags this and it is genuinely valuable — it prevents the innovations pilgrims are warned about). Plus scholar-curated walking routes (Around Masjid al-Haram; Madinah Essentials), "Before you visit / After you visit" prompts, and **full offline support** — this is the feature pilgrims use with no data in Saudi Arabia.

**Shipped (5.2): the gate, the misconceptions, and offline that was actually tested offline.**

- A location page carries **the same editorial gate as a Knowledge Centre article** — sources, a named scholar, approval before publication, a reason for withdrawal. The rule lives in one trait both models use, because the second copy of a rule is the one that drifts; the references table is polymorphic for the same reason, so a hadith cited on a location goes through the same grading check.
- **Common misconceptions are a first-class table**, not a paragraph: "what people say" beside "what is actually the case", which is the shape a reader can repeat to whoever told them. **Each correction carries its own sources, and an unsourced one blocks approval of the whole location.** A correction a pilgrim is asked to believe over what they were already told has to be the better-sourced of the two, or it is one more thing to take on trust.
- **Offline is a deliberate save, not a cache that happens to have something in it.** A control on the index fetches every live page through the service worker before the flight, and reports the number it actually saved rather than claiming success. Verified by saving the guide, **stopping the web server**, and opening a location page the browser had never visited: it rendered in full, corrections and sources included, while a page outside the guide fell back to the offline notice. (Playwright's `setOffline` does not stop the service worker's own fetches — it reported success against a server that was still running, which is the sort of green result this check exists to disbelieve.)
- The saved guide lives in **its own unversioned cache**. The shell and runtime caches are wiped on deploy, which would otherwise delete the guide a pilgrim saved the night before they flew, the first time their phone picked up an update on hotel wifi.
- **Coordinates are stored and no map is embedded.** §11.4 wants Google Maps; nobody has supplied a key, and an unkeyed embed renders a grey rectangle stamped "for development purposes only" across a page about the Prophet's mosque. The page links out to the map application the phone already has — which also works from a saved copy with no data. The embed arrives with the key and nothing above it changes.

**Not shipped, and not code:** every location page. There is no named religious reviewer yet, so there is nothing to sign off — and nothing in this feature will author religious text to fill the gap. Photos, video, the audio guide, walking routes and "before/after you visit" prompts also wait on content. What exists is the apparatus that will refuse to publish any of it unsourced.

### 7.3 Umrah Learning Academy

Learning paths (beginner / intermediate / advanced / women's / family / children's), modules on ihram, miqat, talbiyah, tawaf, sa'i, halq & taqsir, common mistakes, packing, health, etiquette. Quizzes, certificates, progress tracking, downloadable audio, pronunciation practice, and a **personalised study plan keyed to the departure date** (60 / 30 / 7 days out). Link modules to the itinerary: if the trip visits Uhud, surface Uhud's history the week before.

**Business tie-in:** completion feeds the Pilgrim Readiness Score (§6.1) and gives sales a real answer to "why Rihla".

**Shipped (5.3): the plan, the gate, and a quiz that teaches instead of marking.**

- A module carries **the same editorial gate** as an article and a location — sources, a named scholar, approval before publication, a reason for withdrawal. It is a claim a pilgrim is about to *act on*, so nothing about a lesson makes it lighter than a page they merely read.
- The **study plan is arithmetic on the departure date**, computed and never stored. Each module carries its own `days_before_departure`, so §7.3's "60 / 30 / 7" is data rather than three hard-coded buckets: move a departure forward and every deadline on it moves, with nothing to re-run and nobody to remember.
- **Progress belongs to the person, not the booking.** Somebody who travels twice keeps what they learned; keying it to the booking would make them start again.
- **The quiz is not a gate.** No score blocks anybody and no certificate is issued from one. Grading is strict set equality — partial credit on "how do I perform this rite" would tell somebody they were mostly right about a thing they are about to do — and the explanation is shown whether the answer was right or wrong, because the mark says there is a problem and only the explanation says what it is. A question with no correct answer, one where every answer is correct, and one with no source are each refused before a pilgrim can meet them.
- **The itinerary tie-in fails loudly.** A module about a place appears only for departures whose itinerary names that place. The match is on text, so it can miss a spelling — and a miss is listed on the departure board for the office to fix rather than the module vanishing. A tie-in that quietly drops a module is the failure this codebase has been bitten by before.
- **Readiness gains a named concern, at attention and never blocking.** "One traveller has not opened the reading" is a phone call worth making; withholding somebody's Umrah over homework is not a thing anybody should build.

**Not shipped, and not code:** every module. There is still no named religious reviewer. Certificates are deliberately absent — a credential in religious learning signed by nobody is worth less than nothing. Downloadable audio and pronunciation practice need recordings that do not exist and speech scoring that nothing here can do honestly.

---

## 8. Workstream E — Back office: CRM, operations, finance

*Sources: `14-CRM-SALES`, `15-OPERATIONS-MANAGEMENT`, `16-FINANCE`, `17-BI-ANALYTICS`, `26-PARTNER-B2B`, `27-LOYALTY`, `28-SUPPLIER-PROCUREMENT`, `29-JOURNEY-PLANNING-CAPACITY`, `30-REAL-TIME-OPERATIONS-COMMAND-CENTER`, `32-EXECUTIVE-OKR`, `33-CONFIG-FEATURE-FLAGS`.*

The thread specifies ten enterprise modules here. For an agency of Rihla's size, build them in this order and stop when the pain stops.

### 8.1 CRM (Phase 3, minimal; Phase 5, full)

Leads from web forms and WhatsApp, lead pipeline, follow-up tasks and reminders, quotations, communication history, customer 360 view, tags and preferences, referral tracking, post-Umrah re-engagement. **Minimal version first**: every enquiry becomes a tracked lead with an owner and a next action — that alone beats a shared inbox.

**Shipped (5.5): everything between "somebody asked" and "somebody paid".**

- **Quotations, superseded and never edited.** Today the price lives in a WhatsApp message and nowhere else, so nobody can answer "what did we quote them?" a fortnight later. A quotation is that answer, and editing one that has been sent is refused **on the model**, not merely hidden on a screen — a console command, a seeder or the next screen somebody writes goes straight past a hidden button. Both versions stay on the record with a link between them, so a year later somebody can see the price moved and by how much. The expiry is required and derived, because a quotation with no end date is a price the operator is held to for ever.
- **Follow-up tasks, because one next action was never enough.** `next_action_at` holds a single date; real follow-up is "ring them Tuesday, and chase the deposit on the 14th". Tasks hang off an enquiry, a customer **or a booking**, because "ring them about their passport" is not a lead and inventing a fake one to hold a reminder puts a deal nobody is working into the pipeline. "Done" is a time and a person, not a flag.
- **A "Today" screen: yours first, everyone else's collapsed.** A shared list where nothing is yours is a list nobody works. The reminder is the screen and not an email, which is the honest version on a host with no SMTP and no queue worker (ADR 0002) — a promise of a reminder nothing keeps is worse than none.
- **Customer 360, assembled on read.** Journeys taken, what is still owed **kept apart by currency** ([R-7] — the one mistake in this area that looks right on screen), open work from every direction, what they asked a scholar. It gathers and does not judge: no score, no segment, no "value" band, because a number that ranks customers ends up deciding who gets a phone call and nobody asked this system to make that decision.
- **Tags and referrals on the customer, not the lead.** A tag on an enquiry is lost the moment the enquiry closes, which is exactly when it starts being useful. A referral points at a customer already on file rather than a name in a box, because the point of recording one is being able to thank the person who made it.

**The other half of that is now built** (`App\Support\Referrals`, `/staff/who-sends-us-people`, "Who sends us people", and a Referrals section on the customer dossier). The referral field has been on the customer form since Phase 5.5 and nothing surfaced it, so the reason it points at a person on file rather than a name in a box was true in the docblock and nowhere a member of staff could act on.

**It does not claim to know who has been thanked.** A thank-you is a telephone call and nothing here can see one. What it can see is whether anybody wrote a follow-up task down about the referrer *since their last referral flew*, and that is what the screen says — in those words, on the page, not only in a comment. Storing a `thanked_at` would mean somebody ticking a box, and a ticked box is evidence that a box was ticked. No schema change: a follow-up is an ordinary `CrmTask`, the machinery §8.1 already has.

A referral whose booking has not yet flown is counted but owes nothing — a favour in progress, and thanking somebody for a journey that has not happened is worse than saying nothing.
- **Post-Umrah re-engagement as a list, not a campaign.** Both exclusions are the design: somebody with an open enquiry is already being spoken to, and somebody with an upcoming departure has not gone quiet. Both are live reads, so nobody has to remember to take a person off a list. Nothing is sent from it.

**Not shipped, and not code:** leads *from WhatsApp* still need the Business account and cost model §11.2 describes. A quotation is recorded rather than emailed, because there is no SMTP.

### 8.2 Journey planning & capacity (Phase 4)

Journey workspace per departure: capacity, flight allocation, hotel blocks, **room allocation** (intelligent grouping by family/gender/age), transport, staff and scholar assignment, checklists, conflict detection, waitlist, readiness score. Room allocation and rooming lists are, in practice, where operator time disappears.

**Shipped (4.1): rooming with conflict detection, deliberately without the allocator.** Rooms belong to a hotel stay, so a party can be in a four-bed room in Makkah and a two-bed room in Madinah. Six checks run over a whole hotel's list — over capacity, men and women in a room not marked `family`, a room nobody has designated, a child with no adult (age measured *at the departure date*, threshold in config because it is a safeguarding judgement), somebody in two rooms, and a confirmed traveller with no room at all. The last is the one that is invisible on a rooming list, because the person simply is not on it.

The "intelligent grouping" this section asks for is **not** built, and that is the decision rather than an omission: an allocator that shuffles real pilgrims on rules nobody has written down is how a mother is separated from her children by a heuristic. Every mistake is found and named for a person to fix. Over capacity is *reported, not refused* — it is sometimes deliberate for a night, and blocking it is how staff go round the system with a spreadsheet.

**Shipped (4.2): the departure board.** One screen that answers "what is not ready?" across every upcoming departure, so the answer does not have to be assembled by opening six other screens and remembering what was on each. Computed at read time, never stored — the same reasoning as `TravelReadiness`, and here a stale flag means a departure nobody checks because the board said it was fine.

It is **not** the "readiness score" this section asks for, and that too is deliberate. "72%" tells the person who has to act nothing; "three passports missing, one hotel's rooming unsettled, no scholar" is a morning's work. So it returns named concerns at two severities: **blocking** (a traveller cannot go, money is owed, no tour leader) and **attention** (rooming unsettled, no scholar, seats held but never confirmed, a queue while seats sit free). Folding the two together would either raise a false alarm on every departure or bury the real one.

Flights, transport and supplier coordination are **absent rather than green**. Nothing in this system records them, and reporting them as fine from the absence of data is the class of lie that put invented social links and a `PLxxxxxxxxxx` playlist on the live site. The board says so on its face.

### 8.3 Operations (Phase 4)

Pre-departure checklists, airport operations, flight monitoring, hotel operations, transport/bus and seat management, attendance, incident management, daily operations log, meeting-point manager, supplier coordination. **Nusuk compliance gate** (§5.4) lives here.

**Shipped (4.3): incidents.** The safety-critical half, and what §6.5 asks for as a minimum. An incident is always about a departure and sometimes about a person — a coach that does not arrive has no victim, and forcing a name onto it means somebody picks one at random.

**Three severities, each with a stated meaning**, not a five-point scale: an undefined scale gets used as a mood ring, where everything is a 3 until something goes badly wrong and then everything is a 5. `minor` is handled on the spot and recorded so it is not lost; `serious` means the office needs to know today; `emergency` means somebody needs to act now.

**When it happened is recorded separately from when it was typed.** On a trip those are routinely hours apart, and a report timed by the typing is useless for working out what led to what.

**The narrative is append-only.** Notes are added, never edited or deleted, each with an author and a time — the same reasoning as [R-8]'s supersede-never-overwrite. An incident report that can be quietly rewritten after the fact is not evidence, and this is the record that gets read if anything ever reaches a lawyer or a regulator. Closing one *requires* a sentence saying what was done: "resolved" with no sentence is a record that looks complete while being useless. Reopening keeps the original resolution in the trail.

The screen answers one question — **what is open that nobody is on?** — and that is the number on the navigation badge. "Twelve open" is a fact nobody can act on; "one emergency with nobody on it" is somebody's next five minutes. The list is sorted worst-first, then most recent, so a lost boarding pass from an hour ago cannot outrank an emergency from yesterday.

**The Tour Leader raises one and adds to it; they do not close it or hand it on.** They are the person standing there when it happens, and an incident that has to wait for the office to open is one recorded from memory two days later, if at all. But a leader closing their own incident from the coach is how a serious one stops being followed up.

**Shipped (4.4): attendance and the daily operations log.**

Attendance is **not a daily register**. A count is taken at the moments where somebody can actually be left behind — boarding at Velana, off the coach in Madinah, back from the Haram before a transfer — so a roll call is named after its moment and there are three in a day or none. The moment is free text because the ones that matter differ by itinerary, and a closed list would be wrong for the first trip that needs another.

**An unmarked traveller is not present.** This is the whole of it. Nine of eleven marked is not "nine present"; it is a count that has not been finished, and the two nobody marked are exactly the two to go and look for. There is no default state and no row exists until somebody marks one, so "unmarked" is computed as the difference between the departure's confirmed travellers and the rows that exist. Somebody added to the departure after the count was taken therefore shows up as unmarked, which is correct.

**Excused is a third state, not a kind of absent.** Somebody who stayed at the hotel with a fever, with the leader's knowledge, is not missing. Folding the two together means the screen cries wolf on every trip and stops being read.

The daily log is **deliberately not an incident**: the coach being forty minutes late and the hotel moving the group to the third floor belong there, while things that went wrong carry a severity, an owner and a resolution. Keeping them apart is what stops the incident list filling with weather. The day an entry is about is separate from the day it was written — a log written up the next morning is still about yesterday.

The Tour Leader takes the counts and writes the day up, because they are the one standing at the coach door. They cannot delete a count: a count deleted from the coach is a count nobody can check.

Still to come in this section: pre-departure checklists, flight and transport records, and the Nusuk compliance gate's own screen.

Defer the thread's real-time "Command Center" with live maps (`30-...`) to Phase 6+; it presumes staffing Rihla does not have.

### 8.4 Finance (Phase 5)

Customer payments and instalments, invoices/receipts, refunds, supplier payments, expenses, **per-journey profitability**, package cost builder, multi-currency (MVR/USD/SAR), approvals, bank reconciliation, audit trail, tax configuration. Per-journey profitability is the report that changes pricing decisions.

**Shipped (5.6): the other half of the arithmetic, and a report that will not invent a number.**

Payments, invoices, receipts, refunds and the audit trail were already here from Phase 3. What was missing was everything going *out*, so "what did this journey make?" could not be asked at all.

- **Costs are recorded per departure, and each one says whether it is per person.** A coach costs the same for eighteen travellers as for thirty; a hotel bed does not. Without that distinction the margin is wrong at every party size except the one somebody happened to have in mind, and wrong in the direction that flatters a small group.
- **Estimated, agreed, paid — three statuses giving three different numbers**, and the report says which it counted. A margin built on estimates is a forecast; one built on paid costs is history, and it stays incomplete for months after a journey returns. The screen lets you switch between them.
- **It will not invent an exchange rate.** Hotels bill in SAR, airlines in USD, the office collects MVR. Where a rate is missing the report keeps each currency apart and says *which* currency is missing one — a made-up rate here is a number somebody prices a season on. Set the rate in `config/finance.php` and the same report gives one total, labelled with the rate and the date it was set; leave the date out and it says nobody has recorded when the rate was last checked.
- **A journey with no costs recorded says so**, rather than showing the whole take as margin. That silence is how a season gets priced on a number that never included the hotels.
- **The margin is behind its own permission.** "What did this journey make?" is not a number the office hands round; Finance holds both halves of the arithmetic and nobody else reads the result.
- **This is also §8.4's package cost builder.** A cost entered while a departure is still selling *is* the estimate; the same rows become the real figures as invoices arrive. A separate planning screen would mean two sets of numbers to keep in step, and they would not be.

**Not shipped, and not code:** the exchange rates themselves — the operator states them, this system will not guess. Supplier payment *runs*, approvals and bank reconciliation need a bank feed and an approval chain nobody has described; tax configuration needs the GST position, which is still an open question in §13.

### 8.5 BI (Phase 6)

Executive dashboard, sales/marketing/customer/financial/operational/learning analytics, forecasting, smart alerts. Start with ~10 KPIs (§10.5), not a warehouse.

**The dashboard is built** (`App\Support\Kpis`, `/staff/performance`, "How the business is doing"). Computed at read time like every other read model here; nothing is stored, and there is no nightly job, because there is no queue worker (ADR 0002).

**Nine of §10.5's thirteen measures are real arithmetic. Four are named absences, and that distinction is the point of the screen.** A KPI is in one of three states and says which:

| State | Meaning |
|---|---|
| Measured | A figure, over real rows. |
| Nothing to measure yet | The measure works; the input set is empty in this window. **Not a zero** — a fortnight in which nobody enquired is not a 0% conversion rate, and a dashboard that renders it as one starts a board meeting about a crisis that did not happen. |
| Not measured — nothing records this | The instrumentation does not exist. Named rather than proxied. |

The four in the third state, with what each would need:

- **Visit → enquiry** — a page-view log. There is none, and a web-server access log cannot tell a reader from a crawler.
- **Portal weekly-active pilgrims** and **family-portal engagement** — a visit log. `portal_accesses.last_used_at` and `family_accesses.last_used_at` are single timestamps overwritten on every visit: they answer "when was this person last here", miss everybody who came back twice, and count nobody's second visit. **A last-seen count reported as "weekly actives" is a number somebody quotes to a bank**, and there is no way back from that once it is quoted.
- **NPS after return** — a survey. Nobody has been asked, and inferring it from repeat bookings would be a different measure wearing its name.

Three further honesty rules the screen carries, each with a test that plants its opposite:

- **An empty Nusuk permit register is not a 0% issue rate.** Zero permits recorded against travellers who flew may mean the permits were obtained and never typed in; reporting 0% is an accusation against the visa desk rather than a fact about it.
- **A booking that has not flown has not failed to pay in full.** The deposit-to-full measure counts only journeys that have already departed, so the question is settled.
- **The margin inherits §8.4's refusal to invent an exchange rate.** A journey whose money spans currencies with no configured rate is left out of the average, and the count left out is on the screen.

`kpi.view` opens the dashboard; the margin row needs `profit.view` separately. The Reporting role holds the first and not the second, and the page says so where the row would have been — two people comparing this screen in a meeting must not find different lists and no explanation.

**Forecasting is built** (`App\Support\SeatForecast`, `/staff/forecast`, "What is likely to fill"). The method is pace and only pace: at any point before a departure some fraction of its eventual seats has been sold, past journeys say what that fraction usually is this far out, and dividing what is sold today by that fraction projects where this one lands.

**It refuses more often than it answers, on purpose.** A seat forecast is a number somebody charters an aircraft on, so it declines by name when: fewer than `forecast.minimum_comparable_journeys` (3) comparable journeys have flown; nobody had booked this early on any of them, so there is no fraction to divide by; nothing has been sold on this one yet; or the departure is inside `forecast.quiet_within_days` (7), where the useful question is who is outstanding and the departure board already answers it. **The historical import is why the floor is not one**: bookings brought in from the old spreadsheets carry the import's dates rather than the day anybody actually booked, so their apparent pace is an artefact.

It gives a **range, never a point**, and the range is the most and least front-loaded of the past journeys rather than a statistical band. Note which way round that goes — a journey that had sold most of its seats by this point front-loaded, so this one at the same seat count projects *low* against it. Getting that backwards inverts every recommendation on the screen while leaving it looking entirely reasonable, so it has a test of its own.

**Smart alerts are built** (`App\Support\Alerts`, `/staff/needs-attention`, "What needs attention", with a red badge in the navigation counting only what is due today).

**It only raises what nothing else is watching.** Adrift enquiries and follow-ups belong to Today; outstanding documents to the chasing list; everything blocking a particular departure to the departure board. Restating any of those would double every number in the office and leave both copies less trusted than one. So it raises: a departure off its selling pace while there is still time to act; a departure selling past its capacity; money sitting unreviewed for more than three days; a quotation about to lapse with no answer; a departure flying within a month that still has a blocker — that one *is* readiness, raised here only because the board tells you nothing until you open it, and nobody opens it on a quiet Tuesday; and religious content a scholar has gone quiet on for over a fortnight, because the only desk that shows it is the quiet reviewer's own.

**Every alert names who acts and where, and is shown only to somebody who can act on it.** The page is open to anyone in the panel; the list is not. A tour leader shown "MVR 84,000 is waiting to be reconciled" has learned something about the business and can do nothing with it. Where the list is shorter for a role, the page says so — and where it is *empty* because everything was withheld, it says that instead of claiming the office is quiet. Conflating those two empty states was a real defect, caught by reading the rendered page: a tour leader saw "None of those is true right now" printed directly above "5 alerts are hidden".

Nothing here is stored. A stored alert has to be dismissed, dismissal has to be remembered, and a remembered dismissal is how a real problem stays hidden for a season.

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

**Decided: stay on cPanel.** The owner was asked directly and chose to stay. The compromises above are now commitments, written down with what each one costs and when to revisit, in [`adr/0002-stay-on-cpanel-shared-hosting.md`](adr/0002-stay-on-cpanel-shared-hosting.md). The two that arrive soonest are queues and Redis, and both arrive with bookings and payments in Phase 3 — so Phase 3's estimate should carry the extra work of making every job safe to run late and twice.

### 9.2 Admin panel — adopt Filament

The hand-rolled Blade admin is ~20 view files for six models. The plan adds 25+ models. Rebuilding that by hand is months of CRUD.

**Recommendation: Filament v5** (current line — v5.8.x as of September 2026; requires Livewire 4 and supports Laravel 13, verified on Packagist). MIT-licensed, actively maintained, the default choice for Laravel admin in 2026, and it collapses standard CRUD from days to hours. Cost: the team must learn Livewire. Migrate incrementally — new modules (bookings, travellers, payments, journeys) in Filament first, existing trip/media/settings CRUD ported afterwards. *(v1.0 said v4; v4.13 also supports Laravel 13 but is the previous major — start on v5.)*

**Status: adopted, new modules first.** Filament v5.8 is installed and serves a panel at **`/staff`** — not `/admin`, which the Blade panel still owns. New modules are built in Filament; the six existing screens move across in Phase 3, one at a time. The reasoning and the three things that had to be dealt with before it worked on this host are in [`adr/0003-filament-for-new-admin-modules.md`](adr/0003-filament-for-new-admin-modules.md).

The short version, because each one would otherwise be rediscovered: **the panel cannot run under the site's nonce-based CSP** (Filament writes `window.filamentData` in an inline script with no way to attach a nonce), so `script-src` is relaxed for `/staff` and nowhere else; **Filament's published assets must be committed**, because the deploy is a `git pull` with no build step, and `filament:install` had git-ignored all three directories; and **installing it added two unauthenticated public routes** that answered 500 until their migrations were run.

Also: `composer update` now needs `php artisan filament:upgrade` and a commit of the republished assets. A test fails if that is forgotten.

**The first module is staff accounts and roles** — a gap, not a duplicate: §9.3 shipped nine roles with no screen to assign them, so an account could only be made with `php artisan admin:create` and a role only from tinker. Building it found a live authorisation defect that predates Filament: `Access::matrix()` defined the content permission set *by exclusion*, so adding `user.*` to the permission list silently gave the Content Manager and Operations Manager the ability to hand out roles including their own, and the read-only set — matching any permission containing `.view` — gave Reporting every staff name and email address. Both are defined by inclusion now. See ADR 0003.

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

**Trips are done.** `trips` carried *both* mechanisms at once — a `locale`
column that made a Dhivehi trip a second duplicate row, and four `*_dv`
columns that made it the same row twice — and neither reached a visitor:
`Trip::published()` has never filtered by locale, and no public view has ever
read a `*_dv` column. What the Dhivehi half did do was make all four `*_dv`
fields **required** the moment an editor chose Dhivehi, for text nothing
rendered. A trip is now one row holding `{"en": …, "dv": …}` per field, read
through `spatie/laravel-translatable` with an English fallback, and the admin
form asks for both languages side by side. The reasoning, and the rule for
which entities get JSON columns and which get translation tables, is in
[`adr/0001-how-content-is-translated.md`](adr/0001-how-content-is-translated.md).
**The Umrah guide is done too.** A step was two rows agreeing only by
convention on their `step_number`, so publishing the English step said nothing
about the Dhivehi one, reordering had to be done twice, and — the part a
pilgrim saw — a single missing Dhivehi step sent the *whole* guide back to
English. It is now one row per step, and the fallback is per field: a step
translated by halves shows the Dhivehi title above the English instructions.
`dua_text` is deliberately not translated; it is the Arabic of the rite.
**The homepage blocks are done.** `hero_banners` and `why_sections` each had a
`locale` column with one row per language, and `why_features` — the three cards
under "Why Choose Rihla" — had no translation mechanism at all, so a Dhivehi
section meant a second set of three cards related to the English three by
nothing. The homepage also filtered banners by locale, so a slot with no
Dhivehi row simply vanished from `/dv`. One row each now, per-field fallback.
The admin screen for the why-section used to *create* a Dhivehi section when
none existed, with two hard-coded Thaana sentences nobody had written — that is
gone. **§9.4 is now complete.** `media.title` and `media.caption` were the last
untranslated content columns, and they follow the same shape as the rest. No
content table carries a `locale` column any more.

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
| Observability | A07, `39-...` | ~~Laravel Pulse~~ **done — at `/pulse`, configured for cPanel (ADR 0005)**; ~~log levels and retention~~ **done — the stack rotates and preflight warns**; Sentry **still needs the owner's account** |
| Deployment & release | A09, `51-...` | Extend the existing deploy doc |

Defer (record the decision, do not build): event-driven architecture (A04, `37-...`), data lakehouse (A28), multi-tenancy (`47-...`), plugin marketplace (`55-...`), enterprise meta-model (META01), reference models RM01–RM10, reference architectures RA01–RA25. These describe an organisation with an architecture function. Rihla should revisit them if it franchises or white-labels.

### 9.6 AI features — scoped honestly

*Source: `18-AI-PLATFORM`, `54-AI-GOVERNANCE`, `A26`.*

The thread proposes ten AI assistants. Build **two**, well:

1. **Pilgrim assistant (RAG over Rihla's own content)** — answers about packages, visa/Nusuk steps, documents, payments, and religious guidance **strictly from scholar-approved Knowledge Centre content**, with citations and a hard "I'll connect you to an advisor" fallback. Never let it improvise rulings.
2. **Staff drafting assistant** — quotations, itinerary text, announcement translation (en/dv/ar) with human review before send.

Governance, in one page not forty: approved use cases, prohibited behaviours (no independent fatwa, no medical/legal advice, no personal data in prompts), human-in-the-loop for anything customer-facing, logged prompts/responses, and a visible "AI-assisted" label.

**Both are built, and the governance page is [ADR 0006](adr/0006-an-assistant-that-mostly-refuses.md).**

**The pilgrim assistant is switched off and structurally incapable of answering a religious question no named scholar has approved** — whether it is switched on or not, and whatever an API key is set to. "Never let it improvise rulings" is implemented as five constraints in code above the model, not as wording in a prompt:

1. Only approved content is retrieved. `Corpus` queries through the editorial gate's `live()` scope and cannot see a draft.
2. **No matching source means no model call at all.** This is the whole of the behaviour today, because nothing has been approved.
3. No configured provider means no model call. Also the whole of the behaviour today.
4. **An answer that cites nothing is discarded.** The model marks each claim `[1]`, `[2]`…; a reply with no valid marker is thrown away and replaced with the referral. An uncited sentence about a rite is exactly the improvised ruling §9.6 forbids, and reading it will not tell you which it is.
5. The asker is never named in the prompt.

It sits **in front of** the scholar's queue rather than beside it, on the same portal screen: a pilgrim asks once, and either the approved pages answer it or a person does, with the question carried over so they do not type it twice. It answers nothing today, and that is the feature working — an assistant that began answering the moment a key was pasted in, from a corpus of nothing, is the failure this design exists to prevent.

**The public Knowledge Centre reader's pages were built with it** (`/knowledge`, `/knowledge/{slug}`). Phase 5.1 built the editorial standard and the admin and stopped there; §9.6 requires citations, and a citation that cannot be opened and checked is not a citation.

**The staff drafting assistant drafts and does not translate.** §9.6 names "announcement translation (en/dv/ar)" and that half is **declined, not deferred**: `AGENTS.md` records that machine-generated Dhivehi has already reached this site — many unrelated English sentences mapping to one identical Dhivehi string — and it was found by a reader, not by a test. A button producing Dhivehi nobody in the office can check is the same mistake with a nicer interface. `StaffDrafter::whyNoTranslation()` says so on the screen rather than leaving a gap somebody fills back in next quarter.

Nothing the drafter produces is sent, saved, published or quoted: `draft.use` is a verb about having a machine write a first draft, and a drafted announcement still needs somebody who may create one.

**What switching either on actually needs** is an Anthropic API key and a view on cost per question — and, for the pilgrim assistant, the thing that matters more: **a scholar willing to put their name to the pages.** Without that a key changes nothing, which is why it was built in this order.

---

## 10. Non-functional requirements

### 10.1 Performance

| Metric | Target | Notes |
|---|---|---|
| LCP (mobile, 4G) | < 2.5 s | Homepage hero must not block it; use a poster image, lazy-load video |
| CLS | < 0.1 | Reserve space for hero, cards, images |
| INP | < 200 ms | Alpine is fine; avoid heavy JS on the homepage |
| Lighthouse performance (mobile) | ≥ 90 | **Done — in CI.** Measured 90–97 on the three audited pages |
| Server response (TTFB) | < 400 ms | Cache package/departure queries; index them properly |
| Image delivery | WebP/AVIF, responsive `srcset`, CDN | Currently unoptimised |

**Lighthouse runs in CI — done, and its first run found two defects.** `npm run lighthouse` had existed in `package.json` since the beginning and nothing ever ran it, so nothing stopped an accessibility or SEO regression reaching the site. The job audits the homepage, the packages list and the Umrah guide against `lighthouse-budget.json`, and the numbers in that file were **measured against the real pages before they were written down** — a threshold set by guesswork is either meaningless or flaky, and a flaky gate is worse than none because people learn to re-run it until it passes.

  **Only the deterministic categories gate a merge.** Accessibility and SEO are rule-based: the same page scores the same on any machine, so a drop is a real regression somebody introduced, and both are required at 100. Performance is timing on a shared runner and moves several points between identical runs, so it is reported and does not fail. The realistic performance regression here is somebody committing a four-megabyte hero photograph, and **bytes are deterministic** — so page weight is the performance gate instead, budgeted at 900 KB against measured weights of 174, 155 and 322 KB. The Lighthouse version is pinned exactly, because scoring changes between releases and a floating version turns the gate red for no code reason.

  The two defects it found on its first run, both invisible to every existing test:

  - **"Learn More" is not link text.** The homepage's why-section button said it, and a screen reader offers its user the links on a page as a list, out of context — where "Learn More" says nothing at all. It is also the only thing a search engine has to go on about what is at the other end. Now "Read the Umrah guide". The same button had already been repointed once, from an `/about` that 404ed; this gives it words. The migration matches the exact seeded value in each locale, so text an editor chose is untouched.
  - **The footer skipped a heading level.** "Quick Links" and "Contact Info" were `<h3>` under no `<h2>`, so on any page whose own content has no `<h2>` — the packages list is one — a screen reader navigating by heading went from the page's `<h1>` straight to an `<h3>` and was told a section was missing. Now `<h2>`; the visual size was always set by the class, not the tag, so nothing moved.

### 10.2 Accessibility — WCAG 2.2 AA

Keyboard navigation throughout, visible focus states, screen-reader labels, adjustable text size, high-contrast mode, reduced-motion support, correct `lang`/`dir` per locale (the layout already switches `dir` — good), and Thaana font legibility at small sizes. The `skip to main content` link already present is a good sign; extend the discipline.

**Colour contrast is now done** (§4.5). Every interactive combination in the palette is verified against WCAG 2.2 AA, and the focus ring is a single consistent wine across the whole site — previously it was sky blue, green, gold, blue, indigo and grey depending on the screen. The contrast reference table is in [`BRAND.md`](BRAND.md) §3. **The non-colour half is now done too** (D51–D55): every icon is hidden from assistive tech, every link and button has a name at every viewport width, the trips tabs are a real tab widget with arrow-key navigation, the header marks the current page, and the heading outline no longer skips levels. `AccessibilityTest` checks all of it against the rendered DOM of twelve pages — six public pages in both locales — so it cannot quietly regress. What is left here is what a test cannot judge: **an actual screen-reader pass in Dhivehi**, which needs a Thaana-speaking tester with NVDA or VoiceOver, and a check that the reading order of RTL pages matches their visual order.

### 10.3 Testing

| Level | Target |
|---|---|
| Unit | Business rules: pricing, instalments, seat holds, readiness score |
| Feature | Every public route in both locales; full booking flow; payment webhook handling; portal authorisation (each role sees only its own data) |
| Regression | The homepage-translation assertion from P0.1 — permanently |
| Browser (later) | Booking flow and portal on mobile viewport |
| Coverage | No number worth policing; instead require that **every bug fixed gets a test** |

### 10.4 Security

Roles/permissions (§9.3) **— done**; audit log **— foundation done (`2e81a34`): create/update/delete on all eight models, secrets excluded, actor snapshotted so it survives staff deletion, read-only viewer behind `audit.viewAny`. Bookings, payments and documents join the audited list when they exist**; **MFA for staff — done.** A TOTP second factor on `/staff`, with RFC 6238 implemented directly rather than taken as a dependency: on a host updated by hand (ADR 0002) every package is another thing somebody must remember to bump, and a stale one in the authentication path is worse than forty lines pinned to the specification's own published test vectors. The secret and recovery codes are `encrypted` in the column, because a shared-host database dump is the realistic threat and a TOTP secret in a dump is a permanent second factor for whoever reads it.

  **Enforcement means "you must enrol", never "you cannot get in".** Required of the roles that read personal data or move money (`config/mfa.php`), offered to everybody else — a tour leader at a hotel desk in Makkah on a borrowed telephone must still be able to take a head count. Somebody in a required role with no factor is sent to enrolment, which they can always complete; `SecondFactorTest` walks that whole round trip, because every individual step of it passes while the path as a whole is broken, and a staff account nobody can open is an outage, and an outage is how a security control gets switched off for good.

  No QR code: rendering one needs a package, and every authenticator accepts a key typed in by hand. Recovery codes are stored hashed and shown exactly once, and the screen says so in those words.

  **It ships with enforcement off**, and that is deliberate rather than timid. Enforcing on release would mean the owner signs in after the next deploy, is sent to enrolment, and needs an authenticator app on the telephone in their hand before they can reach a booking — at whatever moment the deploy happened to land. Changing how somebody signs in to a live system is their decision, on a morning they choose, not a side effect of a release. Off does not mean absent: anybody can enrol at `/two-factor` today and everybody who has is still asked for a code. **Set `MFA_ENFORCE=true` when you have enrolled**, and the roles above are then compelled. The same switch is the emergency stop.

  Still open here: signed, expiring URLs for document access (never public storage paths); rate limiting on auth **— done (D35): registration, password reset (capped per caller *and* per address), reset submission, password confirmation and password update; login was already covered by `LoginRequest`**, booking and payment endpoints; CSRF on all forms (Laravel default — verify on the new AJAX paths); **security response headers — done (D36): `nosniff`, `SAMEORIGIN`, `strict-origin-when-cross-origin`, a `Permissions-Policy` denying the features the site does not use, HSTS over HTTPS only, and `X-Powered-By` removed.**

**Session security and device management — done.** `/devices` lists every browser an account is currently signed in on, names it, says when it was last used and from what address, marks the one being used now, and signs out one or all of the others. It reads the `sessions` table directly and `App\Support\SignedInDevices` explains why: the session *is* the record of being signed in, and a separate devices table would be a second copy of the same fact that parts company with it the first time a session expires quietly — leaving a screen that offers to sign out a telephone somebody sold last year, does nothing, and says it worked.

  **Two properties carry it, and one of them is the empty state.** A session id is the only thing naming a row, so the account is in the query as well — otherwise the screen is a way to sign a colleague out of the panel in the middle of a booking, and `SignedInDevicesTest` proves it by planting exactly that. And because this only works on the `database` session driver, the page checks first and **says it cannot see** rather than showing an empty list: an empty list reads as "you are signed in nowhere else", which is the opposite of the truth and the worst possible answer to somebody checking whether they have been broken into.

  **Changing a password now signs out every other session**, which it did not before. That is the whole point of changing it after a fright — a session already open never re-checks the password, so without this the person who changed it because they thought somebody had reached their account had changed nothing at all. A password *reset* from an e-mailed link goes further and signs out everywhere including the current session, because that is the case where the intruder may be the one still signed in; a change on the profile page keeps this session, since the person holding it has just proved the old password in it.

  `SESSION_ENCRYPT` was considered and **deliberately left off**. The premise for turning it on — that flashed form input puts passport numbers in the session payload — did not survive being checked: the payload held a CSRF token, the locale and a status message, and nothing personal. More to the point, anybody holding the `sessions` table already holds the session ids, which is a straight account takeover and worth far more than the payload. Encrypting it would have logged everybody out once in exchange for the appearance of hardening.

**Two badges were invisible, and no test could see them.** Found by rendering the new page rather than by asserting on it. `wine` and `gold` were the only palettes in `tailwind.config.js` without a `DEFAULT`, so `bg-wine`, `text-wine`, `border-s-wine` and `border-s-gold` **compiled to no rule at all** while `text-ink` and `bg-cream` worked everywhere — which is why everybody wrote them. The failure is silent in the worst way: the element renders, the markup is right, every assertion passes, and `bg-wine px-3 py-1 text-white` is white text on no background. Live on two screens: the tour leader's head count reading "3 missing" and the Family Portal's "Not yet counted", on a telephone, at a hotel desk in Makkah, to the two audiences least able to work out what they were missing. Both palettes now carry a `DEFAULT`, which fixes eight views at once, and `BrandColourTest` fails if any view again names a palette that has none.

**Encryption at rest for identity documents and payment slips — done.** A passport scan and a bank transfer slip are now written to disk as ciphertext under `APP_KEY`, through `App\Support\EncryptedFile`. That class states in its own docblock what this is worth, because "encrypted at rest" is a phrase people stop reading after: it helps against a backup of `storage/` taken without `.env`, which is most backups; against a directory-listing or traversal leak that exposes files but not the application; and against another account on the shared host reading files it should not. It does not help against anybody holding both the files and `APP_KEY`, and on cPanel those sit in the same account, so somebody with a shell has both. **It is a layer, not a safe**, and a passport scan is still something to be careful with.

  **Every payload says which it is**, and that is the property that would otherwise take the site down. Encrypted files begin with a marker deliberately chosen not to be the opening bytes of a PDF, JPEG, PNG or HEIC; reading checks for it and hands back anything without it untouched. So between deploying this and running the backfill — a window in which every file on the server is still plaintext — nothing breaks, and turning the switch off later does not orphan what was written while it was on. `php artisan documents:encrypt` converts the rest at leisure, idempotently, with `--dry-run`; it writes beside each file and renames over it, so an interrupted run leaves the original or the encrypted copy and never half of either. It refuses to run while the switch is off, which would otherwise leave the application writing plaintext beside files it had just encrypted.

  Two deliberate choices. **The checksum and size recorded for a version describe the plaintext**, not the ciphertext: encryption is salted, so the same scan encrypts differently every time, and a ciphertext checksum would make every re-upload look like a new version and break "is this the same document as last time" [R-8]. And **downloading costs streaming** — a file must be whole in memory to be decrypted — which is affordable here only because uploads are capped at `documents.max_kilobytes` (8 MB); the worst case is roughly 20 MB for one download, and it would not be affordable without that cap.

**Content-Security-Policy is shipped and enforcing.** `script-src` names a per-request nonce and carries no `'unsafe-inline'`, which is the whole protection: injected `<script>` cannot guess the nonce and does not run. `object-src 'none'`, `base-uri`, `form-action` and `frame-ancestors` close the usual ways around it. Two concessions stand, both recorded in the middleware and asserted by tests so neither can quietly widen. `'unsafe-eval'` remains because Alpine's standard build compiles every `x-data` expression with `new Function()`; it does not re-open what the nonce closes, since reaching `eval` needs code that is already running, and removing it means moving to `@alpinejs/csp` — its own piece of work, as every inline expression becomes a registered component. `style-src` keeps `'unsafe-inline'` because colours chosen in Admin → Settings are written into inline `style` attributes, which only `style-src-attr` could cover and that directive is not supported widely enough to rely on; a blocked style attribute would mean a hero banner losing its colours. Style injection is a real but much smaller problem than script injection, and this is the honest trade rather than a green tick. `SECURITY_CSP_REPORT_ONLY=true` switches to report-only when adding a new embed; `SECURITY_CSP=false` is the emergency stop.

Personal data: passports, photos, medical notes and payment records for minors and adults.

**Honouring a deletion request — done.** `php artisan data:forget <customer>` erases one person and keeps the books. "Delete my data" and "keep your books" are both obligations pointing in opposite directions, so the command is drawn along the line between them: bookings and payments survive with their references, amounts, dates and statuses, so the financial history is unchanged and still adds up, while everything saying *who* goes — name, contacts, national ID, passport number **and the scan of it**, medical notes, emergency contact, the free text staff typed, and every portal link that would open the booking. The files are deleted from disk, not merely dereferenced: nulling a path and leaving the file is the failure that looks exactly like success, because the application cannot reach it and the next backup still carries it.

  Two refusals, both fail-safe. **It refuses while a booking is not finished with** — you cannot forget somebody you are about to fly, and a traveller with no name cannot be checked in or issued a permit. And **it refuses while the schema has moved**: `App\Support\Forgetting` classifies every table that `Anonymisation::SCRUB` says holds personal data, either naming how one person's rows are found in it or recording why it is not about one customer, and an unclassified table stops the run. A deletion request reported as honoured while a table nobody thought of still holds the person is the worst outcome available here, so it fails in a test rather than in front of the person who asked.

  The audit row it writes carries the customer id and nothing else. An auditor needs to know a request was honoured and when; recording *whose* would undo the forgetting.

**Defining the policy — reported on, deliberately not decided.** `php artisan data:retention` says what personal data is held and how old it is, in age brackets, per category. That is the question that has to come first and nobody can answer it today. `config/retention.php` ships with **every period null**, and that is not an oversight: how long to keep a pilgrim's passport number depends on Maldivian tax and company record rules, on what the Ministry expects of a licensed operator, and on what Rihla is willing to promise a customer — none of which has been stated. A default invented by a developer becomes the policy by accident, and the first anybody hears of it is when the data is gone. Set a number there and the same report says how much is past it. **Erasing stays deliberate and one person at a time**: a sweep that deletes by age deletes somebody mid-dispute as readily as somebody long gone.

**`data:anonymise` was broken and nobody had run it.** Found while writing the above. `document_versions.path` is `NOT NULL` and the scrubber mapped it to `null`, so the command threw an integrity-constraint violation on **any database holding a single document** — which is every real one. The suite stayed green because no test had ever stored a document, so the one command whose whole job is keeping real passport numbers off a public server would have failed the first time it was run in earnest, in Phase 7, months after it shipped. Fixed with a `gone` stand-in, and `AnonymiseTest` gained two tests: one asserting against the live schema that every column mapped to `null` is one the schema will accept null in, and one that simply stores a document and runs the command.

**Added in v1.1:**
- **Staging never holds unmasked production data — now enforced.** `test.rihla.mv` auto-deploys from `main` and is reachable on the public internet. This asked for the anonymising export *before Phase 3 shipped*; it was written in Phase 7, which is late, and the gap between those two dates is exactly the window in which "just copy prod to test" would have put real passport numbers on a public host.

  `php artisan data:anonymise` scrubs names, contacts, national IDs, passport numbers, medical notes and free text; regenerates portal tokens so a link sent for a real booking cannot open a scrubbed one; empties the audit log, sessions and queues; and keeps packages, departures and content so the test server is still worth testing on. The procedure is [`RESTORING_PRODUCTION_DATA_TO_TEST.md`](RESTORING_PRODUCTION_DATA_TO_TEST.md).

  Two guards, both of which fail safe. **It refuses outright when `APP_ENV` is production**, with no override flag — a destructive command with an escape hatch is one that will eventually be run with the escape hatch. And **it refuses when the schema has moved**: `App\Support\Anonymisation` classifies every table, the command compares that against the live schema before touching anything, and an unclassified table stops the run. A scrubber built from tables somebody remembered is one that misses the table added last Tuesday, in the direction where real data survives. `AnonymiseTest` asserts the same thing in CI, so a new table fails a test rather than a pilgrim's privacy — and it caught two on its very first run.
- **Backup before every production migration.** `pull-deploy-test.sh` runs `migrate --force` automatically, which is fine for test. The production promotion procedure must take a database snapshot first, and every migration in the booking domain must be either reversible or explicitly forward-fix-only in its ADR.
- **Email authentication.** Booking confirmations and payment receipts that land in spam are a support cost and a trust cost. Set SPF, DKIM and DMARC for `rihla.mv` before the first transactional email is sent from the platform, and send through a dedicated transactional provider, not the cPanel mail server.
- **Uptime monitoring on production — [R-9] still open.** The deploy workflow smoke-tests `test.rihla.mv` only. Point an external monitor (BetterStack / UptimeRobot free tier) at `https://rihla.mv/up` — the health route already exists in `bootstrap/app.php` — with WhatsApp/email alerts. **This is the one piece of observability Pulse cannot supply**, because a dashboard served by the site cannot tell you the site is down, and it needs an account only the owner can open (ADR 0005).
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

**Shipped (4.8): notices — the list, not the messaging.** There is still no WhatsApp Business API, no SMTP and no SMS provider, and this plan already records (§6.4) that the codebase will not pretend otherwise. So a notice is **a row saying somebody needs to know something**, which does three honest things today: it appears on the Pilgrim Portal, which needs no credentials; it gives staff a chasing list instead of a spreadsheet nobody updates; and it carries a **prepared WhatsApp link**, so the conversation staff were going to have anyway takes one tap. The same shape as the waitlist claim link, for the same reason.

**It observes; it never invents.** Every notice is raised from a record that already exists — a missing passport, an approaching departure. There is no `notice.create` permission and none is ever added: the moment somebody can type one by hand, the portal starts carrying claims nothing backs.

**`seen_at` and `handled_at` are different facts.** The customer opening their portal is not staff having chased them. Conflating them would let a passport request vanish from the queue because somebody loaded a page.

**Chasing somebody is not the same as them sending the passport**, so a handled notice leaves the staff queue while the portal keeps asking until the cause is actually gone.

**The portal raises its own on read.** Nobody has confirmed cron runs on this cPanel account, and unlike a lapsed seat hold — which the next booking reclaims inside its own row lock — a notice that is never raised simply does not exist. So the page that needs it computes it, idempotently. `notices:sweep` exists for the customers who do not visit.

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
| Monitoring | Laravel Pulse (in place, ADR 0005) + Sentry (needs an account) |
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
| ~~**P0 — Stabilise**~~ ✅ **done** (`d9f60c9`) | The live site stops embarrassing itself | §3: translations, demo content, CI (incl. MySQL job **[R-2]**), cleanups, locale-prefixed routing **[R-1]**, SEO essentials, PWA wiring | **4–7 days** |
| ~~**1 — Foundations**~~ ✅ **done** | Ready to build on | ~~i18n redesign (§9.4)~~ **— done: trips, the Umrah guide, the homepage blocks and media all translatable (ADR 0001)**, ~~roles/permissions + policies (§9.3)~~ **— staff side done (`456abd6`); customer-side relationships wait for bookings (Phase 3)**, ~~audit-log foundation~~ **— done (`2e81a34`)**, ~~Filament adoption (§9.2)~~ **— adopted; panel at `/staff` (ADR 0003)**, ~~design-system pass~~ **— colour (§4.5), typography (`f30291b`) and the section rhythm (§4.7) all done**, ~~hosting decision + move (§9.1)~~ **— decided: stay on cPanel (ADR 0002)**, ~~media library~~ **— narrowed: the swap to spatie/laravel-medialibrary waits for a second media owner (ADR 0004); translations and the broken thumbnails are done**, ~~observability~~ **— Pulse at `/pulse` and log rotation done (ADR 0005); Sentry and uptime monitoring need the owner's accounts** | **3.5–5.5 weeks** |
| ~~**2 — Public website**~~ ✅ **done** | A site that sells | ~~IA + homepage rebuild (§4.2)~~ **— seven of twelve sections; five need content that does not exist**, ~~package/departure model (§5.1)~~ **— the product side; bookings are Phase 3**, ~~comparison~~, ~~hotel distance~~, ~~itinerary~~, ~~seat bars~~, ~~countdowns~~, ~~leader/scholar profiles~~, trust dashboard **— licence is published; Ministry approval and review counts are facts/features that do not exist**, ~~WhatsApp CTA~~, ~~cost calculator~~ **— totals only; instalment terms are the owner's to state**, ~~blog~~, ~~full SEO~~ | **6–8 weeks** |
| ~~**3 — Booking & payments**~~ ✅ **done, apart from what needs the owner** | Money online, spreadsheets retired | ~~booking domain + capacity invariant (§5.1, §5.2)~~, ~~public booking flow (§5.2)~~ **— stops at the seat hold: taking payment needs BML**, ~~staff booking admin~~, ~~waiting list with auto-promotion~~, ~~document wallet with versioning (§5.5) [R-8]~~, ~~visa applications (§5.4a)~~ and ~~Nusuk permits (§5.4b) + computed travel readiness~~ **as two separate deliverables [R-4]**, ~~payments abstraction + bank transfer and cash (§5.3)~~, ~~Pilgrim Portal v1 (§6.1)~~, ~~invoices and receipts (§5.3)~~, ~~import of historical customers with duplicate detection~~, ~~minimal CRM (§8.1)~~. **Not done, and not code:** BML Connect (§5.3) needs merchant onboarding; instalments need the operator's terms; the bank account, the GST position and the terms & conditions text are all one config line each once stated | **8–10 weeks** |
| ~~**4 — Operations & portals**~~ ✅ **done, apart from what needs an account nobody has opened** | The journey runs on the platform | Journey planning & capacity (§8.2) — ~~rooming with conflict detection~~ and ~~the departure board~~ **both done; the allocator and the single readiness score deliberately not built, see §8.2**, operations (§8.3) — ~~incidents~~, ~~attendance~~ and ~~the daily log~~ **done**, checklists and flights to come, Tour Leader Portal (§6.3) — ~~roster, manifests, head count and the offline queue~~ **done**, ~~Family Portal (§6.2)~~ **done, with the pilgrim-owned privacy controls**, ~~safety & emergency (§6.5)~~ **the minimum done: contacts as a gate, and a broadcast honest about its reach**, ~~notifications~~ **done as notices: the chasing list and the portal**. **Not done, and not code:** WhatsApp Business needs an account and a cost model (§11.2); email needs SMTP; SMS needs a provider decision. All three are seams that refuse loudly and say what is missing, so each is a configuration change rather than a rewrite | **8–10 weeks** |
| ~~**5 — Knowledge & learning**~~ ✅ **done, apart from the content and the rates nobody has supplied** | The differentiator ships | ~~Knowledge Centre (§7.1)~~ **— the editorial standard built as the state machine; no article ships, because the named reviewer does not exist yet**, ~~Ziyarah Guide with offline (§7.2)~~ **— the same gate, misconceptions as a first-class table, and a save-the-whole-guide control proved against a stopped server; no location ships, for the same reason**, ~~Learning Academy (§7.3)~~ **— the plan is arithmetic on the departure date, the quiz teaches rather than marks, and no module ships for the same reason as the other two**, ~~Scholar Portal (§6.4)~~ **— one queue across all four things waiting, and Ask a Scholar with consent that only the asker can give**, readiness score **— deliberately not a single score; named concerns instead, see §8.2**, ~~full CRM (§8.1)~~ **— quotations that are superseded rather than edited, follow-up tasks, a customer 360 that gathers without judging, and re-engagement as a list rather than a campaign**, ~~finance (§8.4)~~ **— per-journey profitability that distinguishes per-person from fixed costs and refuses to invent an exchange rate** | **10–12 weeks** |
| ~~**6 — Intelligence**~~ ✅ **done, apart from the key and the scholar nobody has supplied** | Decisions from data | ~~BI dashboard (§8.5)~~ **— nine of §10.5's thirteen KPIs computed, and the other four named as absences rather than proxied; "nothing to measure" is a state of its own, distinct from zero**, ~~forecasting~~ **— seat projection from booking pace, as a range and never a point, that declines below three comparable journeys rather than extrapolate**, ~~smart alerts~~ **— only the conditions no other screen watches, each naming who acts and where, and each shown only to somebody who can act on it**, ~~pilgrim AI assistant (§9.6)~~ **— built, switched off, and structurally incapable of answering a religious question no named scholar has approved; the governance page is ADR 0006, and the public Knowledge Centre reader's pages were built with it so a citation can be checked**, ~~staff drafting assistant~~ **— drafts, and declines to translate into Dhivehi rather than repeat what machine-generated Dhivehi has already cost this site**, ~~personalisation~~ **— one honest line for a signed-in returning pilgrim; no cookie, no profile, and no recommendations**. **Not done, and not code:** an Anthropic API key and a cost model, and the named scholar without whom a key changes nothing | **6–8 weeks** |
| **7 — Expansion** | New revenue | ~~loyalty & referrals — the referral half~~ **done: who sends Rihla people, what it came to, and whether anybody has written a follow-up down since; it does not claim to know who was thanked**. Hajj, partner/B2B portal, loyalty rewards, Arabic locale, native app shells, marketplace — **each blocked on a business fact or an account nobody has supplied; see below** | **open-ended** |

### What the rest of Phase 7 is waiting on

Phase 7 is "new revenue", and every item left in it turns on a commercial or
regulatory fact this application cannot supply itself. Written down so the
next person does not have to guess, and so none of it gets built speculatively.

| Item | Blocked on | Why it cannot be assumed |
|---|---|---|
| **Hajj** | A Ministry of Islamic Affairs Hajj quota and licence | Hajj is a separately licensed product with an allocated quota, not a longer Umrah. §2 records that the Ministry fines non-compliant operators up to MVR 30,000, with licence suspension and police referral — and far more for operating unlicensed. Advertising a Hajj package Rihla is not licensed to sell is the one mistake on this list that is a legal problem rather than a product one. |
| **Partner / B2B agent portal** | Commission rates, credit terms, and whether agents book against Rihla's allocation or their own | The commercial model *is* the data model here. Build it on a guessed commission structure and the schema is wrong, not just the numbers. |
| **Loyalty rewards** | What a point is worth, what earns one, and whether it discounts a journey or buys something else | The recognition half is built (above). The reward half is a pricing decision, and a loyalty scheme whose value is invented is a liability the operator has to honour. |
| **Arabic locale** | Somebody who can write and check Arabic | The machinery is done — §9.4 is complete and adding `ar` is configuration. What is missing is the content, and `AGENTS.md` records exactly what happened the last time this codebase shipped a language nobody in the office could read. An `/ar` that is entirely English fallback is worse than no `/ar`. |
| **Native app shells** | An Apple Developer account and a Google Play account | Neither can be opened by anybody but the owner. The PWA already installs to a home screen and works offline (§7.2), which is most of what a shell would add. |
| **Marketplace / multi-tenancy** | Nothing — it is deliberately deferred | §11.24 already records this: revisit if Rihla franchises or white-labels. It describes an organisation with an architecture function. |

**None of these is a small amount of code once the fact exists.** They are
listed as blocked rather than as remaining work so that the absence is a
decision somebody made, not a gap that looks like neglect.

**MVP definition (if the timeline must compress):** P0 + Phase 2 + the booking half of Phase 3 (booking flow, deposit payment, document upload, permit tracker, pilgrim dashboard). That is a sellable platform in roughly four months and it is where the compounding starts.

**Release discipline throughout:** every phase ends with CI green, a test for each new business rule, an ADR for each significant decision, and a deploy to test.rihla.mv verified before production. Production stays manually promoted — do not extend the auto-deploy webhook to rihla.mv.

---

## 13. Decisions needed from you

These block or reshape the plan; everything else I can proceed on with stated assumptions.

1. ~~**Hosting (§9.1)**~~ **answered: stay on cPanel** (ADR 0002). The compromises it names — no queue worker, no Redis, no websockets — land in Phase 3.
2. ~~**Admin (§9.2)**~~ **answered: Filament, new modules first** (ADR 0003). Panel at `/staff`; the Blade admin keeps `/admin` until Phase 3.
3. **Scope ambition** — the MVP-in-four-months path, or the full Phase 1–6 programme (~10–12 months at one developer)?
4. **Payments** — is BML merchant onboarding already in progress? It gates Phase 3 and has the longest external lead time.
5. **Nusuk (§2.3, §5.4b)** — **[R-6]** is Rihla an approved Nusuk-integrated operator, or does it work through a licensed intermediary, and who owns permit issuance operationally? This decides whether 5.4b is a staff workflow with forms or a system integration, and it materially changes the Phase 3 estimate. Currently modelled as a manual staff workflow with an optional API later.
6. **Content ownership** — who writes and who *religiously reviews* the Knowledge Centre and Academy? Phase 5 is content-bound, not code-bound.
7. **[R-5] Production runtime versions** — which MySQL/MariaDB version does the cPanel account run, and is PHP really 8.4 (the deploy scripts reference `ea-php84`)? The DB version matters because The capacity invariant `capacity_held + capacity_confirmed <= capacity_total` is enforced with a CHECK constraint, which needs MySQL 8.0.16+ or MariaDB 10.2+. On an older engine the row lock becomes the sole defence and that must be recorded deliberately. Verify before the booking tables are created.
8. ~~**[R-1] Locale in the URL (P0.7)**~~ **answered and shipped** (`f4c41cc`): approve moving locale into the route. Without it the P0.5 SEO work ships tags that do nothing.
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
| Auto-deploy pushes a broken `main` to test | Medium | ~~CI required before merge (P0.3) — currently the gap that lets this happen~~ **Closed (v1.33).** The deploy is triggered by CI concluding successfully on `main`, not by the push, so it no longer races the test suite. A red run deploys nothing and annotates itself. The cron fallback still pulls whatever `main` is — it is a fallback for a failed webhook, not a second gate |
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

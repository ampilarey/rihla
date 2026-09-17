# Rihla Platform — Website Upgrade Plan

**Version:** 1.0
**Date:** 2026-09-17
**Status:** Proposed — awaiting prioritisation decisions (see §13)
**Owner:** Rihla Travels (Reg. No. C11452023)
**Scope:** rihla.mv (production) and test.rihla.mv (staging)

---

## How to read this document

This plan has three sources:

1. **The ChatGPT "Umrah Website Upgrade Ideas" thread** — 214 messages, 107 specification documents (`00-VISION` → `57-ENTERPRISE-REFERENCE-ARCHITECTURE`, appendices `A01`–`A33`, plus `META01`). Every one of those documents is accounted for in this plan; Appendix A maps each to a section here or records why it is deferred.
2. **An audit of the current codebase and the live sites** performed on 2026-09-17 (§1). Findings there are verified, not assumed.
3. **External research** into the 2026 Umrah market, Saudi regulatory reality, Maldivian payment/regulatory context, and Laravel tooling (§11, Appendix B).

The source thread is an *enterprise architecture manual*. It is excellent as a reference library and unusable as a build plan: it describes a 22-module platform with ~50 enterprise standards documents for what is today a 6-table brochure site run by a small Maldivian agency. **This plan keeps all of its ideas but re-sequences them by business value and cost**, and adds the things it missed — most importantly Nusuk compliance, which as of 2026 is not optional.

Sections §2–§10 are the plan. §12 is the phased roadmap with effort. If you read only one section, read §1 (what is broken now) and §12 (the sequence).

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
| D1 | **Critical** | The production homepage renders raw translation keys to visitors: `hero_title`, `hero_sub`, `cta_trips`, `cta_whatsapp`, `section_upcoming`, `section_memories`, `memories_sub`, `join_next_title`, `contact_whatsapp` | Live HTML of `rihla.mv` | Translations live in `resources/lang/{en,dv}/messages.php`, but Laravel 9+ (so Laravel 13) reads the **root** `lang/` directory, and no `useLangPath()` override exists. Separately, all 21 calls are dotless — `__('hero_title')` — which is JSON-file syntax; the PHP array files would need `__('messages.hero_title')`. Two independent bugs, either alone breaks it. |
| D2 | **High** | Production is advertising demo seed data — "Maldives Island Hopping Adventure", "Luxury Resort Experience" — instead of Umrah packages, on a site whose `<title>` is "Islamic Travel & Umrah Services" | Live HTML | `TripSeeder` demo rows were seeded to production and never replaced |
| D3 | **High** | No structured data (`ld+json`) anywhere; no `sitemap.xml`; no `hreflang` tags despite two locales | `grep` across `resources/views`, `routes/`, `public/` | Never implemented |
| D4 | **Medium** | PWA is half-wired: `public/sw.js`, `public/manifest.json` and `public/offline.html` exist, but the service worker is registered **only** on `/guide` and the manifest is not linked from `layouts/app.blade.php` | `resources/views/pages/guide.blade.php:78` | Partial implementation |
| D5 | **Medium** | `package.json` carries both `tailwindcss ^3.1.0` and `@tailwindcss/vite ^4.0.0` | `package.json` | Half-finished Tailwind v4 migration; a fragile build |
| D6 | **Medium** | No CI. Nothing runs tests, Pint or Larastan before code reaches `main` — and `main` auto-deploys to test | `.github/workflows/` contains only `deploy-test-immediate.yml` | Never set up |
| D7 | **Medium** | Known-failing tests are documented as acceptable in `AGENTS.md` (Breeze `Auth`/`Profile` tests reference removed routes; `ExampleTest` lacks `RefreshDatabase`) | `AGENTS.md` | Drift after customisation |
| D8 | **Low** | Debug scaffolding is routed in production: `admin/media/{medium}/debug` and `admin/test-video` | `routes/web.php` | Leftovers |
| D9 | **Low** | `intervention/image ^2.7` is a major version behind (v3) | `composer.json` | Deferred upgrade |
| D10 | **Low** | `tailwind copy.config.js` is committed at the repo root | repo root | Stray file |

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

Nothing in §4–§9 should start before this section is done. Estimated total: **3–5 developer-days.**

### P0.1 — Fix the broken translations (D1)

Two-part fix, both parts required:

1. **Move the files.** `resources/lang/{en,dv}/` → `lang/{en,dv}/`. (Alternatively call `->useLangPath(resource_path('lang'))` in `bootstrap/app.php`, but moving matches Laravel 13 convention and is less surprising.)
2. **Fix the call sites.** Convert all 21 dotless calls to namespaced keys: `__('hero_title')` → `__('messages.hero_title')`. Do it as one mechanical pass over `resources/views/`.

**Acceptance:** `curl -s https://test.rihla.mv/ | grep -c 'hero_title'` returns 0; the Dhivehi homepage renders Thaana for every one of the 21 keys; a feature test asserts the homepage contains the translated string, not the key, in both locales. **Add that test** — this bug reached production precisely because nothing asserted it.

### P0.2 — Replace demo content (D2)

Remove the resort/island-hopping seed rows from production. Publish real Umrah packages, or if none are ready, an honest "next departures announced soon" state. Guard the seeder so `TripSeeder` demo data cannot run in production (`if (app()->isProduction()) return;`).

**Acceptance:** no non-Umrah package appears on rihla.mv; seeders are environment-guarded.

### P0.3 — Stand up CI (D6, D7)

Add `.github/workflows/ci.yml` running on pull requests and pushes to `main`: `composer install`, `npm ci && npm run build`, `php artisan test`, `composer lint:php`, `composer analyse`.

Because `main` auto-deploys to test, CI must be **required to pass before merge**. Fix or delete the known-failing Breeze tests (D7) in the same PR so the suite is green and stays meaningful — a permanently red suite trains everyone to ignore it.

**Acceptance:** CI green on `main`; branch protection requires it; `AGENTS.md`'s "pre-existing test failures" paragraph is deleted because it is no longer true.

### P0.4 — Clean up (D8, D10, D5)

- Delete the `admin/media/{medium}/debug` route and `admin/test-video` route + view.
- Delete `tailwind copy.config.js`.
- Resolve the Tailwind version conflict: commit to v4 (remove `tailwindcss ^3`, migrate `tailwind.config.js` to the v4 CSS-first config) **or** to v3 (remove `@tailwindcss/vite`). Do not ship both. Recommendation: **v4**, since the Vite plugin is already in place and v4 builds faster; budget a day for the theme migration and a visual diff pass over the Dhivehi/RTL pages.

### P0.5 — SEO essentials (D3)

- `sitemap.xml` (route-generated, cached): home, trips index, each published trip, guide, gallery, contact — both locales.
- `hreflang` alternates for `en` / `dv` in `layouts/app.blade.php`, plus `x-default`.
- JSON-LD in the layout: `Organization` (with `identifier` = Reg. No. C11452023, licence, contact), `BreadcrumbList` on content pages, `FAQPage` on `/guide`, and `Product` + `Offer` + `AggregateRating` on each trip page (`TouristTrip` in addition — no rich result today, but AI search surfaces read it).

**Acceptance:** Rich Results Test passes for a trip page; Search Console shows both locales; dates emitted as ISO 8601.

### P0.6 — Finish the PWA wiring (D4)

Link `manifest.json` from the main layout, register the service worker site-wide (not only `/guide`), and define an explicit offline strategy: app shell + guide + Ziyarah content cached, everything else network-first with the existing `offline.html` fallback.

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

---

## 5. Workstream B — Booking engine & payments

*Sources: `04-PACKAGES-BOOKING`, `16-FINANCE-ACCOUNTING-PAYMENTS`, `25-DOCUMENT-MANAGEMENT-DIGITAL-WALLET`.*

This is where the business value is. Today every booking is a WhatsApp conversation and a spreadsheet.

### 5.1 Domain model

Replace the single `trips` table with a proper package/departure split (full schema in Appendix C):

- `packages` — the sellable product (title, type, inclusions, exclusions, itinerary template, hotel set, difficulty/accessibility)
- `departures` — a dated instance of a package (dates, airline, capacity, seats sold, price tiers, tour leader, scholar, status)
- `bookings` — one per party, with a reference (`RIH-2026-0001`)
- `travellers` — one per person, with passport, Nusuk linkage, room assignment, meal/medical notes
- `payments`, `payment_plans`, `invoices`, `refunds`
- `documents` — passport, photo, vaccination, visa, permit; with verification state and expiry

**Migration path:** keep `trips` as a view/alias through Phase 2 so the existing public pages keep working while the new model is populated.

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

### 5.4 Visa & Nusuk permit tracking

This replaces the thread's simpler "visa tracker":

```
Documents collected → Nusuk account linked → Accommodation & transport recorded (Nusuk-compliant)
 → Visa applied → Visa issued → Umrah permit issued → Rawdah slot booked → Ready to travel
```

Each stage: owner, timestamp, evidence document, pilgrim-visible status, and an SLA alert to operations when it stalls. **Gate:** a departure cannot be marked "ready" while any traveller lacks a permit.

### 5.5 Digital document wallet

Per-traveller secure storage with categories (travel, identity, financial, learning, medical-optional), verification workflow, expiry alerts (passport < 6 months validity is a blocker — check it automatically), QR codes for check-in, offline access on mobile, and a full audit trail. Apple/Google Wallet passes for the boarding-style journey card are a nice Phase 5 addition.

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

**Recommendation: Filament v4.** MIT-licensed, actively maintained, the default choice for Laravel admin in 2026, and it collapses standard CRUD from days to hours. Cost: the team must learn Livewire. Migrate incrementally — new modules (bookings, travellers, payments, journeys) in Filament first, existing trip/media/settings CRUD ported afterwards.

### 9.3 Authorisation

Replace the `is_admin` boolean with real roles and permissions (`spatie/laravel-permission`): pilgrim, family member, tour leader, scholar, agent, operations, finance, marketing, admin, super-admin. The portals in §6 each imply a distinct permission set; a boolean cannot express them.

### 9.4 Internationalisation — redesign now, cheaply

The current `*_dv` column pattern does not survive contact with packages, itineraries, learning modules, Ziyarah locations and articles. Move to a **translations table** (`spatie/laravel-translatable` with JSON columns is the lighter option and works well on MySQL 8). Support en / dv / ar with full RTL, per-locale slugs and per-locale SEO metadata. Doing this in Phase 1, while there are eight models, costs days; doing it in Phase 5 costs weeks.

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

### 10.3 Testing

| Level | Target |
|---|---|
| Unit | Business rules: pricing, instalments, seat holds, readiness score |
| Feature | Every public route in both locales; full booking flow; payment webhook handling; portal authorisation (each role sees only its own data) |
| Regression | The homepage-translation assertion from P0.1 — permanently |
| Browser (later) | Booking flow and portal on mobile viewport |
| Coverage | No number worth policing; instead require that **every bug fixed gets a test** |

### 10.4 Security

Roles/permissions (§9.3); MFA for staff; session security and device management; encryption at rest for passport/identity documents; signed, expiring URLs for document access (never public storage paths); rate limiting on auth, booking and payment endpoints; CSRF on all forms (Laravel default — verify on the new AJAX paths); audit log for every admin action on bookings, payments and documents; dependency scanning in CI; secrets only in `.env` (the deploy webhook secret pattern already in place is the right model); documented backup **and tested restore**.

Personal data: passports, photos, medical notes and payment records for minors and adults. Define retention and deletion policy, and honour deletion requests.

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

| Need | Package |
|---|---|
| Admin panel | `filament/filament` v4 |
| Roles & permissions | `spatie/laravel-permission` |
| Translations | `spatie/laravel-translatable` |
| Media conversions | `spatie/laravel-medialibrary` (replaces the hand-rolled `Media` model; also fixes D9) |
| Sitemap | `spatie/laravel-sitemap` |
| Backups | `spatie/laravel-backup` |
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
| **P0 — Stabilise** | The live site stops embarrassing itself | §3: translations, demo content, CI, cleanups, SEO essentials, PWA wiring | **3–5 days** |
| **1 — Foundations** | Ready to build on | i18n redesign (§9.4), roles/permissions (§9.3), Filament adoption (§9.2), design-system pass, hosting decision + move (§9.1), media library, observability | **4–6 weeks** |
| **2 — Public website** | A site that sells | IA + homepage rebuild (§4.2), package/departure model (§5.1), comparison, hotel distance explorer, itinerary, seat bars, countdowns, leader/scholar profiles, trust dashboard, WhatsApp CTA, cost calculator, blog, full SEO | **6–8 weeks** |
| **3 — Booking & payments** | Money online, spreadsheets retired | Booking flow (§5.2), BML Connect (§5.3), instalments, invoices, document wallet (§5.5), **visa & Nusuk permit tracker** (§5.4), minimal CRM (§8.1), Pilgrim Portal v1 (§6.1) | **8–10 weeks** |
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
5. **Nusuk (§2.3)** — is Rihla integrating with / operating through Nusuk today, and who owns permit issuance operationally? This shapes §5.4 substantially.
6. **Content ownership** — who writes and who *religiously reviews* the Knowledge Centre and Academy? Phase 5 is content-bound, not code-bound.

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
| `35-MASTER-DATA-MANAGEMENT-DOMAIN-MODEL` | Appendix C |
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

Core tables for Phases 2–4. Conventions follow A01/A02 (snake_case, plural tables, `id` PK, public ULID where an identifier is exposed).

```
packages            id, slug, type, title*, summary*, details*, inclusions*, exclusions*,
                    difficulty, accessibility_notes*, is_published
departures          id, package_id, code, date_start, date_end, airline, route,
                    capacity, seats_held, seats_sold, status, tour_leader_id, scholar_id
price_tiers         id, departure_id, room_type(quad|triple|double|single), price_mvr, price_usd
hotels              id, city(makkah|madinah), name*, stars, lat, lng,
                    distance_to_haram_m, walk_minutes, gallery
departure_hotels    departure_id, hotel_id, city, nights, room_block
itinerary_items     id, departure_id, day_no, time, title*, description*, location_id
bookings            id, reference, departure_id, payer_user_id, status,
                    total_mvr, paid_mvr, balance_mvr, source, agent_id
travellers          id, booking_id, user_id?, full_name, passport_no, passport_expiry,
                    dob, gender, mahram_traveller_id?, room_assignment, meal_notes, medical_notes
documents           id, traveller_id, type, file_path, status(pending|verified|rejected),
                    expires_at, verified_by, verified_at
permits             id, traveller_id, nusuk_linked_at, visa_status, visa_issued_at,
                    umrah_permit_at, rawdah_slot_at, notes
payments            id, booking_id, method, gateway_ref, amount, currency, status, paid_at
payment_plans       id, booking_id, due_date, amount, status, reminder_sent_at
invoices            id, booking_id, number, issued_at, pdf_path
leads               id, name, contact, source, package_interest, status, owner_id, next_action_at
locations           id, slug, city, name*, lat, lng, category, significance*, references*
articles            id, slug, type(history|learning|blog), title*, body*, reviewed_by, reviewed_at
lessons             id, path_id, order, title*, body*, media, quiz_id
progress            id, user_id, lesson_id, completed_at, score
incidents           id, departure_id, traveller_id?, severity, category, status, reported_by
```

`*` = translatable field (JSON per §9.4).

**Key constraints:** unique `(departure_id, traveller passport_no)`; `seats_sold + seats_held <= capacity` enforced at the database level; documents and permits cascade-audit rather than hard-delete.

---

*Prepared 2026-09-17 from the "Umrah Website Upgrade Ideas" thread (214 messages / 107 documents), an audit of `ampilarey/rihla@da9b36d`, live inspection of rihla.mv and test.rihla.mv, and 2026 market research. Section §1.2 findings are verified against the running site; effort estimates in §12 are assumptions and should be re-based once §13 is answered.*

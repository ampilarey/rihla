# Rihla — Project Overview

*Rewritten November 2026, after Transport and the travel agency were built.
The earlier version described a brochure site on Laravel 11; that site is
long gone. For anything deeper than this page, follow the links.*

## What Rihla is

One Laravel application that runs **five businesses** for a Maldivian travel
company, each a module under `modules/` (see [MODULES_PLAN.md](MODULES_PLAN.md)):

| Part | Module | What it does | Plan |
|---|---|---|---|
| **Rihla's own Umrah** | `Umrah`, `Content` | Packages and departures, online booking with seat holds, travellers and their documents (encrypted, read by OCR or Claude and accepted by a person), visas and Nusuk permits, rooming, buses, cards and the Scan page, lists and manifests, the departure lifecycle, the books, the family portal, the pilgrim's guide and learning academy | [WEBSITE_UPGRADE_PLAN.md](WEBSITE_UPGRADE_PLAN.md) |
| **Other Umrah companies** | `Companies` | Each company has its own panel (`/company/{slug}`), its own website at a subdomain or its own domain, its own Telegram bot, the features it pays for, and monthly billing by Rihla | [UMRAH_COMPANIES_PLAN.md](UMRAH_COMPANIES_PLAN.md) |
| **Stays** | `Stays` | Guesthouses, island holidays and rooms in Malé, sold for hosts who run their own panel (`/host`), with commission and statements | §15–16 of the upgrade plan, ADR 0008 |
| **Boats** | `Transport` | Scheduled sailings sold online with QR tickets, boarding by scan, manifests, cargo and charter quotes, and outside operators with their own panel (`/operator`), commission and statements | [TRANSPORT_PLAN.md](TRANSPORT_PLAN.md) |
| **Travel agency** | `Travel` | "Plan my trip" for flights, hotels and visas abroad: quoted options the customer chooses online, issued tickets on their trip page, and a visa desk for other countries. Live supplier booking (A4) waits for a supplier account | [TRAVEL_AGENCY_PLAN.md](TRAVEL_AGENCY_PLAN.md) |

`app/` is the shared core: users and roles, partners (companies, hosts,
operators), customers, the payment ledger, notices, media, settings, the
audit trail, and the company-isolation machinery. `modules/Telegram` is the
staff bot that every part reports through.

## Who uses it

- **Customers**, on the public site in English, Dhivehi and Arabic (Dhivehi
  and Arabic fall back to English where no trustworthy translation exists).
  Every public part can be switched off, "coming soon" or on from Settings.
- **Rihla's staff**, in the Filament panel at `/staff`, each by role
  (Operations Manager, Booking Staff, Finance, Visa Staff, Pilgrim Support,
  Content Manager, Tour Leader, Reporting, Scholar and Super Admin) —
  permissions in `App\Support\Access`.
- **An Umrah company's staff** at `/company/{slug}`, a **guesthouse's team** at
  `/host/{slug}`, a **boat operator's owner and crew** at `/operator/{slug}` —
  each let in by a membership, never by a Rihla role.

## How money moves

Every payable (an Umrah booking, a stay, an additional trip, a boat booking, a
cargo request, a trip) takes payments through one ledger. Bank transfer and
cash are recorded by staff and only count once Finance verifies them; card
payment arrives with a BML merchant account. Three currencies (MVR, USD, SAR)
with one rate table. See [BOOKS.md](BOOKS.md) and [CURRENCIES.md](CURRENCIES.md).

## How customers are told things

A **notice** is raised for each piece of news (a confirmed booking, a document
needed, a sailing cancelled, a price quoted…). Staff chase it from the queue
with a ready-written WhatsApp message, and `notices:email` also e-mails it to
anybody who gave an address, once real SMTP settings are in `.env`.

## Stack and hosting

Laravel 13 on PHP 8.3+, Filament 5 and Livewire, Tailwind, Vite and Alpine,
MySQL in production (SQLite in tests), hosted on cPanel with **no queue
worker**: everything scheduled runs from one cron line (`schedule:run`), see
ADR 0002. Merging to `main` deploys to test.rihla.mv after CI passes;
production is deployed by hand with `scripts/deploy-production.sh`
([PRODUCTION_PROMOTION.md](PRODUCTION_PROMOTION.md)). Working rules and the
traps that cost a cycle each are in `AGENTS.md`.

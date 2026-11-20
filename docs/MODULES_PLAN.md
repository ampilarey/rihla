# Modules: moving the code into one folder per line of business

**Asked for by the owner, November 2026.** The code was in Laravel's standard
layout, sorted by kind of file: 112 models in one folder, every screen in one
tree, one routes file. This plan moves it into **modules**, one per line of
business, each holding its own models, services, screens, commands and tests.

**Nothing a customer, a company or a member of staff sees changes.** Every
step is a move, not a rewrite. Each module is its own pull request, merged
only with the full suite, Larastan and the MySQL job green.

## The modules

| Module | Holds | Status |
|---|---|---|
| **Core** (stays in `app/`) | Users and roles, companies and hosts as records (`Partner`), customers, payments and the ledger, settings, the audit trail, media, notices, the three panels' providers, and the shared support classes | stays where it is |
| **Telegram** | The staff bot: links, link codes, updates, the router, the notifier, the daily summary, the webhook, and its page | ✅ moved (#220) |
| **Companies** | Company invoices, memberships, sites, support grants and visits; adding, billing, exporting and closing a company; the company panel's own pages | ✅ moved |
| **Content** | Trips, articles, the knowledge centre, the Umrah guide, hero banners, the *why* blocks, learning paths and quizzes, ziyarah locations, the assistant | |
| **Stays** | Properties, rooms, units, photos, rates, blocked dates, stays and their guests, charges, messages and reviews, hosts' memberships, pages and statements, calendars, payouts; the host panel | |
| **Umrah** | Packages, price tiers, departures and everything on them, bookings, travellers, seat holds, the waitlist, quotations, documents and reading, visas, Nusuk permits, rooming, buses, flights, roll calls, cards, excursions, incidents, broadcasts, the portal and the family portal, enquiries and the CRM | last, the largest |

Customers and payments stay in Core because Umrah and Stays both use them.
So does what keeps companies apart: `CompanyContext`, the company gate,
`BelongsToCompany`, the company-site middleware and the cross-company test
suites. Every Umrah record in every module depends on them, so they are not
the Companies module's.

## How a module is laid out

```
modules/Telegram/
├── Models/           Modules\Telegram\Models\…
├── Services/
├── Http/Controllers/
├── Console/Commands/
├── Filament/Pages/   (and Resources/, discovered by the panels)
├── TelegramServiceProvider.php
└── Tests/            Modules\Telegram\Tests\…, run by phpunit with the rest
```

`composer.json` maps `Modules\` to `modules/`. `App\Providers\ModulesServiceProvider`
registers each module's provider. Each staff, company or host panel discovers
the module's screens beside its own.

## The three rules that keep a move from breaking anything

1. **A moved model keeps the class name already stored in the database.**
   Four columns hold a model's class name: `payments.payable_type`,
   `notices.noticeable_type`, `audit_logs.auditable_type` and spatie's
   `model_type`. `ModulesServiceProvider::LEGACY_NAMES` maps each moved model's
   old name to its new class through `Relation::morphMap()`. That means:
   - existing rows still resolve;
   - new rows keep the old name, so nothing has to be migrated;
   - a query that compares one of these columns with `X::class` must use
     `(new X)->getMorphClass()` once X has moved.

   `ModulesTest` fails if a moved model is missing from the map.
2. **Cached objects.** A model whose instances are cached is listed in
   `cache.serializable_classes`. The list names the new class, and each
   deploy clears the cache (the deploy script already does).
3. **Factories.** A moved model's factory moves with it. Its `newFactory()`
   names the factory, so Laravel's naming guess is never relied on.

## Order, and why

Smallest and least shared first, so the pattern is proved where a mistake is
cheap: **Telegram → Companies → Content → Stays → Umrah**. Umrah touches most
of the code and goes last, split across several pull requests by area:
packages and departures, then bookings, then documents and visas, then
operations.

## Not moving

- `config/`, `database/migrations/` and `resources/lang/` stay where they are.
  Laravel and the deploy script read them from there, and splitting
  migrations would reorder their history.
- Routes stay in `routes/`, pointing at the moved controllers. Route
  registration order matters here (company host routes must come first, as
  AGENTS.md records), and one file keeps that order visible.

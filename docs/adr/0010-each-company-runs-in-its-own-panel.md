# 0010 — Each company runs in its own panel, on Rihla's own screens

**Status:** Accepted
**Date:** 2026-11-18
**Context:** Phase 2 of [`UMRAH_COMPANIES_PLAN.md`](../UMRAH_COMPANIES_PLAN.md), on top of [ADR 0009](0009-one-database-every-umrah-record-tagged.md)
**Decided by:** the owner chose the outcome (decisions 6, 7, 16 and 17). The mechanism is recorded here.

## Context

Phase 1 tagged every Umrah record with its company and walled each
company off. Phase 2 had to let a company actually work: its own staff,
its own panel, its own paperwork and its own Telegram bot. It had to do
that without Rihla's staff noticing anything, and without making a second
back office that would drift from the first.

## Decision

1. **A third Filament panel, `/company/{slug}`, whose tenant is the
   company's `partners` row.**
   - `partners.is_umrah_company` marks a company. `kind = agency` already
     means a guesthouse business.
   - A person gets in through an accepted `company_memberships` row.
     Rihla's admin adds a company and its owner after checking the
     licence, and the owner adds the rest.
   - `UseTenantCompany` points the Phase 1 company context at the tenant,
     so every query, a Livewire update included, works for that company.
2. **Rihla's own screens, served again, not copied.** The panel registers
   the same resource and page classes as `/staff`
   (`CompanyPanelProvider::RESOURCES` / `PAGES`). They are listed rather
   than discovered, because what is left out matters: Rihla's website
   content, the guesthouse marketplace, its staff accounts, settings,
   audit log, Pulse, and the AI drafting page that spends Rihla's API key.
3. **A company's people hold no spatie role.** Inside the panel, a
   permission is answered by the company gate from `CompanyRole`.
   - Rihla's own role definitions (`Access::matrix()`) are narrowed to
     `UMRAH_AREAS`, plus an Owner who holds all of it.
   - `/staff` stays closed to them, and a Rihla Super Admin is nobody in a
     company's panel.
   - The same rule holds wherever work is done for a company: a print it
     opens, its bot's webhook, a command looping over companies.
     `CompanyRequest::current()` says which company that is.
4. **Plain print and download routes carry the company.**
   - `CompanyRequest::route()` adds `?company={slug}`. Signed links add it
     before signing.
   - `UseRequestedCompany` checks membership and answers 404 otherwise.
     It runs before route model binding, so the record is looked up for
     the company.
5. **Anything said about "the business" goes through `CompanyDetails`.**
   For a company, that is its own name, registration, address, telephone,
   terms, bank account and reference letters. A detail it has not filled
   in comes back empty, never as Rihla's, so a pilgrim is never told to
   call or pay somebody else.
6. **Each company's own Telegram bot.**
   - The token is encrypted on the partner.
   - The company has its own webhook and secret, and `Bot` follows the
     company and never borrows Rihla's.
   - Update ids are unique per company, because they are per bot.

**The release gate** is `CompanyRunsATripTest`. Rihla's admin adds a
company; then, only in the company's panel and each person in their role,
the trip runs end to end:
- the owner fills in the company's details and builds the team;
- a package and departure are made, and booking staff take a booking at
  the desk under the company's reference letters;
- a passport is filed, and Finance checks the payment in;
- the invoice prints for the company;
- operations moves the departure to its return.

At the end, every row is the company's, Rihla's screens have seen none of
it, and nobody in the company holds a Rihla role.

## Consequences

- **A shared screen must never name the staff panel.** Use
  `XResource::getUrl()` / `XPage::getUrl()`, which follow the current
  panel and tenant, and `CompanyRequest::route()` for a plain route.
  Fifteen places had to be found in 2b; a new one would print a link
  that answers a company's staff 403.
- **A new permission area is Rihla-only until it is added to
  `CompanyRole::UMRAH_AREAS`.** That is deliberate: a list that grows by
  default grants by default.
- **`SecurityHeaders` must list every panel path.** Without that,
  Filament's scripts are blocked in a real browser while every PHP test
  stays green.
- **A company's token goes on one site only**, as Rihla's does: Telegram
  holds one webhook per bot.
- **What Phase 2 does not do.** It does not let a company take bookings on
  its own website, open portal links for its customers, take card
  payments, or charge for features. Those are Phases 4 and 5. Until
  Phase 4 adds the company's own address, the panel is at a path on
  Rihla's.

## Alternatives rejected

- **A separate set of company resources.** Each company would have one
  back office, but there would be two to maintain. The first fix made to
  one and not the other is a company running on yesterday's rules.
- **Spatie teams for company roles.** That would turn on a global package
  feature that changes the primary keys of the role tables under Rihla's
  live data, to express something a membership row and one gate already
  express.
- **The company's own subdomain now.** It needs the cPanel wildcard step,
  and nothing in Phase 2 depends on it. Phase 4 adds it as a second
  address for the same panel.

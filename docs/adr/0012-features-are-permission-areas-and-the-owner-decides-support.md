# 0012 — A company's features are permission areas; its Owner decides support access

**Status:** Accepted
**Date:** 2026-11-20
**Context:** Phase 5 of [`UMRAH_COMPANIES_PLAN.md`](../UMRAH_COMPANIES_PLAN.md), on top of [ADR 0010](0010-each-company-runs-in-its-own-panel.md) and [ADR 0011](0011-each-company-site-on-its-own-address.md)
**Decided by:** the owner chose the outcome (decisions 12, 13, 14 and 17). The mechanism is recorded here.

## Context

A company pays a registration fee for its basic website and then for each
further feature, switched on by Rihla's admin (decisions 13 and 14). A
downgrade keeps the data and makes it read-only. The owner must be able
to add a company in minutes, look inside one only with its consent,
suspend it, export everything it holds, and close it.

## Decision

1. **A feature is a set of permission areas** (`CompanyFeatures::FEATURES`).
   The company gate already answered each person from their role at the
   company; it now also asks `CompanyFeatures::allows()`. On passes,
   read-only passes `view`, `viewAny` and `download` only, off passes
   nothing. Because Filament asks the gate for every screen, menu item and
   action, a feature that is off disappears from the panel without a single
   screen knowing features exist. Packages and departures belong to no
   feature: they come with the basic website. The parts outside the panel
   ask by name — the site's checkout and portals (`CurrentSite`), the app's
   files, an own domain, the bot's token, the document reader — and the
   team screen counts staff seats. Rihla's own company has everything.
2. **Stored on the company, never fillable.** `partners.features`
   (`{feature: on|read_only}`, off by absence), the registration fee and
   when it was paid, and `staff_seats`, all written by `EditPartner` with
   `forceFill`. The site's switch refuses to turn on until the fee is
   recorded as paid. Companies that existed before features keep
   everything (a separate data migration, with literal values).
3. **Adding a company records the licence checked** — number, expiry and
   the register it was checked against, since which register is still the
   owner's open question — and seeds an unpublished website.
4. **Suspension and closing reuse one rule.** `isSuspended()` is true for a
   closed company too, so every check that already shut a suspended company
   out (the panel, the site, the bot, its webhook) covers closing without
   change. Neither deletes anything; reactivating restores everything but
   the site's switch.
5. **Support access is the company's to give.** Rihla asks with a reason;
   only a member whose stored role is Owner can allow it, for 1, 4 or 24
   hours, decline it or end it. While it lasts the requester's role at the
   company reads as `Rihla support`: every view permission, nothing else,
   and never the Owner's own pages. Every page they open, refused ones
   included, is recorded where the company sees it. A Rihla Super Admin is
   still nobody in a company's panel otherwise.
6. **The export is every company table, every row of that company's.**
   One CSV per table carrying `company_id`, the audit trail among them, plus
   the document scans and payment slips decrypted. Encrypted identifiers are
   written readable; credentials (link tokens, passwords, chat link codes)
   are not written at all. From `/staff` (downloaded, recorded) or the
   `company:export` command for one too large to download.
7. **Usage is counts, never contents.**

## Consequences

- A new permission area is Rihla-only until it is added to
  `CompanyRole::UMRAH_AREAS`, and free for every company until it is added
  to a feature in `CompanyFeatures::FEATURES` — decide both when adding one.
- A test that builds a company with `Partner::factory()` has no features and
  is refused everything a feature covers; give it the features it uses
  (`AddCompany` without a list gives everything, for older callers).
- MySQL keeps JSON keys in its own order: compare stored features with
  `assertEquals`, never `assertSame`.
- `CompanyOnboardingTest` is the release gate: added in `/staff`, fee
  recorded, site published by the company, first booking taken on its own
  address and shown in its own panel.

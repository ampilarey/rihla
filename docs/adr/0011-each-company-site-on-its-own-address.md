# 0011 — Each company's website on its own address, from the same pages

**Status:** Accepted
**Date:** 2026-11-20
**Context:** Phase 4 of [`UMRAH_COMPANIES_PLAN.md`](../UMRAH_COMPANIES_PLAN.md), on top of [ADR 0009](0009-one-database-every-umrah-record-tagged.md) and [ADR 0010](0010-each-company-runs-in-its-own-panel.md)
**Decided by:** the owner chose the outcome (decisions 2, 4, 5, 8, 9 and 18). The mechanism is recorded here.

## Context

A company's pilgrims needed a site under the company's name: its trips,
an enquiry form, booking, the customer and family portals, and an app to
install. Nothing of Rihla's could appear there, and nothing of the company
could appear on rihla.mv. And it had to be done without a second copy of
the public pages, which would drift from the first.

## Decision

1. **The address decides.** `ServeCompanySite` is global middleware and
   runs first. It reads the host — `{slug}.{COMPANY_SITE_DOMAIN}`, or a
   domain Rihla's admin mapped to the company (`partners.site_domain`). If
   the host is a company with a live site, the middleware points
   `CompanyContext` at that company, so the ADR 0009 scope walls every
   query to it, exactly as in its panel. A live site is one Rihla's admin
   has switched on (`site_enabled`, standing for the registration fee until
   Phase 5) and the company has published. Any other name under the
   companies domain is a 404, never a second rihla.mv. rihla.mv's own
   requests never cost a query.
2. **An allowlist, not a blocklist.** On a company's address only the
   route names in `CurrentSite::ROUTES` answer: home, packages, contact and
   enquiry, checkout and the waiting list, the customer and family portals,
   the sitemap, robots and the installable app. A page Rihla adds later is
   absent from every company site until somebody adds it to that list.
   Panels, the API, health and Pulse are refused by path before routing.
3. **Same pages, another frame.** `layouts.app` hands every page to
   `sites/layout`, which is the company's: its name, logo, colours, font
   and contact details, in one of three themes (`CompanySite::THEMES`),
   each with its own header, opening and home-page order. Rihla's palette
   classes take the company's colours from one generated stylesheet
   (`SitePalette`): words from the colour checked as readable on white,
   surfaces from the accent, white words on a surface turned dark where
   white would not read. "Powered by Rihla" is one line, off unless Rihla's
   admin switches it on.
4. **Links follow the customer's company.** `SiteAddress` makes a portal
   link or a signed waiting-list claim at the company's own address, signed
   for that host. A company with no live site gets no link, never one to
   rihla.mv, which could not open it.
5. **An app per company, by origin.** The manifest, icons (its logo on
   white, or its initials on its colour), offline page and service worker
   are generated per company and served from its address. A worker's cache
   belongs to its origin, so two companies' apps on one phone never share
   one.
6. **The leader pages stay with the panel.** A company's leaders open them
   from the company panel, at rihla.mv with `?company=`, behind the
   panel's own sign-in and second step. Serving them on the company's
   address would mean a second sign-in, second step and session on every
   address for staff pages no pilgrim sees.

## Consequences

- A company site cannot show anything that has no company: Rihla's
  articles, guide, trips, stays and media stay Rihla's. A company that
  wants such pages needs them built company-aware first.
- The production server needs a wildcard subdomain `*.rihla.mv` in cPanel
  and `COMPANY_SITE_DOMAIN` set; a company's own domain needs adding in
  cPanel with a certificate before Rihla's admin maps it. The test server
  cannot host company addresses (`coral.test.rihla.mv` is two levels deep).
- `.htaccess` sends `robots.txt` to the application for any host but
  rihla.mv, www and test, because the file in `public/` names rihla.mv's
  sitemap.
- In a test, one application serves every request, so a company a request
  set can outlive it; the middleware resets it, and tests use absolute
  addresses because the test client sends a relative path to the previous
  request's host.
- `CompanySitesShareNothingTest` is the release gate: two companies and
  rihla.mv, every page, sitemap, manifest and portal link checked against
  the other two.

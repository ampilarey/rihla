# Plan: Umrah companies run their business on Rihla

**Status: plan only. Nothing here is built.** Written from a conversation
with the owner in the Hijaz repository (`ampilarey/hijaz`), for a session
that starts in this repository and has not read that conversation.

The idea: other Hajj and Umrah companies get everything Rihla's own Umrah
business runs on (website, bookings, pilgrims, money, operations, customer
portal), each as a fully independent company under its own name, hosted by
Rihla. Hijaz Hajj & Umrah will be one of them.

## Decided by the owner

| # | Decision |
|---|---|
| 1 | **Everything lives in Rihla.** One app, not a separate platform project |
| 2 | **Other companies are totally independent of rihla.mv.** rihla.mv never shows, lists, searches or links another company's trips, staff or customers. Rihla only hosts them |
| 3 | **Every Umrah feature is built in Rihla; a company gets the features it pays for** |
| 4 | **Each company chooses a theme and customises its own website** from its own admin |
| 5 | **Address:** `company.rihla.mv` (as `hijaz.rihla.mv` today), or the company's own domain if it has one |
| 6 | **The owner adds companies.** No public sign-up |
| 7 | **Each company has its own Telegram bot** |
| 8 | **"Powered by Rihla" is off by default,** and can be switched on per company |
| 9 | **Company websites are in the first version,** not a later addition |
| 10 | **Hosting starts on cPanel** and moves when it grows (ADR 0002 already names the triggers) |
| 11 | **Any company may be the pilot.** Hijaz moves in after the build is complete |
| 12 | **A company must be able to leave:** its data exported in full, and a dedicated installation on its own hosting kept possible |
| 13 | **A registration fee, set by Rihla's admin.** Once it is paid, the company can set up its **basic website** |
| 14 | **Everything beyond the basic website is a paid feature,** switched on by Rihla's admin when it is paid for |
| 15 | **No card payments through Rihla's BML gateway** for other companies. They take bank transfer and cash, or bring their own merchant account later |
| 16 | **Each company has its own admin panel** |
| 17 | **Rihla's admin checks each company's licence** before it is added |
| 18 | **Each company gets its own installable app (PWA)** under its own name, icon and colours. A native mobile app is planned later |

## What already exists here (read before planning any phase)

- **Tenancy for hosts**: the `/host` panel on Filament tenancy with
  `Partner` as tenant, `HostMembership` and `HostRole`, `HostContext` for
  policies, approval and suspension, statements and payouts (§16, ADR 0008).
- **`Partner::KIND_AGENCY`** is already a kind of partner, and Rihla is
  already a partner of its own (`is_rihla`). An Umrah company is the same
  record with more behind it.
- **Host subdomains**: `{slug}.rihla.mv` routing and the reserved-name list
  (`config/marketplace.php`), switched off until wildcard DNS and a
  certificate exist.
- **Guarded branding**: `HostPage` (layout, logo, cover, two colours, font,
  sections) with the `Contrast` check on save.
- **Service switches**: `App\Support\Services`, today one row for the
  whole site.
- **The Umrah back office**, including what was ported from Hijaz in
  #171–#179: desk bookings, instalments, discounts, buses, lists, cards,
  Scan, the staff Telegram bot, document reading, visas in bulk, the
  departure lifecycle, three currencies, the books, families.
- **Privacy and safety tooling**: `Anonymisation`, `Forgetting`, encrypted
  files and identifiers, verified backups, the second factor.

**What is not company-aware.** Packages carry `partner_id`; everything
downstream of them (departures, bookings, travellers, customers, payments,
documents, visas, permits, rooms, buses, roll calls, incidents, costs, the
books, settings, the Telegram bot, the portals) belongs to Rihla alone.
That is the centre of this plan.

## Still in Hijaz and not yet here

Built in Hijaz after the #171–#179 batches. Each is checked against this
codebase before it is ported, and ported company-aware (Phase 3):

- A trip created before its dates are known; dates taken from its flights.
- Registering customers before a price exists; payments refused until the
  price is set; waiting travellers priced when it is.
- Flight groups: the people on one flight out and one home, each group
  with its own buses and colours; per-group airline lists and letters.
- A family's leader and contact person, and a contact for one booking.
- The customer portal with a password: sign in by mobile number, all the
  customer's bookings, agreement signed on screen or by an uploaded scan,
  travel acceptance letters, share by WhatsApp, Viber or Telegram.
- Deleting a trip that has no bookings.
- The home dashboard: figure cards and two charts, each gated by permission.

The Hijaz repository stays as the reference for each.

## What "totally independent" means in code

- **One database, every Umrah record tagged with its company**
  (`partner_id`, as packages already are), scoped by a global scope on the
  model as well as by Filament's tenant scoping, and checked by `HostContext`
  style ownership in policies. Lists are scoped; so is a record reached by id.
- **No shared people.** A customer, traveller or family belongs to one
  company. The same person at two companies is two unrelated records, with
  two portal logins. The ID-number blind index has the company in its key.
- **No shared surface.** rihla.mv's pages, search, sitemap, structured data,
  feeds and assistant read Rihla's own company only. A company's site reads
  only its own.
- **Staff are a company's own.** Rihla's `/staff` permissions give no
  access to another company's records. Support access is a separate,
  consented and audited act (Phase 5).
- **Files apart** (`documents/{company}/…`), **cache keys apart**, and every
  **queued job and scheduled command** carries its company and refuses to
  run without one.
- **Telegram apart**: each company's bot token stored encrypted on the
  company, its own webhook address and secret; an update arriving on one
  bot can only reach that company's data.
- **Proved by tests, as a release gate**: a suite that signs in as company
  A (staff, customer, leader, Telegram) and tries every page, file, PDF,
  portal link, list, export and bot query of company B, and must be refused
  each time. `Anonymisation::SCRUB` and `Forgetting` classify every new
  table in the same commit, as AGENTS.md already requires.

**The honest limit.** Whoever runs the server can read the database. The
company agreement says so plainly and binds Rihla not to; the product does
not claim a technical guarantee it cannot give.

## Features a company pays for

Decided (13, 14): **a registration fee, then features one by one.**

- **Registration fee.** Set by Rihla's admin, per company or as a default.
  Until it is marked paid, the company exists but cannot publish anything.
  Once paid, the company can set up its **basic website**: theme, logo,
  colours, words, photographs, trips shown, contact and enquiry form.
- **Every other feature is paid and switched on by Rihla's admin** when the
  company has paid for it: bookings and payments, pilgrims and documents,
  visas, buses and rooms, lists, cards and Scan, the books, the customer
  portal, the Telegram bot, document reading, the installable app, own
  domain, extra staff seats, and the rest.
- **Rihla's admin sets every price.** The product records what is on and
  what was paid; it does not take card payments from companies (billing is
  by hand at first, see "Later").
- Grouping features into named plans stays possible later, as a
  convenience for the admin; the rule is still one switch per feature.
- `Services` grows from one row for the site to **a set per company**; the
  owner sets it when adding or changing a company. Rihla's own company has
  everything.
- A feature that is off: its screens, routes, menu items, jobs and bot
  commands are absent for that company, not merely hidden.
- **Downgrade keeps the data** and makes that area read-only. Nothing is
  deleted by a plan change.
- **Card payments are not a feature Rihla can sell (15).** Rihla's BML
  gateway is for Rihla only. A company's customers pay by bank transfer
  (slip uploaded and verified, as here) or cash. A company that later has
  its own merchant account could have its own gateway credentials stored
  on the company; that is a later decision, not in the first version.

## Each company's installable app (18)

- **Yes, each company gets its own installable app** (a PWA, as rihla.mv's
  is today): its own name, icon, colours and start page, served from its
  own address, so a pilgrim installs "Coral Umrah", not Rihla. The
  manifest, icons and service worker are generated per company, and the
  offline cache is kept per company so two companies' apps on one phone
  never mix.
- It is a paid feature (14) unless the owner decides it comes with the
  basic website.
- **A native mobile app later.** To keep that cheap, the customer and
  leader screens are built on the same company-scoped data the web uses,
  so a future app reads an API rather than needing a second system. One
  native app can serve every company (the pilgrim picks or is linked to
  theirs) or each company can have its own store listing; that is decided
  when the app is built.

## Each company's website and theme

- A company's address serves **its own public site**: home, trips, a trip's
  page, enquiry and booking, contact, and its customer and leader portals.
- **A few themes**, each a complete layout, built from Rihla's existing
  public pages. The company picks one and sets its logo, colours, font,
  photographs, words, and which sections show.
- **Guarded, as host pages are**: colours pass the `Contrast` check; there
  is no free page builder (ADR 0008's reasons stand).
- Languages as Rihla has them (English, Dhivehi, Arabic), with the company
  writing its own content.
- **Nothing of Rihla's** appears on a company's site unless "Powered by
  Rihla" is switched on for it: no Rihla header or footer, no Rihla logo in
  the installable app, PDFs, emails or the bot.
- Own domain: the company points its domain at the server; the owner adds
  it in cPanel with a certificate; the app maps the domain to the company.

## Leaving

- **Export, always**: one download of everything the company holds
  (customers, travellers, bookings, payments, books, documents, audit
  trail) in open formats, from the owner's tools and on the company's
  request.
- **Dedicated installation, kept possible**: the same app runs with a
  single company. Offered, if ever, as a managed service on the company's
  hosting; the design must not make it impossible (no logic that assumes
  rihla.mv, no company data outside its `partner_id`).

## Phases

Every phase goes to test.rihla.mv first and is promoted to production by
hand, as now. Rihla's own Umrah business keeps working throughout.

### Phase 1: the Umrah side becomes company-aware (largest)
- `partner_id` on every Umrah table; existing rows backfilled to Rihla's
  own partner record, in reversible migrations with literals, rehearsed on
  MySQL against a restored copy of production (AGENTS.md traps apply:
  constrained columns on SQLite, dependent-migration list, no constants).
- Global scopes, policies, files, cache keys, jobs and scheduled commands
  made company-aware; references and unique columns per company.
- The cross-company test suite.
- **Done when**: Rihla's staff see no difference, and a second test
  company created by hand is fully walled off.

### Phase 2: the company panel
- An Umrah company's own panel (the `/host` pattern: tenant, memberships,
  roles), carrying the back office that `/staff` has for Rihla, with the
  company's name and colours.
- Per-company settings: profile, bank accounts, agreement wording,
  reference prefix, exchange rates, ledger categories, Telegram bot.
- **Done when**: a test company can run a trip from registration to
  return without touching `/staff`.

### Phase 3: the rest of Hijaz, company-aware
- The list under "Still in Hijaz and not yet here", each checked against
  what exists here first.
- **Done when**: nothing an Umrah company does in the Hijaz app is missing.

### Phase 4: company websites and themes
- Subdomain and own-domain routing to a company's site; themes;
  the customer and leader portals and the installable apps under the
  company's brand; "Powered by Rihla" as a per-company switch.
- rihla.mv audited to confirm it surfaces nothing of any other company.
- **Done when**: two test companies have sites that share nothing visible
  with each other or with rihla.mv.

### Phase 5: plans and the owner's tools
- Plans and per-company feature switches.
- Add a company (details, licence checked, first admin invited, defaults
  seeded); suspend and reactivate; usage as counts, never contents;
  consented, recorded support access; full export; closing an account.
- **Done when**: the owner can add a company in minutes and it can take
  its first booking without help.

### Pilot
- One real company, added by the owner, on a signed agreement, invoiced by
  hand, for at least one trip from booking to return.

### Hijaz moves in
- Hijaz added as a company at `hijaz.rihla.mv`; an import brings its data
  from the Hijaz app, rehearsed until money totals, counts and file
  checksums match; a short planned stop; the old app kept read-only for a
  month, then retired.

### Later, only if wanted
- Billing automation (invoices to companies, reminders, suspension).
- A managed dedicated installation.
- The move off cPanel, on ADR 0002's triggers.

## Hosting notes

- **Wildcard**: `*.rihla.mv` needs a DNS record and a certificate covering
  it. If the host cannot issue one, each company's subdomain is added by
  hand in cPanel, as `hijaz.rihla.mv` was. Guesthouse hosts and Umrah
  companies share one name space: one registry, one reserved list.
- **Media**: many companies' photographs and scans may not fit a cPanel
  disk allowance (ADR 0008 already says so for hosts).
- **Cron-driven queues** serve every company in turn; jobs stay small and
  safe to run late and twice.

## Risks

| Risk | Answer |
|---|---|
| One company sees another's data | Scopes, policies, files, cache and jobs per company; the cross-company suite as a release gate; a security review before the pilot |
| Rihla's live Umrah business breaks during Phase 1 | Invisible change; reversible migrations rehearsed on a production copy; test first, promoted by hand |
| rihla.mv leaks another company (search, sitemap, assistant, feeds) | Explicit audit in Phase 4, with tests on each surface |
| Companies distrust a host that also sells Umrah | Independence in code, a written agreement, no Rihla branding on their sites, export at any time |
| Themes produce unreadable or broken sites | A few complete themes, the contrast check, no page builder |
| Plans become a maze | A few plans and named add-ons; one place that decides what is on |
| The Hijaz app and Rihla drift further apart | New Umrah features are built here from now on; Hijaz gets fixes only |

## Open questions for the owner

Answered November 2026 (decisions 13 to 18): the registration fee and
paid features, no card payments through Rihla's BML, each company's own
admin panel, licences checked by Rihla's admin, and each company's own app.

Still open:

1. **Wildcard subdomains.** The owner believes the host supports
   `*.rihla.mv` with a certificate; it must be confirmed in cPanel (a
   wildcard DNS record and a wildcard or auto-issued certificate) before
   Phase 4. If not, each company's subdomain is added by hand.
2. **The registration fee's amount** and each feature's price, set by
   Rihla's admin when the tools exist (Phase 5).
3. **Where the company admin panel lives:** at `company.rihla.mv/admin`
   (on the company's own address), or one shared login page. The plan
   assumes the company's own address.
4. **Which register a licence is checked against** (the Ministry of
   Islamic Affairs' list of Hajj and Umrah operators, or another), and
   whether the licence number and expiry are stored and shown.

## For the session that builds this

- Start with Phase 1 only, and begin by listing every Umrah table and
  every place a query, job, file path or cache key would cross companies.
- Give a time estimate for Phase 1 before starting it.
- Each phase gets its ADR and its section here marked done, as §16 was.
- The Hijaz repository (`ampilarey/hijaz`) is the reference for Phase 3;
  its `docs/PLATFORM_PLAN.md` is superseded by this document.

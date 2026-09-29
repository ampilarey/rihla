# 0008 — Hosts run their business here, and Rihla is paid only when it brought the guest

**Status:** Accepted
**Date:** 2026-09-29
**Context:** §16 of [`WEBSITE_UPGRADE_PLAN.md`](../WEBSITE_UPGRADE_PLAN.md)
**Supersedes:** §15.2 decision 8 ("no partner login") and the §11.24 deferral of a marketplace
**Decided by:** the owner, in conversation; the defaults below are the plan's and each is a config value or a per-host field

## Context

Rihla's Stays line was built as a marketing arrangement: Rihla's staff enter a
handful of guesthouses, confirm each request with the owner by phone, and take
a deposit. The owner now wants what Booking.com and Airbnb are: any property
owner in the Maldives lists their rooms, houses or apartments; guests search
every listing in one place and pay by card; each host has a branded page of
their own; and Rihla earns a commission.

Two further asks shape the answer. Hosts should be able to **run their whole
business here** — every booking from every source, occupancy, check-in, bills,
tax reports — so they need no other tool. And tourists and Maldivians book the
same rooms at different prices, in different currencies, with different taxes.

The marketplace was deferred in §11.24 because "it describes an organisation
with an architecture function". That is still true of multi-tenancy as a
platform product. It is not true of *this*: the booking engine, the calendar
lock, the ledger, the guest register and the tax rules already exist and are
tested; what is being added is who may edit them and who gets paid.

## Decision

1. **One inventory, many doors.** Guesthouses, rooms and apartments, and
   island holidays are three entrances to one set of `properties`, one calendar
   and one booking table. A door may be switched off without touching the
   inventory (the service registry already does this).

2. **Managing the business is free; Rihla is paid only on bookings the
   marketplace brought.** A booking a host enters themselves — phone, walk-in,
   another site — carries no commission. This is the incentive that keeps the
   host's calendar true, and a true calendar is what makes the marketplace's
   "available" mean something. Commission is a per-host percentage
   (`partners.commission_pct`, which already exists) snapshotted onto each
   stay at the moment it is made.

3. **Rihla is the only merchant.** BML Connect pays one account and cannot
   split a payment, so every online payment lands with Rihla. Two settlement
   models exist per host: `commission_deposit`, where the guest's online
   payment *is* Rihla's commission and the balance is paid to the host at the
   property, and `full_collection`, where Rihla takes everything and pays the
   host out against a monthly statement. Hosts start on the first; Rihla's own
   rooms run on the second from day one; other hosts move to it when payouts
   are built (Phase 16). Both are the same tables; the model is a field.

4. **Two audiences, two prices, one calendar.** Every room type carries a
   tourist rate (USD) and a local rate (MVR); either may be empty, meaning
   "not sold to that audience". Green Tax follows the tourist rate. The
   audience is declared at booking by nationality and checked against the
   guest register at check-in. Nothing is converted between currencies.

5. **Rihla's frame, the host's body.** The header, footer, search, booking
   steps and payment pages are Rihla's on every page. A host's storefront and
   listing pages carry the host's logo, photographs, two colours and words
   inside that frame. Colours pass the same readability check hero banners
   do. There is no page builder.

6. **Hosts use a second Filament panel at `/host`, scoped by tenant.** The
   same `users` table, a membership table with a role per host, and
   Filament's tenancy so a host can only ever query their own rows. Staff keep
   `/staff` and gain a Hosts resource to approve and suspend.

7. **Nothing is invented.** No seeded hosts, listings or reviews. A host
   goes live only after a person at Rihla has checked their Ministry of
   Tourism registration. Reviews come only from completed stays.

## Alternatives considered

- **A monthly software fee for hosts.** Cleaner revenue, but the first eight
  hosts will not pay for software they have never used, and a fee makes
  hosts keep their direct bookings elsewhere — which is exactly the calendar
  drift a marketplace cannot survive. Revisit once hosts depend on the tool.
- **Commission on every booking, including direct ones.** Hosts would enter
  direct bookings as blocked dates instead, and the reports, bills and guest
  register would be wrong for the bookings that matter most to them.
- **A separate PMS product on a separate domain.** Two logins, two brands,
  two deployments on one cPanel account with no queue worker. The panel is
  the cheaper way to give hosts "everything under one roof", and it can be
  split out later if it earns it.
- **Letting hosts choose any layout and any colours.** This is how the site
  ended up with a `PLxxxxxxxxxx` playlist and white-on-gold buttons. Two
  layouts and a contrast rule leave room for a brand without room for a
  broken page.

## Consequences

- `partners` becomes the host record and keeps its name; a rename would
  touch every stays test for no behaviour change.
- Every new table holding a person's words or identifiers is classified in
  `Anonymisation::SCRUB` **and** `Forgetting` in the same commit, and every
  table with a foreign key into `stays` or `customers` is added to
  `PackageDepartureTest::DEPENDENT_MIGRATIONS` — both traps are recorded in
  `AGENTS.md` and both have bitten before.
- The scheduler is not wired today; holds that expire and reviews that
  invite need `schedule:run` on cron. Phase 12 starts there.
- Photographs from many hosts do not fit a cPanel disk allowance; a media
  disk on object storage is a prerequisite for opening registration, and it
  is an account only the owner can create.
- The travel-agency licence question (§15.2 decision 6) grows: Rihla is now
  facilitating stays for foreigners at other people's properties. Registered
  hosts only, by default, and the registration number shown on the listing.

## When to revisit

- A host asks to pay for the tool rather than take the marketplace, or a
  second operator asks to white-label it: that is the §11.24 multi-tenancy
  question, and it becomes worth answering.
- BML or another Maldivian gateway offers split settlement: `full_collection`
  could then pay hosts directly and the statement becomes a receipt.

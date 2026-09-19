# 1. How content is translated

- **Status:** accepted
- **Date:** 2026-09-19
- **Context:** §9.4 of [`WEBSITE_UPGRADE_PLAN.md`](../WEBSITE_UPGRADE_PLAN.md)

## The problem

`trips` carried two half-built translation mechanisms at once.

A `locale` column said which language the whole row was in, so a Dhivehi trip
meant a second, duplicate row with its own slug and its own publication state.
Four `*_dv` columns said the row held both languages at once. The admin panel
used both: it listed trips filtered by the panel's own locale, and it revealed
the `*_dv` inputs only after you set the language to Dhivehi — so the Dhivehi
text could only ever be typed on the duplicate row.

Neither mechanism reached a visitor. `Trip::published()` has never filtered by
locale, so every row showed on both sites, and no public view has ever read a
`*_dv` column. What the Dhivehi half did do was block the admin: `TripRequest`
made all four `*_dv` columns **required** the moment the language was set to
Dhivehi, for text that nothing rendered. A staff member without a Thaana
keyboard could not save a Dhivehi trip, and if they had, both rows would have
appeared on both sites as two separate trips.

Live data at the time of the decision: three trips, all `locale = en`, none
with any `*_dv` value set. Public views reading a `*_dv` column: none.

The other content tables disagreed with `trips` and with each other:

| Table | Mechanism |
|---|---|
| `trips` | `locale` **and** `title_dv` / `summary_dv` / `details_dv` / `location_dv` |
| `guide_steps`, `hero_banners`, `why_sections` | `locale` column, one row per language |
| `why_features`, `media` | none at all |

## The decision

**JSON translatable columns** via `spatie/laravel-translatable`, for content
whose translations share a URL, a publication state and a position in a list.
The column holds `{"en": "…", "dv": "…"}` and is read back in the request's
locale.

**Translation tables** — a `*_translations` child table with its own slug, SEO
fields and publication state per locale — only for entities that genuinely need
those three things per language. Today that is nothing. It is where `packages`,
`articles`, `locations` and `notification_templates` will go when they exist,
and this decision is the reason those will not inherit the JSON shape by
default.

Fallback is English, and then any language the record does have. A card with a
blank title is broken; a card in a language the reader did not expect is merely
untranslated.

### What is not translated

- **Scripture.** `guide_steps.dua_text` is the Arabic of the rite: the same
  words whatever language the page is in. It is one column, shown unchanged in
  both languages. Its `reference_text` — "Quran 2:196 and authentic hadith" —
  *is* translated, because that sentence is written for the reader.

- **Slugs.** One trip has one public URL. The locale is already the first path
  segment in front of it (`/en/trips/…`, `/dv/trips/…`), and a slug is ASCII —
  Thaana does not transliterate into one. Per-locale slugs are a translation
  table's job, and nothing needs one yet.
- **Names that are already language-specific** — a `name_latin` /
  `name_dhivehi` / `name_arabic` triple is data about a place or a person, not
  a translation of one string.

## Consequences

- A trip is one row. Creating the Dhivehi version is filling the second box in
  the same form, not creating a second trip.
- Dhivehi is never required anywhere. Where it is missing, English shows.
- Ordering and searching by a translated column sorts by the raw JSON, which
  begins `{"en":`, so it sorts by the English text. Good enough for an admin
  list of tens of rows; a real search needs an index, which is Phase 3's
  problem and another reason `packages` may want a translation table.
- The audit log decodes translatable columns before writing them, so adding a
  Dhivehi title is a distinguishable event from rewriting the English one.
- `hero_banners` and `why_sections` still use one row per locale, and
  `why_features` and `media` still have no mechanism at all. They are
  converted next, in their own change, because they carry seeded content and
  an admin panel each.

## What it changed for a reader

The guide is the clearest case. A step used to be two rows agreeing only by
convention on their `step_number`, so `PageController` could only ask "are
there any Dhivehi steps?" — and with none, it served *the whole guide* in
English. One missing translation meant none of them showed.

Now the fallback is per field. A step translated by halves shows the Dhivehi
title above the English instructions, and an untranslated step sits in a
translated guide without taking the rest down with it.

It also made a rule enforceable that had been written and never switched on:
`step_number` is unique. It could not be before, because two rows sharing a
step number was how a step was translated.

## Why now rather than in Phase 5

The plan's own reasoning: *"Doing this in Phase 1, while there are eight
models, costs days; doing it in Phase 5 costs weeks."* Packages, itineraries,
departures, articles, Ziyarah locations and learning modules all arrive later
and all need translating. Each one built on the `*_dv` pattern is another
migration later.

# 4. Keep the media model until something other than a trip owns media

- **Status:** accepted
- **Date:** 2026-09-19
- **Context:** Phase 1 of [`WEBSITE_UPGRADE_PLAN.md`](../WEBSITE_UPGRADE_PLAN.md), "media library"
- **Supersedes nothing; narrows a Phase 1 line item**

## The decision

Phase 1 lists "media library", meaning `spatie/laravel-medialibrary` in place
of the hand-rolled `Media` model. **That swap waits.** The parts of the work
that do not depend on it — translated titles and captions, and the broken
video thumbnails — are done now.

This is a reduction in scope, recorded so it is a decision rather than an
omission.

## Why

**The current implementation is not the problem.** `Media` stores a WebP at
1600px and a 400px thumbnail, both written synchronously at upload from a
filename the client does not control. That is the right shape for this host.

**Medialibrary's defaults fight [ADR 0002](0002-stay-on-cpanel-shared-hosting.md).**
Its conversions run on the queue. On cPanel the queue is the database driver
woken by cron once a minute, so an uploaded photograph would appear
unconverted for up to a minute — or need conversions forced non-queued, which
is the behaviour we already have, written by hand and tested.

**Its real win is not needed yet.** Medialibrary earns its place when many
models own media: packages, articles, Ziyarah locations, traveller documents.
Today one model does — `media.trip_id` — and `trip_id` is nullable, so
standalone gallery items already work. Converting now means migrating every
row and every file, changing every view, and adding a package, to arrive at
the same set of features.

**And it is not free.** It is another dependency to upgrade on a host where
`composer update` is run by hand over SSH, on top of the 32 that Filament just
added.

## What was done instead

- `media.title` and `media.caption` are translated, the same way as every
  other content table ([ADR 0001](0001-how-content-is-translated.md)). This was
  the last table with no translation mechanism at all: a Dhivehi visitor read
  English captions with no way to change that.
- `Media::getThumbnailUrlAttribute()` no longer points at **via.placeholder.com**
  for Vimeo, Facebook, Instagram and TikTok videos. That service has shut down,
  and `img-src` allows `'self'`, `data:` and YouTube's thumbnail hosts only —
  so every non-YouTube video in the gallery rendered a broken image *and*
  logged a Content-Security-Policy violation. YouTube thumbnails still work;
  everything else falls back to an uploaded thumbnail, and to the gallery's own
  placeholder panel when there is none. A test fails if a third-party
  placeholder host comes back.
- The controller's two hand-copied rule lists became one `MediaRequest`. They
  had already drifted: only `store()` required a file for a photo.

## When to revisit

Adopt medialibrary when the **second** model needs to own media — which is
`packages` or `articles`, both Phase 2. Doing it then converts one model's
worth of rows into a system that was going to be needed anyway, rather than
converting one model to gain nothing.

Bring it forward if any of these arrive sooner:

1. Responsive `srcset` becomes a measured performance requirement (§10.1).
2. Media needs to live on S3 or a CDN rather than the local disk.
3. Traveller documents arrive in Phase 3 — those need a media system with
   access control, and rolling that by hand would be a mistake.

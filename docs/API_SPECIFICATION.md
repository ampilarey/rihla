# Rihla — API Specification

*Rewritten November 2026. The earlier version documented admin "endpoints"
for trips, media, settings, banners and guide steps; those are screens in
the Filament panel at `/staff` now, not an API, and are not listed here.*

Rihla is a server-rendered site. Its pages and the four Filament panels
(`/staff`, `/company/{slug}`, `/host/{slug}`, `/operator/{slug}`) are not an
API and carry no stability promise. This file lists the only endpoints meant
to be called by a machine. `php artisan route:list --path=api` is the source
of truth.

## Public

### `GET /api/guide-steps`

The published steps of the pilgrim's guide in one locale. Every field falls
back to English where that locale has no translation, so the response is
never partly blank. Not locale-prefixed; the locale is a query parameter.

| Parameter | | |
|---|---|---|
| `locale` | optional | `en` or `dv`; defaults to the current locale when that is one of them, else `en`. Anything else is a `400` with `{"error": "Invalid locale"}`. |

```json
{
  "locale": "en",
  "total_steps": 15,
  "steps": [
    {
      "step_number": 1,
      "title": "Intention (Niyyah)",
      "summary": "…",
      "details": "…",
      "dua_text": "…",
      "reference_text": "…",
      "fiqh_notes": ["…"],
      "checklist": ["…"],
      "image_url": "/storage/guide/step1.jpg",
      "video_url": null
    }
  ]
}
```

### `GET /api/health` — throttled to 60 a minute

```json
{ "ok": true, "app": "Rihla Travels", "env": "production" }
```

With the deploy secret (`DeploySecret`), the response also carries
`"commit"`, the revision being served — which is how the deploy pipeline
knows the pull it started has landed. Without it the commit is withheld:
naming the exact revision would tell an attacker which advisories apply.

## Called by our own infrastructure only

| Endpoint | Caller | Guarded by |
|---|---|---|
| `POST /api/deploy/test-pull` | GitHub Actions after CI passes on `main` (`.github/workflows/deploy-test-immediate.yml`) | The shared `TEST_DEPLOY_WEBHOOK_SECRET`; absent entirely where none is configured, and refuses a host it does not recognise. Throttled to 10 a minute. See [TEST_AUTO_DEPLOY.md](TEST_AUTO_DEPLOY.md). |
| `POST /api/telegram/webhook` | Telegram, for Rihla's staff bot | Telegram's secret-token header. Throttled to 120 a minute. See [TELEGRAM_BOT.md](TELEGRAM_BOT.md). |
| `POST /api/telegram/company/{company}/webhook` | Telegram, for an Umrah company's own bot | That company's secret token; 404 for a company with no bot. |

## Pages a customer is sent a link to

Not an API, but stable addresses that leave the building in WhatsApp
messages and e-mails, so they must keep working. Each is found by a random
key, never by a counted reference (`BookingFlowTest` refuses a public URL
that carries one):

| Address | What it is |
|---|---|
| `/{locale}/boats/tickets/{key}` | A boat booking's tickets, one QR per passenger |
| `/{locale}/boats/requests/{key}` | A cargo or charter request, its quote and its progress |
| `/{locale}/travel/requests/{key}` | A trip: options to choose, payment, issued documents, visa checklists |
| `/boat-scan?t={token}` | Where a ticket's QR goes: crew are sent to their panel's boarding page |

# Promoting to production

## What rihla.mv is serving right now

Checked against the live site on 2026-09-19, five weeks after the audit that
started this work. The production homepage shows visitors:

```
hero_title
hero_sub
cta_trips
cta_whatsapp
```

Those are **raw translation keys** where the headline and the call-to-action
should be — defect D1, the first thing the audit found, still live. Directly
below them:

```
Current Trip
Maldives Island Hopping Adventure
```

An island-hopping beach holiday, on an Umrah operator's site — D2, also still
live. And:

| | rihla.mv | test.rihla.mv |
|---|---|---|
| `/en`, `/dv` | **404** | 200 |
| `/api/health` | **404** | 200 |
| Security headers | **0 of 4** | 4 of 4, incl. an enforcing CSP |
| Hero text | raw keys | real copy |
| Trips | resort holidays | three Umrah departures |

Everything in the rest of this document exists so that this gap can be closed
deliberately rather than by a webhook. Nothing here runs by itself.


`test.rihla.mv` deploys itself on every push to `main`. **`rihla.mv` does not**, and
nothing in this repository will deploy it for you. Promotion is a decision someone
makes and a command someone types.

This page is how.

---

## Why it is manual

The test deploy runs `migrate --force` unattended. On test that is right. On production
the same command runs against real passports, bookings and payments, and there is no
undo. So production has a different path: a backup it cannot skip, a set of checks it
cannot fail, and a person.

---

## Before you start

You need SSH access to the cPanel account and roughly ten minutes. Pick a quiet hour —
the site is offline for the middle of this, usually under a minute.

**Look at the test site first.** Everything you are about to promote is already running
on `test.rihla.mv`. If something there looks wrong, it will look wrong on `rihla.mv`.

---

## The deploy

```bash
ssh rihla@<host>
cd /home/rihla/rihla.mv

# 1. See what would happen. Changes nothing.
DRY_RUN=1 ./scripts/deploy-production.sh

# 2. Do it.
./scripts/deploy-production.sh
```

To promote one specific reviewed commit rather than whatever `main` happens to be:

```bash
./scripts/deploy-production.sh <full-40-character-sha>
```

### The first promotion: exact commands

Run these in **cPanel → Terminal** (or over SSH) as the `rihla` user. Do them in
order. After each one, check the "expect" line before moving on — if what you
see differs, stop and send me the output rather than continuing.

Set aside twenty minutes and do it when you can watch the site afterwards, not
last thing at night.

---

### 0. Find the production directory

```bash
ls -d /home/rihla/rihla.mv && cd /home/rihla/rihla.mv && pwd && git log --oneline -1
```

**Expect:** the path printed twice, then one commit line — whatever production
is currently on. If `ls` says "No such file or directory", the site lives
somewhere else; run `ls /home/rihla` and send me what you see.

---

### 1. Check PHP is new enough

```bash
/opt/cpanel/ea-php84/root/usr/bin/php -v
```

**Expect:** `PHP 8.4.x`. Laravel 13 needs 8.3 or newer. If this errors, try
`ea-php83` in place of `ea-php84`; if neither exists, stop — the PHP version
has to be changed in cPanel first, and deploying onto 8.2 will take the site
down.

---

### 2. Look before you leap

```bash
git fetch origin main && git status && git log --oneline HEAD..origin/main | wc -l
```

**Expect:** a clean working tree, and a count of the commits about to be
applied. If `git status` shows modified files, somebody edited code directly on
the server — stop and tell me, because step 5 will refuse and you do not want
to lose their change.

---

### 3. Dry run — changes nothing

```bash
DRY_RUN=1 bash scripts/deploy-production.sh
```

Wait — `scripts/deploy-production.sh` does not exist on production yet, because
production has not pulled this work. So for the **first** promotion only, fetch
the script by itself:

```bash
git fetch origin main && git checkout origin/main -- scripts/ && DRY_RUN=1 bash scripts/deploy-production.sh
```

**Expect:** a numbered list of seven steps, each saying `would run:`. It will
almost certainly report failures at step 1 — that is the point of a dry run.
Send me everything it prints.

Common ones and what they mean:

| Line | Means |
|---|---|
| `fail APP_DEBUG — is true` | `.env` has `APP_DEBUG=true`. Set it to `false` or a crash will show visitors your configuration |
| `fail APP_ENV — is [local]` | `.env` has the wrong `APP_ENV`. Set `APP_ENV=production` |
| `fail APP_URL — is not https` | `.env` needs `APP_URL=https://rihla.mv` |
| `fail demo content` | The resort-holiday trips are still in the database. They are removed by a migration in step 5 |
| `warn storage — symlink missing` | Uploaded images will 404. Fixed automatically in step 5 |

Fix any `fail` in `.env`, then re-run the dry run until only warnings remain.

---

### 4. Take a backup you have checked

```bash
php artisan rihla:backup && php artisan rihla:backup:verify
```

**Expect:** `Backup written: …` with a size in KB, then a table of tables with
matching row counts and `The backup opens, passes its own integrity check, and
carries every table.`

**If verify fails, stop.** Do not continue. A backup that will not restore is
the only thing standing between a bad migration and your real bookings.

---

### 5. The promotion itself

```bash
bash scripts/deploy-production.sh
```

**Expect:** the seven steps running for real, ending with `deploy complete`.
The site goes into maintenance mode at step 4 and comes out at step 7. If a
step fails, the script **stops in maintenance mode on purpose** — the site
shows a maintenance page rather than a half-deployed one. Send me the output;
do not run `php artisan up` until we know why.

---

### 6. Check it with your own eyes

Open these, on your phone as well as a computer:

| URL | Expect |
|---|---|
| `https://rihla.mv/` | Redirects to `/en`. Real headline text — **not** `hero_title` |
| `https://rihla.mv/en/trips` | Three Umrah departures. **No** "Island Hopping" or "Luxury Resort" |
| `https://rihla.mv/dv` | The Dhivehi site, right-to-left, with the dhoni logo |
| `https://rihla.mv/en/guide` | The Umrah guide, ten steps |
| `https://rihla.mv/api/health` | `{"ok":true,...}` |

Then sign in at `https://rihla.mv/login` and check Admin → Settings loads.

---

### If something is wrong

The database goes back to the backup from step 4:

```bash
ls -lt storage/app/backups | head
gunzip -c storage/app/backups/rihla-<stamp>.sql.gz | mysql -u <user> -p <database>
php artisan up
```

The code goes back to the commit from step 0:

```bash
git reset --hard <the sha you noted in step 0>
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan up
```

Send me whatever the failing step printed. Do not guess at a fix on a live
site with real bookings in it.

---

### After it is done

- Set your real social links in **Admin → Settings** (they are all empty now —
  the seeded ones were invented and one was a dead account).
- The Dhivehi site will read mostly in English. That is deliberate: see
  [`DHIVEHI_TRANSLATION.md`](DHIVEHI_TRANSLATION.md).
- Production still never auto-deploys. Every future promotion is this same
  script, run on purpose.

## What it does, in order

| | Step | If it fails |
|---|---|---|
| 1 | `rihla:preflight --production` | **Stops.** Nothing has changed |
| 2 | `rihla:backup` — gzipped dump to `storage/app/backups`, then `rihla:backup:verify` on it | **Stops.** Nothing has changed |
| 3 | Fetch, and refuse any commit that is not a descendant of what is live | **Stops.** Nothing has changed |
| 4 | Maintenance mode, then fast-forward | Stops **in maintenance mode**, on purpose — see below |
| 5 | `composer install --no-dev`, only if dependencies changed | Stops in maintenance mode |
| 6 | `migrate --force`, then cache config, routes and views | Stops in maintenance mode |
| 7 | Leave maintenance mode, then check `/up` and `/` answer | Warns; the site is already back up |

It **never** seeds. Not as an option, not behind a flag. Demo content reached production
once already and advertised resort holidays on an Umrah site.

---

## If it stops in maintenance mode

That is deliberate. A half-applied deploy should show visitors the maintenance page
rather than a broken site.

```bash
# Look at what it said, fix that, then either re-run the script or:
php artisan up
```

To roll the database back to the backup it took at step 2:

```bash
ls -lt storage/app/backups | head          # newest first
gunzip -c storage/app/backups/rihla-<stamp>.sql.gz | mysql -u <user> -p <database>
php artisan up
```

To roll the **code** back, deploy the previous commit by its SHA. The script refuses to
move backwards, so do it by hand:

```bash
git reset --hard <previous-sha>
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan up
```

---

## After the first promotion

Things this project does not have yet, in the order they will be missed:

- **A restore done by a person, once.** The deploy now verifies its own backup before it
  migrates — `rihla:backup:verify` opens the file, checks the gzip, requires mysqldump's
  completion marker and confirms every live table is in there — and it refuses to deploy
  when any of that fails. What it cannot do on shared hosting is create a scratch database
  to restore into; that needs cPanel, not the database user. Run the restore by hand once,
  against a throwaway database, and the gap is closed:

  ```bash
  php artisan rihla:backup:verify          # prints the three commands to run
  ```
- **Uptime monitoring.** Point BetterStack or UptimeRobot at `https://rihla.mv/up` with
  alerts to WhatsApp. The health route already exists.
- **Off-server backups.** `storage/app/backups` is on the same disk as the site. That
  covers a bad migration; it does not cover losing the server.
- **Anonymised test data.** `test.rihla.mv` is public. Once real passports exist,
  production data must never be copied to it except through an anonymising export.

---

## What preflight checks, and why each one is there

| Check | Why |
|---|---|
| Database reachable | Nothing else matters if it is not |
| Pending migrations counted | So you know the backup is about to earn its keep |
| `public/build/manifest.json` present, and every file it names exists | The server has no Node, so the build is committed. A manifest naming an uncommitted file throws on every page |
| `storage/framework/views` writable | Otherwise every page 500s on first render |
| `public/storage` symlink | Otherwise every uploaded image 404s |
| `APP_DEBUG` is false | A stack trace on the live site hands out configuration and file paths |
| `APP_ENV` is `production` | The demo seeders only refuse to run when it is |
| `APP_URL` is https | HSTS and secure cookies depend on it |
| `APP_KEY` is set | Sessions and encrypted columns cannot be read without it |
| A WhatsApp number is configured | Every contact link on the site is built from it |
| No demo trips or placeholder playlist in the database | The seeders refuse to run in production, but a database restored from test carries it in anyway |

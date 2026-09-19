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
cd /home/rihla/rihla.mv-app

# 1. See what would happen. Changes nothing.
DRY_RUN=1 ./scripts/deploy-production.sh

# 2. Do it.
./scripts/deploy-production.sh
```

To promote one specific reviewed commit rather than whatever `main` happens to be:

```bash
./scripts/deploy-production.sh <full-40-character-sha>
```

## First promotion on a host that has never had this tooling

`rihla:preflight`, `rihla:backup` and `rihla:backup:verify` ship **with** the
release being deployed. On a host that has never had them, `deploy-production.sh`
stops at step 1 with:

```
ABORT: this checkout has no rihla:* commands yet, so preflight and the backup cannot run.
```

That is correct behaviour, not a fault — the script refuses to deploy without a
backup it can verify, and it cannot take one until the code that knows how has
arrived. It happens exactly once per host. Every promotion after this one uses
the script normally.

The way through is to take the backup by hand, bring the code across, and then
let the new tooling check its own work before anything touches the schema. The
important ordering is unchanged: **nothing migrates until a backup exists.**

Run these in order, from the production checkout. Stop at the first thing that
does not match, and send me the output.

### 1. Confirm the merge will not clobber local edits

```bash
cd /home/rihla/rihla.mv-app
git diff --name-only HEAD origin/main -- '*.gitignore' '.env' | head
```

**Expect:** nothing. `git status` shows several `.gitignore` files as modified
on this host; that is harmless as long as the incoming release does not also
change them, and this command proves it either way. If it prints anything,
stop — a fast-forward would refuse, and I would rather know now.

### 2. Back up the database by hand

Read the credentials out of `.env` rather than typing them, and keep the
password out of your shell history and out of `ps`:

```bash
mkdir -p ~/backups
DB=$(grep -E '^DB_DATABASE=' .env | cut -d= -f2-)
DU=$(grep -E '^DB_USERNAME=' .env | cut -d= -f2-)
MYSQL_PWD=$(grep -E '^DB_PASSWORD=' .env | cut -d= -f2-) \
  mysqldump --user="$DU" --single-transaction --quick --no-tablespaces "$DB" \
  | gzip > ~/backups/rihla-before-first-promotion-$(date +%F_%H%M).sql.gz
ls -lh ~/backups/
```

**Expect:** a file of a plausible size — kilobytes at least, not zero.

### 3. Check that backup is actually restorable

```bash
BK=$(ls -t ~/backups/*.sql.gz | head -1)
gzip -t "$BK" && echo "gzip OK"
zcat "$BK" | grep -c "^CREATE TABLE"
zcat "$BK" | tail -1
```

**Expect:** `gzip OK`, a table count above zero, and a last line containing
`Dump completed`. **If the completion marker is missing, the dump stopped early
— stop and tell me.** `mysqldump` writes a plausible-looking file when it fails
part way, which is the whole reason `rihla:backup:verify` exists.

### 4. Bring the code across

```bash
php artisan down --retry=60
git merge --ff-only origin/main
composer install --no-dev --optimize-autoloader --no-interaction
```

The site shows a maintenance page from here until step 7. No migration has run
yet — only files have changed.

### 5. Now the new tooling exists: let it check the host

```bash
php artisan rihla:preflight --production
php artisan rihla:backup:verify "$BK"
```

**Expect:** preflight listing what is wrong with `.env`, and the verifier
confirming the hand-made backup carries every table.

Fix any `fail` in `.env` — `APP_ENV=production`, `APP_DEBUG=false`,
`APP_URL=https://rihla.mv` — and re-run preflight until only the exception
below remains. The site is still down while you do this, which is the right
time for it.

**One failure is expected here and must not be fixed by hand:**

```
fail  demo content — these demo trips are in the database:
      maldives-island-hopping-adventure, luxury-resort-experience
```

That check exists to stop demo content being deployed *to* production. On a
first promotion the demo content is already there — it is what visitors have
been seeing — and the release being deployed is what removes it, by migration,
in the next step. The same goes for a `PLxxxxxxxxxx` YouTube playlist if
preflight reports one.

So on the **first** promotion: treat a demo-content failure as expected, and
every other failure as blocking. On every promotion after that, a demo-content
failure is real and means something put it back.

### 6. Migrate

```bash
php artisan migrate --force
php artisan storage:link --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

This is the only step that touches your real data. You have a verified backup.

### 7. Bring it back up and look at it

```bash
php artisan up
curl -sS -o /dev/null -w "%{http_code}\n" https://rihla.mv/en
```

Then check the pages in the table under *Check it with your own eyes* below.

---

## Every promotion after the first: exact commands

Run these in **cPanel → Terminal** (or over SSH) as the `rihla` user. Do them in
order. After each one, check the "expect" line before moving on — if what you
see differs, stop and send me the output rather than continuing.

Set aside twenty minutes and do it when you can watch the site afterwards, not
last thing at night.

---

### 0. Find the production directory

```bash
ls -d /home/rihla/rihla.mv-app && cd /home/rihla/rihla.mv-app && pwd && git log --oneline -1
```

**Expect:** the path printed twice, then one commit line — whatever production
is currently on.

The production checkout is `rihla.mv-app`, **not** `rihla.mv` — `~/public_html`
is a symlink to `rihla.mv-app/public`. An earlier version of this document
guessed `rihla.mv` by analogy with `test.rihla.mv`; that directory does not
exist, and the first attempt to promote failed at `cd`. If this `ls` fails too,
run `for d in /home/rihla/*/; do [ -f "$d/artisan" ] && echo "$d"; done` and use
whichever path it prints.

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

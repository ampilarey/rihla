# Promoting to production

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

### What it does, in order

| | Step | If it fails |
|---|---|---|
| 1 | `rihla:preflight --production` | **Stops.** Nothing has changed |
| 2 | `rihla:backup` — gzipped dump to `storage/app/backups` | **Stops.** Nothing has changed |
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

- **A tested restore.** A backup nobody has restored is a hope, not a backup. Restore one
  into a scratch database and confirm it comes back.
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

#!/usr/bin/env bash
# Promote main to PRODUCTION (rihla.mv). Run by hand, never by webhook.
#
# The test site auto-deploys on every push to main. Production does not, and
# this script is deliberately not wired to anything: no GitHub Action calls
# it, no cron runs it. Someone decides to promote, and types this.
#
#   ./scripts/deploy-production.sh              # deploy origin/main
#   ./scripts/deploy-production.sh <full-sha>   # deploy one reviewed commit
#   DRY_RUN=1 ./scripts/deploy-production.sh    # show the plan, change nothing
#
# What it will not do:
#   - proceed without a verified database backup
#   - proceed if preflight fails
#   - run seeders, ever (demo content reached production once already)
#   - leave the site in maintenance mode if a step fails
set -uo pipefail

export HOME="${HOME:-/home/rihla}"
export PATH="$HOME/bin:/usr/local/bin:/opt/cpanel/ea-php84/root/usr/bin:/opt/cpanel/composer/bin:/usr/bin:/bin:${PATH:-}"

ROOT="${RIHLA_PRODUCTION_ROOT:-/home/rihla/rihla.mv}"
TARGET_SHA="${1:-}"
DRY_RUN="${DRY_RUN:-0}"
LOCK="$HOME/.deploy-production.lock"

log() { echo "$(date '+%F %T') $*"; }
die() { log "ABORT: $*"; exit 1; }

run() {
  if [[ "$DRY_RUN" == "1" ]]; then
    echo "           would run: $*"
    return 0
  fi
  "$@"
}

for tool in php git composer; do
  command -v "$tool" >/dev/null || die "$tool not found on PATH=$PATH"
done

[[ -d "$ROOT" ]] || die "production root $ROOT does not exist (set RIHLA_PRODUCTION_ROOT)"

mkdir "$LOCK" 2>/dev/null || die "a production deploy is already running"
trap 'rmdir "$LOCK" 2>/dev/null' EXIT

cd "$ROOT" || die "cannot cd to $ROOT"

log "=== Rihla production deploy ${DRY_RUN:+ (dry run)} ==="
log "root: $ROOT"

# ---------------------------------------------------------------- 1. preflight
log "1/7 preflight"
if [[ "$DRY_RUN" != "1" ]]; then
  php artisan rihla:preflight --production || die "preflight failed — fix the findings above before deploying"
else
  php artisan rihla:preflight --production || log "     (dry run: preflight findings above would stop a real deploy)"
fi

# ------------------------------------------------------------------- 2. backup
# Before anything touches the schema. The deploy runs migrate --force, and on
# production that is real passports, bookings and payments.
log "2/7 database backup"
run php artisan rihla:backup || die "backup failed — nothing has been changed"

# A dump that exists is not a dump that would restore. mysqldump writes a file
# even when the disk fills mid-table: right name, plausible size, missing the
# last few tables. Checking takes seconds here and cannot be done later.
run php artisan rihla:backup:verify || die "the backup will not restore — nothing has been changed"

# -------------------------------------------------------------------- 3. fetch
log "3/7 fetch"
# Not wrapped in run(): fetching changes nothing that is served, and a dry run
# that skips it has no commits to compare. The first draft did skip it, so
# FETCH_HEAD did not exist and the ancestry check compared against the literal
# string "FETCH_HEAD" — reporting that "FETCH_HE is not a descendant".
git fetch origin main --quiet || die "git fetch failed"

LOCAL=$(git rev-parse HEAD) || die "cannot read HEAD"
REMOTE=$(git rev-parse FETCH_HEAD 2>/dev/null) || die "cannot resolve FETCH_HEAD after fetch"
[[ -n "$TARGET_SHA" ]] && REMOTE=$(git rev-parse "$TARGET_SHA" 2>/dev/null)
[[ -n "$REMOTE" ]] || die "cannot resolve the commit to deploy"

if [[ "$LOCAL" == "$REMOTE" ]]; then
  log "already on ${LOCAL:0:8} — nothing to deploy"
  exit 0
fi

git merge-base --is-ancestor "$LOCAL" "$REMOTE" 2>/dev/null \
  || die "${REMOTE:0:8} is not a descendant of ${LOCAL:0:8}; refusing to move production sideways or backwards"

log "     ${LOCAL:0:8} -> ${REMOTE:0:8}"
log "     changes:"
git log --oneline "$LOCAL..$REMOTE" | sed 's/^/           /' | head -40

# --------------------------------------------------------------- 4. down + pull
log "4/7 maintenance mode and checkout"
run php artisan down --retry=60 --render="errors::503" || log "     WARN: could not enter maintenance mode"

restore_up() { run php artisan up >/dev/null 2>&1 || true; }
trap 'restore_up; rmdir "$LOCK" 2>/dev/null' EXIT

run git merge --ff-only "$REMOTE" || die "fast-forward failed — resolve by hand, site is still in maintenance mode"

# ---------------------------------------------------------- 5. dependencies
if [[ "$DRY_RUN" == "1" ]] || git diff --name-only "$LOCAL" "$REMOTE" | grep -qE '^composer\.(lock|json)$'; then
  log "5/7 composer install"
  run composer install --no-dev --optimize-autoloader --no-interaction \
    || die "composer install failed — site is still in maintenance mode"
else
  log "5/7 composer install (skipped: no dependency change)"
fi

# ------------------------------------------------------------------ 6. migrate
log "6/7 migrate and cache"
run php artisan storage:link --force >/dev/null 2>&1 || log "     WARN: storage:link failed"
run php artisan migrate --force || die "migration failed — restore the backup from storage/app/backups"
run php artisan config:cache || die "config:cache failed"
run php artisan route:cache || die "route:cache failed"
run php artisan view:cache || die "view:cache failed"

# No seeding. Not as an option, not behind a flag. Demo content reached
# production once already and advertised resort holidays on an Umrah site.

# ------------------------------------------------------------------- 7. verify
log "7/7 up and verify"
run php artisan up || die "could not leave maintenance mode — run: php artisan up"
trap 'rmdir "$LOCK" 2>/dev/null' EXIT

if [[ "$DRY_RUN" != "1" ]]; then
  URL="${RIHLA_PRODUCTION_URL:-https://rihla.mv}"
  for path in "/up" "/"; do
    code=$(curl -fsS -o /dev/null -w '%{http_code}' --max-time 20 "${URL}${path}" || echo 000)
    log "     ${URL}${path} -> ${code}"
    [[ "$code" =~ ^(200|302)$ ]] || log "     WARN: unexpected status; check the site before walking away"
  done
fi

log "=== deployed ${REMOTE:0:8} ==="

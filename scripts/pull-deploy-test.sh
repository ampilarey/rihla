#!/usr/bin/env bash
# Immediate TEST pull + Laravel deploy for test.rihla.mv
#
# Used by:
#   - POST /api/deploy/test-pull (GitHub Actions on push to main)
#   - scripts/self-update-test.sh (cron fallback)
#
# Optional arg: expected full SHA to deploy (retries fetch briefly if tip lags).
export HOME="${HOME:-/home/rihla}"
set -uo pipefail

export PATH="$HOME/bin:/usr/local/bin:/opt/cpanel/ea-php84/root/usr/bin:/opt/cpanel/composer/bin:/usr/bin:/bin:${PATH:-}"
command -v php >/dev/null || { echo "$(date '+%F %T') php not found on PATH=$PATH"; exit 1; }
command -v git >/dev/null || { echo "$(date '+%F %T') git not found on PATH"; exit 1; }
command -v composer >/dev/null || { echo "$(date '+%F %T') composer not found on PATH=$PATH"; exit 1; }

ROOT="/home/rihla/test.rihla.mv"
LOCK="$HOME/.self-update-test.lock"
EXPECTED_SHA="${1:-}"

echo "$(date '+%F %T') pull-deploy-test starting (HOME=$HOME expected=${EXPECTED_SHA:-none})"

mkdir "$LOCK" 2>/dev/null || {
  echo "$(date '+%F %T') deploy already in progress — skipping"
  exit 0
}
trap 'rmdir "$LOCK"' EXIT

cd "$ROOT" || { echo "$(date '+%F %T') cannot cd to $ROOT"; exit 1; }

fetch_main() {
  git fetch origin main --quiet
}

fetch_main || { echo "$(date '+%F %T') git fetch failed"; exit 1; }

if [[ -n "$EXPECTED_SHA" ]]; then
  echoed_wait=0
  for _ in 1 2 3 4 5 6 7 8 9 10; do
    REMOTE=$(git rev-parse FETCH_HEAD)
    [[ "$REMOTE" == "$EXPECTED_SHA" ]] && break
    if [[ "$echoed_wait" -eq 0 ]]; then
      echo "$(date '+%F %T') waiting for origin/main (${REMOTE:0:8}) to reach ${EXPECTED_SHA:0:8}"
      echoed_wait=1
    fi
    sleep 2
    fetch_main || true
  done
  REMOTE=$(git rev-parse FETCH_HEAD)
  if [[ "$REMOTE" != "$EXPECTED_SHA" ]]; then
    echo "$(date '+%F %T') origin/main tip ${REMOTE:0:8} != expected ${EXPECTED_SHA:0:8} — aborting (deploy only accepts main SHAs)"
    exit 1
  fi
fi

LOCAL=$(git rev-parse HEAD)
REMOTE=$(git rev-parse FETCH_HEAD)
if [[ "$LOCAL" == "$REMOTE" ]]; then
  echo "$(date '+%F %T') already on ${LOCAL:0:8} — nothing to deploy"
  exit 0
fi

echo "$(date '+%F %T') deploying ${LOCAL:0:8} -> ${REMOTE:0:8}"
git merge --ff-only FETCH_HEAD || { echo "$(date '+%F %T') fast-forward failed — manual attention needed"; exit 1; }

if git diff --name-only "$LOCAL" "$REMOTE" | grep -qE '^(composer.lock|composer.json)$'; then
  composer install --no-dev --optimize-autoloader --no-interaction \
    || { echo "$(date '+%F %T') composer install failed"; exit 1; }
else
  # Rebuild the classmap even when dependencies did not change. It maps
  # class => file, so a commit that deletes or moves a PHP class leaves it
  # pointing at a file that is gone; class_exists() then tries to include
  # the missing file and the page fatals. Blade does that for every
  # <x-component> tag, which is how this was found.
  composer dump-autoload --optimize --no-interaction \
    || { echo "$(date '+%F %T') dump-autoload failed"; exit 1; }
fi

php artisan storage:link --force 2>/dev/null \
  || echo "$(date '+%F %T') WARN: storage:link failed"

php artisan migrate --force \
  && php artisan config:cache \
  && php artisan route:cache \
  && php artisan view:clear \
  || { echo "$(date '+%F %T') Laravel deploy steps failed"; exit 1; }

echo "$(date '+%F %T') deploy complete: ${REMOTE:0:8}"

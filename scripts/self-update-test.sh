#!/usr/bin/env bash
# Cron fallback for test.rihla.mv auto-deploy.
#
# Prefer immediate deploy: GitHub Actions calls POST /api/deploy/test-pull
# (see docs/TEST_AUTO_DEPLOY.md). This cron remains the fallback.
#
# Install once (cPanel Terminal):
#   bash /home/rihla/test.rihla.mv/scripts/install-self-update-cron-test.sh
#
# Watch:  tail -f ~/self-update-test.log
export HOME="${HOME:-/home/rihla}"
set -uo pipefail

export PATH="$HOME/bin:/usr/local/bin:/opt/cpanel/ea-php84/root/usr/bin:/opt/cpanel/composer/bin:/usr/bin:/bin:${PATH:-}"
command -v git >/dev/null || { echo "$(date '+%F %T') git not found on PATH"; exit 1; }
command -v curl >/dev/null || { echo "$(date '+%F %T') curl not found on PATH"; exit 1; }

ROOT="/home/rihla/test.rihla.mv"
REPO="ampilarey/rihla"
PULL="$ROOT/scripts/pull-deploy-test.sh"

cd "$ROOT" || { echo "$(date '+%F %T') cannot cd to $ROOT"; exit 1; }

git fetch origin main --quiet || { echo "$(date '+%F %T') git fetch failed"; exit 1; }

LOCAL=$(git rev-parse HEAD)
REMOTE=$(git rev-parse FETCH_HEAD)
[ "$LOCAL" = "$REMOTE" ] && exit 0

# Anonymous API — repo is public. Holds if CI is red/running.
# Every CI check, by name, must be green — site audit. Reading every
# check-run on the commit instead held for ever exactly when the webhook
# deploy (itself a check-run on the same commit) had failed, which is the
# one case this fallback exists for; and a commit polled before GitHub had
# made its check-runs deployed untested.
for NAME in "Tests (PHP 8.3)" "Tests (PHP 8.4)" "Tests (MySQL)" "Static analysis" "Dependency audit" "Committed build is current"; do
  ONE=$(curl -fsS -m 20 -H 'Accept: application/vnd.github+json' \
      "https://api.github.com/repos/${REPO}/commits/${REMOTE}/check-runs?check_name=$(printf '%s' "$NAME" | sed 's/ /%20/g;s/(/%28/g;s/)/%29/g')&per_page=5") \
      || { echo "$(date '+%F %T') GitHub API unreachable — will retry next run"; exit 0; }
  if ! printf '%s' "$ONE" | grep -q '"total_count": *[1-9]'; then
      echo "$(date '+%F %T') ${REMOTE:0:8}: no result yet for ${NAME} — holding."
      exit 0
  fi
  if ! printf '%s' "$ONE" | grep -qE '"conclusion": *"success"'; then
      echo "$(date '+%F %T') ${REMOTE:0:8}: ${NAME} is not green — holding."
      exit 0
  fi
done

if [[ ! -x "$PULL" ]]; then
  chmod +x "$PULL" 2>/dev/null || true
fi

exec bash "$PULL" "$REMOTE"

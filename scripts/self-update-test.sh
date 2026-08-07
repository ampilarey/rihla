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
CHECKS=$(curl -fsS -m 20 -H 'Accept: application/vnd.github+json' \
    "https://api.github.com/repos/${REPO}/commits/${REMOTE}/check-runs?per_page=100") \
    || { echo "$(date '+%F %T') GitHub API unreachable — will retry next run"; exit 0; }

# If no checks exist yet (no CI workflow), deploy anyway (rihla may not have CI).
if printf '%s' "$CHECKS" | grep -q '"total_count": *[1-9]'; then
  if printf '%s' "$CHECKS" | grep -qE '"status": *"(queued|in_progress|pending|waiting)"'; then
      echo "$(date '+%F %T') ${REMOTE:0:8}: CI still running — holding."
      exit 0
  fi
  if printf '%s' "$CHECKS" | grep -qE '"conclusion": *"(failure|cancelled|timed_out|action_required|startup_failure|stale)"'; then
      echo "$(date '+%F %T') ${REMOTE:0:8}: CI not green — holding."
      exit 0
  fi
fi

if [[ ! -x "$PULL" ]]; then
  chmod +x "$PULL" 2>/dev/null || true
fi

exec bash "$PULL" "$REMOTE"

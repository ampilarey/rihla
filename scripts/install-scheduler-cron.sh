#!/usr/bin/env bash
# Install (or refresh) the one-minute Laravel scheduler cron for one site.
# Safe to re-run. §16.12 of docs/WEBSITE_UPGRADE_PLAN.md.
#
# Run once per site in cPanel Terminal:
#   cd /home/rihla/rihla.mv-app && bash scripts/install-scheduler-cron.sh
#   cd /home/rihla/test.rihla.mv && bash scripts/install-scheduler-cron.sh
#
# It installs the line for the directory it is run from, so the same script
# serves production and test without either overwriting the other.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
NAME="$(basename "$ROOT")"
LOG="$HOME/schedule-$NAME.log"

export PATH="$HOME/bin:/usr/local/bin:/opt/cpanel/ea-php84/root/usr/bin:/usr/bin:/bin:$PATH"

PHP="$(command -v php || true)"
if [[ -z "$PHP" ]]; then
  echo "ERROR: php not found on PATH=$PATH"
  exit 1
fi

if [[ ! -f "$ROOT/artisan" ]]; then
  echo "ERROR: $ROOT does not look like the application (no artisan)."
  exit 1
fi

CRON_LINE="* * * * * cd $ROOT && $PHP artisan schedule:run >> $LOG 2>&1"

CURRENT="$(crontab -l 2>/dev/null || true)"
# Only this site's scheduler line is replaced; other sites' lines and the
# test auto-deploy line are kept.
FILTERED="$(printf '%s\n' "$CURRENT" | grep -vF "cd $ROOT && " | sed '/^$/d' || true)"

{
  if [[ -n "$FILTERED" ]]; then
    printf '%s\n' "$FILTERED"
  fi
  echo "$CRON_LINE"
} | crontab -

echo "✓ Scheduler cron installed for $ROOT (every minute):"
crontab -l | grep -F "cd $ROOT && "
echo ""
echo "  Log: tail -f $LOG"
echo "  Within two minutes, 'php artisan rihla:preflight --production' stops warning about the scheduler."

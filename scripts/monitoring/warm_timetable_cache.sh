#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
WARMER="${ROOT_DIR}/scripts/monitoring/warm_timetable_cache.php"
LOCK_FILE="/tmp/podroznik-timetable-warmer.lock"
LOG_FILE="${PODROZNIK_TIMETABLE_WARM_LOG:-/home/ubuntu/podroznik-timetable-warmer.log}"

HOME_DIR="${HOME:-/home/ubuntu}"
ENV_FILE="${PODROZNIK_TIMETABLE_WARM_ENV_FILE:-${PODROZNIK_MONITOR_ENV_FILE:-${HOME_DIR}/.config/podroznik/monitor.env}}"
if [[ -f "$ENV_FILE" ]]; then
  set -a
  # shellcheck disable=SC1090
  . "$ENV_FILE"
  set +a
fi

mkdir -p "$(dirname -- "$LOG_FILE")"

exec 9>"$LOCK_FILE"
if ! flock -n 9; then
  exit 0
fi

timestamp="$(date -Is)"

set +e
output="$(/usr/bin/php "$WARMER" 2>&1)"
status=$?
set -e

if [[ $status -eq 0 ]]; then
  {
    echo "[$timestamp] OK"
    echo "$output"
    echo
  } >>"$LOG_FILE"
  exit 0
fi

{
  echo "[$timestamp] FAIL (exit=$status)"
  echo "$output"
  echo
} >>"$LOG_FILE"

exit "$status"

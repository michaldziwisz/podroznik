#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
CHECK="${ROOT_DIR}/scripts/monitoring/check_upstream.php"
REPORT="${ROOT_DIR}/scripts/monitoring/report_to_sygnalista.php"
LOCK_FILE="/tmp/podroznik-monitor.lock"
PENDING_FILE="/tmp/podroznik-monitor.pending"
STATE_FILE="/tmp/podroznik-monitor.state"
LOG_FILE="${PODROZNIK_MONITOR_LOG:-/home/ubuntu/podroznik-monitor.log}"

TITLE="Monitoring: problem z integracją e‑podroznik.pl"

HOME_DIR="${HOME:-/home/ubuntu}"
ENV_FILE="${PODROZNIK_MONITOR_ENV_FILE:-${HOME_DIR}/.config/podroznik/monitor.env}"
if [[ -f "$ENV_FILE" ]]; then
  set -a
  # shellcheck disable=SC1090
  . "$ENV_FILE"
  set +a
fi

if [[ -z "${SYGNALISTA_APP_CHANNEL:-}" ]]; then
  export SYGNALISTA_APP_CHANNEL="monitoring"
fi

mkdir -p "$(dirname -- "$LOG_FILE")"

exec 9>"$LOCK_FILE"
if ! flock -n 9; then
  exit 0
fi

timestamp="$(date -Is)"

set +e
output="$(/usr/bin/php "$CHECK" 2>&1)"
status=$?
set -e

if [[ $status -eq 0 ]]; then
  if [[ -f "$STATE_FILE" ]]; then
    {
      echo "[$timestamp] RECOVERED"
      cat "$STATE_FILE" 2>/dev/null || true
      echo
    } >>"$LOG_FILE"
    rm -f "$STATE_FILE" || true
  fi
  rm -f "$PENDING_FILE" || true
  exit 0
fi

sleep 20
set +e
output_retry="$(/usr/bin/php "$CHECK" 2>&1)"
status_retry=$?
set -e

if [[ $status_retry -eq 0 ]]; then
  {
    echo "[$timestamp] TRANSIENT_FAIL (first_exit=$status)"
    echo "$output"
    echo
  } >>"$LOG_FILE"
  exit 0
fi

error_kind="$(printf '%s\n' "$output_retry" | awk -F= '/^error_kind=/{print $2; exit}')"
if [[ -z "${error_kind:-}" ]]; then
  error_kind="unknown"
fi

output="$output"$'\n\n'"--- retry ---"$'\n'"$output_retry"
status="$status_retry"

if [[ -f "$STATE_FILE" ]]; then
  exit 0
fi

threshold=2
if [[ "$error_kind" == "parser" ]]; then
  threshold=1
fi

pending_count=0
pending_kind=""
pending_first="$timestamp"
if [[ -f "$PENDING_FILE" ]]; then
  pending_count="$(awk -F= '/^count=/{print $2; exit}' "$PENDING_FILE" 2>/dev/null || true)"
  pending_kind="$(awk -F= '/^kind=/{print $2; exit}' "$PENDING_FILE" 2>/dev/null || true)"
  pending_first="$(awk -F= '/^first=/{print $2; exit}' "$PENDING_FILE" 2>/dev/null || true)"
fi
if [[ -z "${pending_first:-}" ]]; then
  pending_first="$timestamp"
fi
if [[ -z "${pending_count:-}" || ! "$pending_count" =~ ^[0-9]+$ ]]; then
  pending_count=0
fi
if [[ "${pending_kind:-}" != "$error_kind" ]]; then
  pending_count=0
  pending_first="$timestamp"
fi
pending_count=$((pending_count + 1))

{
  echo "count=$pending_count"
  echo "kind=$error_kind"
  echo "first=$pending_first"
  echo "last=$timestamp"
} >"$PENDING_FILE"

if [[ $pending_count -lt $threshold ]]; then
  {
    echo "[$timestamp] PENDING_FAIL (count=$pending_count threshold=$threshold kind=$error_kind)"
    echo "$output"
    echo
  } >>"$LOG_FILE"
  exit 0
fi

rm -f "$PENDING_FILE" || true

description=$(
  cat <<EOF
Automatyczny alert z monitoringu (cron) — wykryto problem z integracją/scrapingiem e‑podroznik.pl.

Czas: $timestamp
Serwer: $(hostname -f 2>/dev/null || hostname)

Wynik checka:
\`\`\`
$output
\`\`\`
EOF
)

{
  echo "[$timestamp] FAIL (exit=$status)"
  echo "$output"
  echo
} >>"$LOG_FILE"

set +e
report_resp="$(printf '%s' "$description" | /usr/bin/php "$REPORT" --title "$TITLE" --description-stdin 2>&1)"
report_status=$?
set -e

if [[ $report_status -ne 0 ]]; then
  {
    echo "[$timestamp] SYGNALISTA_FAIL (exit=$report_status)"
    echo "$report_resp"
    echo
  } >>"$LOG_FILE"
  exit 0
fi

{
  echo "[$timestamp] REPORTED"
  echo "$report_resp"
  echo
} >>"$LOG_FILE"

{
  echo "reported_at=$timestamp"
  echo "$report_resp"
} >"$STATE_FILE"

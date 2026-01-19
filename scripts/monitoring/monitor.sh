#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
CHECK="${ROOT_DIR}/scripts/monitoring/check_upstream.php"
LOCK_FILE="/tmp/podroznik-monitor.lock"
LOG_FILE="${PODROZNIK_MONITOR_LOG:-/home/ubuntu/podroznik-monitor.log}"

REPO="michaldziwisz/podroznik"
LABEL="monitoring"
TITLE="Monitoring: problem z integracją e‑podroznik.pl"

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
  exit 0
fi

{
  echo "[$timestamp] FAIL (exit=$status)"
  echo "$output"
  echo
} >>"$LOG_FILE"

# Ensure label exists (ignore errors if already exists / no perms).
/usr/bin/gh label create "$LABEL" --repo "$REPO" --description "Automatyczne alerty z monitoringu" --color "BFD4F2" >/dev/null 2>&1 || true

existing="$(
  /usr/bin/gh issue list --repo "$REPO" --state open --label "$LABEL" --json number --jq '.[0].number' 2>/dev/null || true
)"

if [[ -n "${existing:-}" ]]; then
  exit 0
fi

body=$(
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

if /usr/bin/gh issue create --repo "$REPO" --title "$TITLE" --label "$LABEL" --body "$body" >/dev/null; then
  exit 0
fi

/usr/bin/gh issue create --repo "$REPO" --title "$TITLE" --body "$body" >/dev/null || true

#!/usr/bin/env bash
# Test domykania zgłoszeń monitoringu (issue_close.sh + gałąź RECOVERED
# w monitor.sh). Uruchamiany na ATRAPIE gh, więc nie dotyka prawdziwego
# GitHuba. Każdy przypadek pozytywny ma parę negatywną.
#
# Uruchomienie: bash scripts/monitoring/test_issue_close.sh
set -uo pipefail

ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
CLOSER="${ROOT_DIR}/scripts/monitoring/issue_close.sh"
MONITOR="${ROOT_DIR}/scripts/monitoring/monitor.sh"

PRACOWNIA="$(mktemp -d)"
trap 'rm -rf "$PRACOWNIA"' EXIT

ok=0
bledy=0

sprawdz() {
  # $1 = opis, $2 = oczekiwane, $3 = otrzymane
  if [[ "$2" == "$3" ]]; then
    ok=$((ok + 1))
    printf 'OK    %s\n' "$1"
  else
    bledy=$((bledy + 1))
    printf 'BLAD  %s\n      oczekiwano: %s\n      otrzymano:  %s\n' "$1" "$2" "$3"
  fi
}

sprawdz_zawiera() {
  # $1 = opis, $2 = szukany fragment, $3 = tekst
  if [[ "$3" == *"$2"* ]]; then
    ok=$((ok + 1))
    printf 'OK    %s\n' "$1"
  else
    bledy=$((bledy + 1))
    printf 'BLAD  %s\n      brak fragmentu: %s\n      w tekscie:      %s\n' "$1" "$2" "$3"
  fi
}

sprawdz_nie_zawiera() {
  # $1 = opis, $2 = fragment ktorego BYC NIE MOZE, $3 = tekst
  if [[ "$3" != *"$2"* ]]; then
    ok=$((ok + 1))
    printf 'OK    %s\n' "$1"
  else
    bledy=$((bledy + 1))
    printf 'BLAD  %s\n      NIE POWINNO byc: %s\n      a jest w:        %s\n' "$1" "$2" "$3"
  fi
}

# --- ATRAPA gh -------------------------------------------------------------
# Zapisuje wywolania do pliku, zwraca stany issues z pliku konfiguracyjnego.
mkdir -p "$PRACOWNIA/bin"
cat >"$PRACOWNIA/bin/gh" <<'ATRAPA'
#!/usr/bin/env bash
# Atrapa gh. STANY: plik "numer=STAN" w $ATRAPA_STANY. Zapisuje wywolania
# do $ATRAPA_WYWOLANIA. --state-open-fail=1 udaje odmowe GitHuba.
set -uo pipefail
printf '%s\n' "$*" >>"${ATRAPA_WYWOLANIA:-/dev/null}"

stan_issue() {
  local nr="$1"
  local linia
  linia="$(grep -E "^${nr}=" "${ATRAPA_STANY:-/dev/null}" 2>/dev/null | head -1)"
  [[ -n "$linia" ]] && printf '%s' "${linia#*=}"
}

if [[ "${1:-}" == "issue" && "${2:-}" == "view" ]]; then
  nr="${3:-}"
  s="$(stan_issue "$nr")"
  [[ -z "$s" ]] && exit 1
  printf '%s\n' "$s"
  exit 0
fi

if [[ "${1:-}" == "issue" && "${2:-}" == "close" ]]; then
  if [[ "${ATRAPA_CLOSE_FAIL:-0}" == "1" ]]; then
    echo "atrapa: odmowa" >&2
    exit 1
  fi
  exit 0
fi

if [[ "${1:-}" == "issue" && "${2:-}" == "list" ]]; then
  cat "${ATRAPA_LISTA:-/dev/null}" 2>/dev/null
  exit 0
fi

exit 0
ATRAPA
chmod +x "$PRACOWNIA/bin/gh"

export ATRAPA_STANY="$PRACOWNIA/stany.txt"
export ATRAPA_WYWOLANIA="$PRACOWNIA/wywolania.txt"
export ATRAPA_LISTA="$PRACOWNIA/lista.json"
export PODROZNIK_GH_BIN="$PRACOWNIA/bin/gh"
export PODROZNIK_ISSUE_REPO="atrapa/podroznik"

echo "=== 1. numer issue z pliku stanu ==="

# Plik stanu w formacie, ktory realnie zapisuje monitor.sh: linia reported_at,
# potem CALA odpowiedz sygnalisty.
cat >"$PRACOWNIA/monitor.state" <<'STAN'
reported_at=2026-08-27T21:20:01+02:00
{
    "ok": true,
    "reportId": "4b7c2b0e-0867-41a1-8f76-5848c7979914",
    "issue": {
        "url": "https://api.github.com/repos/michaldziwisz/podroznik/issues/41",
        "id": 5271319666,
        "number": 41,
        "state": "open",
        "user": { "id": 13209868 }
    }
}
STAN

printf '41=OPEN\n' >"$ATRAPA_STANY"
: >"$ATRAPA_WYWOLANIA"
wynik="$("$CLOSER" --state-file "$PRACOWNIA/monitor.state" --reason "powrot" 2>&1)"
sprawdz "otwarte issue z pliku stanu zostaje zamkniete" "CLOSED #41" "$wynik"
sprawdz_zawiera "wywolano gh issue close z numerem 41" "issue close 41" "$(cat "$ATRAPA_WYWOLANIA")"

# PARA NEGATYWNA: numer musi pochodzic z pola issue.number, a NIE z pierwszej
# liczby w JSON (tam wczesniej stoi id=5271319666 i user.id=13209868).
sprawdz_nie_zawiera "nie zamyka po issue.id" "issue close 5271319666" "$(cat "$ATRAPA_WYWOLANIA")"
sprawdz_nie_zawiera "nie zamyka po user.id" "issue close 13209868" "$(cat "$ATRAPA_WYWOLANIA")"

echo "=== 2. issue juz zamkniete nie dostaje drugiego komentarza ==="
printf '41=CLOSED\n' >"$ATRAPA_STANY"
: >"$ATRAPA_WYWOLANIA"
wynik="$("$CLOSER" --state-file "$PRACOWNIA/monitor.state" --reason "powrot" 2>&1)"
sprawdz "zamkniete issue jest pomijane" "SKIP #41 (stan=CLOSED, nie jest otwarte)" "$wynik"
sprawdz_nie_zawiera "nie wolano gh issue close" "issue close" "$(cat "$ATRAPA_WYWOLANIA")"

echo "=== 3. --dry-run niczego nie zamyka ==="
printf '41=OPEN\n' >"$ATRAPA_STANY"
: >"$ATRAPA_WYWOLANIA"
wynik="$("$CLOSER" --state-file "$PRACOWNIA/monitor.state" --reason "powrot" --dry-run 2>&1)"
sprawdz_zawiera "dry-run melduje zamiar" "DRY-RUN" "$wynik"
sprawdz_zawiera "dry-run wskazuje numer" "#41" "$wynik"
sprawdz_nie_zawiera "dry-run nie wola gh issue close" "issue close" "$(cat "$ATRAPA_WYWOLANIA")"

echo "=== 4. plik stanu bez numeru ==="
printf 'reported_at=2026-08-27T21:20:01+02:00\n{"ok":true,"reportId":"x"}\n' >"$PRACOWNIA/bez_numeru.state"
: >"$ATRAPA_WYWOLANIA"
printf '[]\n' >"$ATRAPA_LISTA"
wynik="$("$CLOSER" --state-file "$PRACOWNIA/bez_numeru.state" --reason "powrot" 2>&1)"
sprawdz_zawiera "brak numeru bez --title konczy sie spokojnie" "nie mam czego" "$wynik"
sprawdz_nie_zawiera "brak numeru nie zamyka niczego" "issue close" "$(cat "$ATRAPA_WYWOLANIA")"

# Droga awaryjna po restarcie maszyny: numeru nie ma, ale tytul znamy.
cat >"$ATRAPA_LISTA" <<'LISTA'
[{"number": 41, "title": "Monitoring: problem"},
 {"number": 13, "title": "Zupelnie inne zgloszenie"}]
LISTA
printf '41=OPEN\n13=OPEN\n' >"$ATRAPA_STANY"
: >"$ATRAPA_WYWOLANIA"
wynik="$("$CLOSER" --state-file "$PRACOWNIA/bez_numeru.state" --title "Monitoring: problem" --reason "powrot" 2>&1)"
sprawdz_zawiera "brak numeru + tytul przechodzi na domykanie po tytule" "CLOSED #41" "$wynik"
sprawdz_nie_zawiera "domykanie po tytule NIE rusza obcego zgloszenia" "issue close 13" "$(cat "$ATRAPA_WYWOLANIA")"

echo "=== 5. tytul musi byc DOKLADNY, nie czesciowy ==="
cat >"$ATRAPA_LISTA" <<'LISTA'
[{"number": 41, "title": "Monitoring: problem"},
 {"number": 42, "title": "Monitoring: problem z czyms innym"}]
LISTA
printf '41=OPEN\n42=OPEN\n' >"$ATRAPA_STANY"
: >"$ATRAPA_WYWOLANIA"
wynik="$("$CLOSER" --stale --title "Monitoring: problem" --reason "powrot" 2>&1)"
sprawdz_zawiera "dokladny tytul zamyka wlasciwe" "CLOSED #41" "$wynik"
sprawdz_nie_zawiera "tytul dluzszy NIE jest dopasowaniem" "issue close 42" "$(cat "$ATRAPA_WYWOLANIA")"

echo "=== 6. odmowa GitHuba daje kod 3, nie ciche 0 ==="
printf '41=OPEN\n' >"$ATRAPA_STANY"
ATRAPA_CLOSE_FAIL=1 "$CLOSER" --number 41 --reason "powrot" >"$PRACOWNIA/out6" 2>&1
sprawdz "kod wyjscia przy odmowie" "3" "$?"
sprawdz_zawiera "melduje FAILED" "FAILED #41" "$(cat "$PRACOWNIA/out6")"

echo "=== 7. brak gh w PATH = kod 2, bez udawania sukcesu ==="
PODROZNIK_GH_BIN="$PRACOWNIA/bin/nie-ma-takiego-gh" "$CLOSER" --number 41 >"$PRACOWNIA/out7" 2>&1
sprawdz "kod wyjscia przy braku gh" "2" "$?"

echo "=== 8. bledne uzycie nie konczy sie kodem 0 ==="
"$CLOSER" --stale >"$PRACOWNIA/out8" 2>&1
sprawdz "--stale bez --title" "1" "$?"
"$CLOSER" >"$PRACOWNIA/out8b" 2>&1
sprawdz "brak trybu" "1" "$?"
"$CLOSER" --number >"$PRACOWNIA/out8c" 2>&1
sprawdz "--number bez wartosci" "1" "$?"

echo "=== 9. monitor.sh: RECOVERED wola domykanie i przenosi stan z /tmp ==="
# Atrapa php: pierwszy przebieg ma udac AWARIE, drugi POWROT do sprawnosci.
cat >"$PRACOWNIA/bin/php" <<'ATRAPAPHP'
#!/usr/bin/env bash
if [[ "${ATRAPA_PHP_OK:-0}" == "1" ]]; then
  echo "OK"; echo "elapsed_ms=1"; exit 0
fi
echo "FAILED" >&2; echo "elapsed_ms=1" >&2; echo "error_kind=network" >&2; exit 2
ATRAPAPHP
chmod +x "$PRACOWNIA/bin/php"

# monitor.sh wola /usr/bin/php twardo, wiec test sprawdza SAMA GALAZ RECOVERED:
# podkladamy plik stanu w starym miejscu (/tmp) i wolamy monitor.sh w trybie OK.
export PODROZNIK_MONITOR_STATE_DIR="$PRACOWNIA/state"
export PODROZNIK_MONITOR_LOG="$PRACOWNIA/monitor.log"

if [[ -x /usr/bin/php ]]; then
  # Sprawdzenie samego przeniesienia stanu ze starej lokalizacji.
  legacy="/tmp/podroznik-monitor.state"
  if [[ ! -e "$legacy" ]]; then
    cp "$PRACOWNIA/monitor.state" "$legacy"
    rm -rf "$PRACOWNIA/state"
    printf '41=OPEN\n' >"$ATRAPA_STANY"
    : >"$ATRAPA_WYWOLANIA"
    # PATH z atrapa gh, zeby monitor.sh nie dotknal prawdziwego GitHuba.
    PATH="$PRACOWNIA/bin:$PATH" PODROZNIK_GH_BIN="$PRACOWNIA/bin/gh" \
      bash "$MONITOR" >/dev/null 2>&1
    log="$(cat "$PRACOWNIA/monitor.log" 2>/dev/null || true)"
    if [[ "$log" == *"RECOVERED"* ]]; then
      sprawdz_zawiera "monitor.sh przy RECOVERED zapisuje wynik domykania" "close:" "$log"
      sprawdz_zawiera "monitor.sh zamknal issue #41" "CLOSED #41" "$log"
      sprawdz "stary plik stanu z /tmp zostal przeniesiony" "0" "$([[ -e "$legacy" ]] && echo 1 || echo 0)"
    else
      printf 'POMINIETO 9 (upstream niedostepny albo check zwrocil FAIL, brak galezi RECOVERED)\n'
    fi
    rm -f "$legacy"
  else
    printf 'POMINIETO 9 (w /tmp lezy prawdziwy plik stanu produkcji)\n'
  fi
else
  printf 'POMINIETO 9 (brak /usr/bin/php)\n'
fi

echo
printf 'WYNIK: %d OK, %d bledow\n' "$ok" "$bledy"
[[ $bledy -eq 0 ]] || exit 1

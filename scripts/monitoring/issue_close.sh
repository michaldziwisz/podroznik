#!/usr/bin/env bash
# Domykanie zgłoszeń monitoringu: zamyka issue na GitHubie, gdy monitoring
# wykrył powrót do sprawności.
#
# Powód istnienia: monitor.sh umiał ZAŁOŻYĆ issue (przez sygnalistę) i umiał
# rozpoznać RECOVERED, ale nie miał drugiej połowy pętli. W efekcie lista
# otwartych zgłoszeń mówiła „kiedyś coś się zepsuło”, a nie „coś jest zepsute
# teraz”.
#
# Użycie:
#   issue_close.sh --state-file PLIK --reason "tekst"      # zamknij z pliku stanu
#   issue_close.sh --number 41 --reason "tekst"            # zamknij konkretny numer
#   issue_close.sh --stale --title "TYTUŁ" --reason "..."  # posprzątaj zaległe
#   dowolny z powyższych + --dry-run                       # tylko pokaż, nie zamykaj
#
# Kody wyjścia: 0 = zamknięte albo nie było czego zamykać, 1 = błąd wołającego,
# 2 = gh niedostępny, 3 = GitHub odrzucił operację.
set -uo pipefail

REPO="${PODROZNIK_ISSUE_REPO:-michaldziwisz/podroznik}"
GH_BIN="${PODROZNIK_GH_BIN:-gh}"

mode=""
state_file=""
issue_number=""
reason=""
title=""
dry_run=0

blad_uzycia() {
  printf 'issue_close.sh: %s\n' "$1" >&2
  exit 1
}

wymaga_wartosci() {
  # $1 = nazwa opcji, $2 = liczba pozostałych argumentów
  if [[ "$2" -lt 2 ]]; then
    blad_uzycia "opcja $1 wymaga wartości"
  fi
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --state-file)
      wymaga_wartosci "$1" "$#"
      mode="state"
      state_file="$2"
      shift 2
      ;;
    --number)
      wymaga_wartosci "$1" "$#"
      mode="number"
      issue_number="$2"
      shift 2
      ;;
    --stale)
      mode="stale"
      shift
      ;;
    --title)
      wymaga_wartosci "$1" "$#"
      title="$2"
      shift 2
      ;;
    --reason)
      wymaga_wartosci "$1" "$#"
      reason="$2"
      shift 2
      ;;
    --dry-run)
      dry_run=1
      shift
      ;;
    *)
      blad_uzycia "nieznany argument: $1"
      ;;
  esac
done

if [[ -z "$mode" ]]; then
  blad_uzycia "podaj --state-file, --number albo --stale"
fi

if [[ -z "$reason" ]]; then
  reason="Monitoring: powrót do sprawności."
fi

# Numer issue z pliku stanu. Plik ma linię reported_at=... a potem CAŁĄ odpowiedź
# sygnalisty jako JSON: {"ok":true,"reportId":"...","issue":{"number":41,...}}.
# Czytamy przez python3, bo grep po "number" trafiłby też w inne pola
# GitHubowej odpowiedzi (np. numery w adresach albo id użytkownika).
numer_z_pliku_stanu() {
  local plik="$1"
  [[ -f "$plik" ]] || return 1
  python3 - "$plik" <<'PYTHON'
import json
import sys

try:
    tekst = open(sys.argv[1], encoding="utf-8", errors="replace").read()
except OSError:
    sys.exit(1)

poczatek = tekst.find("{")
if poczatek < 0:
    sys.exit(1)
try:
    obiekt, _ = json.JSONDecoder().raw_decode(tekst[poczatek:])
except ValueError:
    sys.exit(1)

issue = obiekt.get("issue")
if not isinstance(issue, dict):
    sys.exit(1)
numer = issue.get("number")
if not isinstance(numer, int):
    sys.exit(1)
print(numer)
PYTHON
}

# Numery otwartych issues o DOKŁADNIE tym tytule. Równość, nie dopasowanie
# częściowe — inaczej dałoby się zamknąć cudze zgłoszenie o podobnej nazwie.
# UWAGA: listę przekazujemy plikiem, a nie potokiem. Heredoc z programem
# Pythona zajmuje stdin, więc potok „gh | python3 - <<PYTHON” po cichu podaje
# skryptowi pusty wsad i nic się nie zamyka.
numery_po_tytule() {
  local szukany="$1"
  local plik_listy
  plik_listy="$(mktemp)"
  if ! "$GH_BIN" issue list -R "$REPO" --state open --limit 100 --json number,title \
    >"$plik_listy" 2>/dev/null; then
    rm -f "$plik_listy"
    return 0
  fi

  python3 - "$plik_listy" "$szukany" <<'PYTHON'
import json
import sys

try:
    with open(sys.argv[1], encoding="utf-8", errors="replace") as uchwyt:
        dane = json.load(uchwyt)
except (OSError, ValueError):
    sys.exit(0)

szukany = sys.argv[2]
if not isinstance(dane, list):
    sys.exit(0)
for wpis in dane:
    if isinstance(wpis, dict) and wpis.get("title") == szukany:
        numer = wpis.get("number")
        if isinstance(numer, int):
            print(numer)
PYTHON

  rm -f "$plik_listy"
}

# Zamknięcie JEDNEGO issue. Sprawdza wcześniej, czy jest otwarte — zamykanie
# już zamkniętego dorzucałoby mylący komentarz przy każdym przebiegu.
zamknij_numer() {
  local numer="$1"
  local powod="$2"

  if ! [[ "$numer" =~ ^[0-9]+$ ]]; then
    printf 'SKIP numer=%s (nie jest liczbą)\n' "$numer"
    return 0
  fi

  local stan
  stan="$("$GH_BIN" issue view "$numer" -R "$REPO" --json state --jq .state 2>/dev/null)"
  if [[ -z "$stan" ]]; then
    printf 'SKIP #%s (nie udało się odczytać stanu)\n' "$numer"
    return 0
  fi
  if [[ "$stan" != "OPEN" ]]; then
    printf 'SKIP #%s (stan=%s, nie jest otwarte)\n' "$numer" "$stan"
    return 0
  fi

  if [[ $dry_run -eq 1 ]]; then
    printf 'DRY-RUN zamknąłbym #%s: %s\n' "$numer" "$powod"
    return 0
  fi

  if "$GH_BIN" issue close "$numer" -R "$REPO" --comment "$powod" >/dev/null 2>&1; then
    printf 'CLOSED #%s\n' "$numer"
    return 0
  fi

  printf 'FAILED #%s (gh issue close zwrócił błąd)\n' "$numer"
  return 3
}

if ! command -v "$GH_BIN" >/dev/null 2>&1; then
  printf 'issue_close.sh: brak gh w PATH (%s) — nie zamykam niczego\n' "$GH_BIN" >&2
  exit 2
fi

if [[ "$mode" == "state" ]]; then
  numer="$(numer_z_pliku_stanu "$state_file" 2>/dev/null)"
  if [[ -n "$numer" ]]; then
    zamknij_numer "$numer" "$reason"
    exit $?
  fi
  # Brak numeru to normalna sytuacja po restarcie maszyny (stan bywał w /tmp).
  # Wtedy jedyne, co mamy, to tytuł — droga awaryjna, nie błąd.
  if [[ -n "$title" ]]; then
    printf 'brak numeru w pliku stanu, przechodzę na domykanie po tytule\n'
    mode="stale"
  else
    printf 'brak numeru w pliku stanu i brak --title — nie mam czego zamknąć\n'
    exit 0
  fi
fi

if [[ "$mode" == "number" ]]; then
  zamknij_numer "$issue_number" "$reason"
  exit $?
fi

if [[ "$mode" == "stale" ]]; then
  if [[ -z "$title" ]]; then
    blad_uzycia "--stale wymaga --title"
  fi

  numery="$(numery_po_tytule "$title")"
  if [[ -z "$numery" ]]; then
    printf 'brak otwartych zgłoszeń o tytule: %s\n' "$title"
    exit 0
  fi

  rc=0
  while read -r numer; do
    [[ -n "$numer" ]] || continue
    zamknij_numer "$numer" "$reason" || rc=$?
  done <<<"$numery"
  exit $rc
fi

blad_uzycia "nieobsłużony tryb: $mode"

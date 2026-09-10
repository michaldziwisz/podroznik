#!/usr/bin/env bash
# Kontrola waznosci testow: psujemy kod produkcyjny w kilku miejscach, KAZDE
# OSOBNO, i sprawdzamy, ze zestaw testow to lapie. Test, ktory przechodzi
# takze na zepsutym kodzie, jest atrapa.
#
# Skrypt przywraca oryginalne pliki po kazdej probie (i przy przerwaniu).
set -uo pipefail

ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

KOPIA="$(mktemp -d)"
cp src/TimetableRules.php src/TimetableParser.php src/ResultsParser.php src/Input.php "$KOPIA/"
przywroc() { cp "$KOPIA"/*.php src/ 2>/dev/null || true; }
trap 'przywroc; rm -rf "$KOPIA"' EXIT

zlapane=0
przegapione=0

# $1 = opis uszkodzenia, $2 = plik, $3 = python: stary tekst, $4 = nowy tekst
proba() {
  local opis="$1" plik="$2" stary="$3" nowy="$4"
  przywroc
  python3 - "$plik" "$stary" "$nowy" <<'PYTHON'
import sys
plik, stary, nowy = sys.argv[1], sys.argv[2], sys.argv[3]
tekst = open(plik, encoding="utf-8").read()
if stary not in tekst:
    print("NIE ZNALEZIONO WZORCA:", stary[:60], file=sys.stderr)
    sys.exit(2)
open(plik, "w", encoding="utf-8").write(tekst.replace(stary, nowy, 1))
PYTHON
  if [[ $? -ne 0 ]]; then
    printf 'POMINIETO (wzorzec nieaktualny): %s\n' "$opis"
    return
  fi

  # Limit czasu jest tu konieczny: czesc uszkodzen (np. zerwane zawijanie
  # licznika dni) daje petle bez konca. Zawieszenie MUSI liczyc sie jako
  # wykryty blad, nie jako sukces — inaczej najgrozniejsza klasa regresji
  # przeszlaby jako "przegapione".
  wynik="$(timeout 120 php tests/run.php 2>&1 | grep -E "^PODSUMOWANIE" || true)"
  timeout 120 php tests/run.php >/dev/null 2>&1
  kod=$?
  if [[ $kod -eq 0 ]]; then
    przegapione=$((przegapione + 1))
    printf 'PRZEGAPIONE  %s\n             (%s)\n' "$opis" "$wynik"
  elif [[ $kod -eq 124 ]]; then
    zlapane=$((zlapane + 1))
    printf 'ZLAPANE      %s\n             (testy nie skonczyly sie w limicie czasu - petla)\n' "$opis"
  else
    zlapane=$((zlapane + 1))
    printf 'ZLAPANE      %s\n             (%s)\n' "$opis" "$wynik"
  fi
  przywroc
}

echo "=== KONTROLA WAZNOSCI: psucie kodu produkcyjnego ==="

proba "filtr godzinowy: granica dolna wylaczna zamiast wlacznej" \
  "src/TimetableRules.php" \
  '        if ($fromMin !== null && $t < $fromMin) {' \
  '        if ($fromMin !== null && $t <= $fromMin) {'

proba "swieta: usuniete swieta ruchome (Wielkanoc, Boze Cialo)" \
  "src/TimetableRules.php" \
  '        $easter = self::easterSunday($year);' \
  '        $cache[$year] = $set; return $set; $easter = self::easterSunday($year);'

proba "zakres dat: koniec okresu wylaczny zamiast wlacznego" \
  "src/TimetableRules.php" \
  '            if ($start !== '"''"' && $end !== '"''"' && $dateYmd >= $start && $dateYmd <= $end) {' \
  '            if ($start !== '"''"' && $end !== '"''"' && $dateYmd >= $start && $dateYmd < $end) {'

# UWAGA na uszkodzenia POZORNE. Pierwsza wersja tej proby zmieniala prog
# zawijania licznika dni (8 -> 99) i wygladala jak niewykryta regresja.
# W rzeczywistosci byla ROWNOWAZNA: licznik zawijal sie pozniej, dokladal
# nieistniejace dni 8-98, ktore nigdy nie pasuja do dnia tygodnia (1-7),
# i konczyl na tym samym zbiorze. Testy nie mialy czego wykryc.
# Wniosek ogolny: gdy kontrola waznosci pokazuje "przegapione", NAJPIERW
# sprawdz pomiarem, czy uszkodzenie w ogole zmienia wynik - inaczej dopiszesz
# test na rzecz, ktora nie jest bledem.
proba "zakres dni tygodnia nie zawija sie przez niedziele (pt - pn czytane jako pn - pt)" \
  "src/TimetableRules.php" \
  '        if ($start === $end) {
            return [$start];
        }' \
  '        if ($start === $end) {
            return [$start];
        }
        return range(min($start, $end), max($start, $end));'

proba "parser rozkladu: validity brane z konca zamiast z poczatku" \
  "src/TimetableParser.php" \
  '        $validity = $infoItems[0] ?? '"''"';' \
  '        $validity = $infoItems[count($infoItems) - 1] ?? '"''"';'

proba "parser wynikow: brak wykrywania odnosnika wczesniej" \
  "src/ResultsParser.php" \
  "btnSearchForEarlier" \
  "btnSearchForEarlierNIEISTNIEJE"

proba "Input: data nie jest sprawdzana z kalendarzem (31 lutego przechodzi)" \
  "src/Input.php" \
  '            if (!checkdate($mo, $d, $y)) {
                return null;
            }
            return sprintf('"'"'%04d-%02d-%02d'"'"', $y, $mo, $d);
        }

        if (preg_match('"'"'/^(\\d{1,2})\\.(\\d{1,2})\\.(\\d{4})$/'"'"', $date, $m)) {' \
  '            return sprintf('"'"'%04d-%02d-%02d'"'"', $y, $mo, $d);
        }

        if (preg_match('"'"'/^(\\d{1,2})\\.(\\d{1,2})\\.(\\d{4})$/'"'"', $date, $m)) {'

proba "Input: godzina 24:00 przyjmowana" \
  "src/Input.php" \
  '            if ($h < 0 || $h > 23 || $min < 0 || $min > 59) {
                return null;
            }
            return sprintf('"'"'%02d:%02d'"'"', $h, $min);
        }

        if (preg_match('"'"'/^\\d{1,4}$/'"'"', $time)) {' \
  '            if ($h < 0 || $h > 24 || $min < 0 || $min > 59) {
                return null;
            }
            return sprintf('"'"'%02d:%02d'"'"', $h, $min);
        }

        if (preg_match('"'"'/^\\d{1,4}$/'"'"', $time)) {'

echo
printf 'WYNIK KONTROLI: zlapane %d, przegapione %d\n' "$zlapane" "$przegapione"
if [[ $przegapione -gt 0 ]]; then
  echo "Sa uszkodzenia, ktorych testy NIE wykrywaja - zestaw wymaga uzupelnienia."
  exit 1
fi
echo "Kazde wprowadzone uszkodzenie zostalo wykryte."

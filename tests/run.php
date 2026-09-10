#!/usr/bin/env php
<?php
// Uruchamia wszystkie testy projektu i zwraca jeden kod wyjścia.
//
// Kod 0 tylko wtedy, gdy KAŻDY plik testowy przeszedł ORAZ wykonała się
// co najmniej jedna asercja. Zielono bez liczby asercji znaczyłoby „nic nie
// biegło”, a to najgorszy możliwy wynik testów: wygląda jak sukces.
//
// Uruchomienie: php tests/run.php
declare(strict_types=1);

$katalog = __DIR__;
$pliki = glob($katalog . '/test_*.php') ?: [];
sort($pliki);

if ($pliki === []) {
    fwrite(STDERR, "Nie znaleziono żadnego pliku test_*.php w {$katalog}\n");
    exit(1);
}

$php = PHP_BINARY;
$oblane = [];
$sumaAsercji = 0;

foreach ($pliki as $plik) {
    $nazwa = basename($plik);
    echo str_repeat('=', 70) . "\n";
    echo "PLIK: {$nazwa}\n";
    echo str_repeat('=', 70) . "\n";

    $wyjscie = [];
    $kod = 0;
    exec(escapeshellarg($php) . ' ' . escapeshellarg($plik) . ' 2>&1', $wyjscie, $kod);
    $tekst = implode("\n", $wyjscie);
    echo $tekst . "\n";

    // Liczbę asercji czytamy z podsumowania, żeby móc odróżnić „przeszło”
    // od „nic się nie wykonało”.
    if (preg_match('/WYNIK:\s*(\d+)\s+asercji zdanych/u', $tekst, $m)) {
        $sumaAsercji += (int)$m[1];
    }

    if ($kod !== 0) {
        $oblane[] = $nazwa;
    }
}

echo str_repeat('=', 70) . "\n";
printf("PODSUMOWANIE: plików %d, asercji zdanych %d, plików oblanych %d\n", count($pliki), $sumaAsercji, count($oblane));

if ($oblane !== []) {
    echo "OBLANE PLIKI:\n";
    foreach ($oblane as $p) {
        echo "  - {$p}\n";
    }
    exit(1);
}

if ($sumaAsercji === 0) {
    echo "UWAGA: nie wykonała się ani jedna asercja — traktuję to jako błąd.\n";
    exit(1);
}

echo "WSZYSTKO ZIELONE\n";
exit(0);

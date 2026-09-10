#!/usr/bin/env php
<?php
// Zbieracz probek HTML z e-podroznik.pl do testow parserow.
//
// Po co: parsery zyja wylacznie z ksztaltu CUDZEGO HTML-a, ktory moze sie
// zmienic bez ostrzezenia. Test na zywym upstreamie nie jest testem — bylby
// wolny, kruchy i milczalby o regresji, gdy serwis padnie. Dlatego probki
// utrwalamy na dysku raz i testujemy parsery offline.
//
// Uzycie: php scripts/dev/collect_fixtures.php [katalog]
// Domyslnie zapisuje do tests/fixtures/.
declare(strict_types=1);

if (PHP_SAPI === 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    $savePath = sys_get_temp_dir() . '/podroznik-fixtures-sessions';
    if (!is_dir($savePath)) {
        @mkdir($savePath, 0o700, true);
    }
    if (is_dir($savePath) && is_writable($savePath)) {
        ini_set('session.save_path', $savePath);
    }
    session_id('podroznik-fixtures');
}

require __DIR__ . '/../../src/bootstrap.php';

use TyfloPodroznik\EpodroznikClient;

$outDir = $argv[1] ?? (__DIR__ . '/../../tests/fixtures');
if (!is_dir($outDir) && !@mkdir($outDir, 0o755, true)) {
    fwrite(STDERR, "Nie udalo sie utworzyc katalogu: {$outDir}\n");
    exit(1);
}

function zapisz(string $sciezka, string $tresc): void
{
    $bajty = file_put_contents($sciezka, $tresc);
    if ($bajty === false) {
        fwrite(STDERR, "ZAPIS PADL: {$sciezka}\n");
        exit(1);
    }
    printf("zapisano %s (%d B)\n", basename($sciezka), $bajty);
}

/**
 * Usuwa z probki identyfikatory sesji. Probki ida do publicznego repozytorium,
 * a odpowiedzi serwisu zawieraja jsessionid, tabToken i vTok — czyli dane
 * jednorazowej sesji osoby, ktora probke zebrala.
 *
 * Podmieniamy je na wartosci ZASTEPCZE o tym samym KSZTALCIE (ta sama dlugosc
 * i alfabet), zamiast wycinac: parsery czytaja z tych adresow odnosniki
 * „wczesniej/pozniej”, wiec puste miejsce zmienilo by to, co test sprawdza.
 */
function anonimizuj(string $html): string
{
    $wzorce = [
        '/(tabToken=)[A-Za-z0-9]+/' => 'ZASTEPCZYTABTOKEN0000000000000000',
        '/(vTok=)[A-Za-z0-9]+/' => 'ZASTEPCZYVTOK00000000000000000000',
        '/(;jsessionid=)[A-Za-z0-9.!_-]+/i' => 'ZASTEPCZYJSESSIONID',
        '/(JSESSIONID=)[A-Za-z0-9.!_-]+/i' => 'ZASTEPCZYJSESSIONID',
    ];

    foreach ($wzorce as $wzorzec => $zastepnik) {
        $html = preg_replace_callback(
            $wzorzec,
            static function (array $m) use ($zastepnik): string {
                // Zachowujemy dlugosc oryginalu, zeby probka pozostala
                // realistyczna, ale wartosc juz niczego nie otwiera.
                $dlugoscOryginalu = mb_strlen($m[0]) - mb_strlen($m[1]);
                $wartosc = substr(str_repeat($zastepnik, 4), 0, max(1, $dlugoscOryginalu));
                return $m[1] . $wartosc;
            },
            $html
        ) ?? $html;
    }

    return $html;
}

$client = EpodroznikClient::fromSession();

// 1. Rozklad z przystanku (Sieradz — ten sam, ktorego uzywa monitoring, bo
//    odpowiada szybciej niz wielkie wezly).
$stopId = '103250';
$html = $client->getGeneralTimetableStop($stopId, forceRefresh: true);
zapisz($outDir . '/timetable_sieradz.html', anonimizuj($html));

// 2. Wyniki wyszukiwania polaczen.
$from = 'Warszawa';
$to = 'Kutno';
$resolve = static function (EpodroznikClient $c, string $q, string $kind): string {
    $resp = $c->suggest($q, $kind, 'CITIES');
    foreach (($resp['suggestions'] ?? []) as $s) {
        if (is_array($s) && isset($s['placeDataString']) && is_string($s['placeDataString']) && $s['placeDataString'] !== '') {
            return $s['placeDataString'];
        }
    }
    throw new RuntimeException("brak placeDataString dla {$q}");
};

$searchHtml = $client->search([
    'fromV' => $resolve($client, $from, 'SOURCE'),
    'toV' => $resolve($client, $to, 'DESTINATION'),
    'fromQuery' => $from,
    'toQuery' => $to,
    'date' => date('Y-m-d'),
    'omitTime' => true,
]);
zapisz($outDir . '/search_warszawa_kutno.html', anonimizuj($searchHtml));

// 3. Surowa odpowiedz podpowiedzi (JSON, nie HTML) — do testu warstwy wyboru.
$suggest = $client->suggest('Krak', 'SOURCE', 'ALL');
zapisz(
    $outDir . '/suggest_krak.json',
    json_encode($suggest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

echo "gotowe\n";

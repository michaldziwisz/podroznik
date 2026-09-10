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

/**
 * Przygotowuje probke do zapisania w PUBLICZNYM repozytorium.
 *
 * Odpowiedzi e-podroznik.pl niosa trzy rodzaje rzeczy, ktorych nie chcemy
 * commitowac, a ktore parserom sa NIEPOTRZEBNE:
 *   1. cudze klucze uslug (np. klucz Google Maps w adresie skryptu),
 *   2. identyfikatory naszej jednorazowej sesji (tabToken, vTok, jsessionid),
 *   3. kilkaset kilobajtow JavaScriptu, ktory i tak nigdy nie jest wykonywany.
 *
 * Dlatego NAJPIERW wycinamy w calosci <script>, <style> i <noscript> (tam
 * siedzi zdecydowana wiekszosc jednego i drugiego), a POTEM anonimizujemy to,
 * co zostalo w samym HTML-u: atrybuty formularzy i adresy odnosnikow.
 *
 * Wycinanie skryptow jest bezpieczne, bo parsery czytaja wylacznie strukture
 * dokumentu (XPath po klasach i identyfikatorach), nigdy kodu strony.
 */
function przygotuj_probke(string $html): string
{
    $html = usun_skrypty($html);
    return anonimizuj($html);
}

function usun_skrypty(string $html): string
{
    foreach (['script', 'style', 'noscript'] as $znacznik) {
        $html = preg_replace(
            '#<' . $znacznik . '\b[^>]*>.*?</' . $znacznik . '\s*>#is',
            '<!-- ' . $znacznik . ' usuniety przy przygotowaniu probki -->',
            $html
        ) ?? $html;
        // Znaczniki samozamykajace i te bez domkniecia (np. <script src=... />).
        $html = preg_replace(
            '#<' . $znacznik . '\b[^>]*/?>#is',
            '<!-- ' . $znacznik . ' usuniety przy przygotowaniu probki -->',
            $html
        ) ?? $html;
    }

    return $html;
}

/**
 * Podmienia identyfikatory sesji na wartosci ZASTEPCZE o tym samym KSZTALCIE
 * (ta sama dlugosc i alfabet), zamiast je wycinac: parsery czytaja z adresow
 * odnosniki „wczesniej/pozniej”, wiec puste miejsce zmienilo by to, co test
 * sprawdza.
 *
 * PULAPKA, NA KTOREJ SIE PRZEJECHALEM: token wystepuje w KILKU formach, nie
 * tylko w adresie. Pierwsza wersja lapala wylacznie „tabToken=” i przepuscila
 * `EPodroznik.setTabToken("...")`, `<input name="tabToken" value="...">` oraz
 * `data: {tabToken: '...'}`. Dlatego anonimizacja konczy sie SIATKA
 * BEZPIECZENSTWA: po podmianie znanych form podmieniamy jeszcze KAZDE
 * pozostale wystapienie samej wartosci tokena, gdziekolwiek by nie stalo.
 */
function anonimizuj(string $html): string
{
    $zastepniki = [
        'tabToken' => 'ZASTEPCZYTABTOKEN0000000000000000',
        'vTok' => 'ZASTEPCZYVTOK00000000000000000000',
        'jsessionid' => 'ZASTEPCZYJSESSIONID00000000000000',
    ];

    // Zbieramy REALNE wartosci tokenow ze wszystkich znanych form zapisu.
    $wartosci = [];
    foreach (
        [
            'tabToken' => [
                '/tabToken=([A-Za-z0-9]{8,})/',
                '/setTabToken\(\s*["\']([A-Za-z0-9]{8,})["\']/',
                '/name=["\']tabToken["\']\s*value=["\']([A-Za-z0-9]{8,})["\']/',
                '/tabToken["\']?\s*[:=]\s*["\']([A-Za-z0-9]{8,})["\']/',
            ],
            'vTok' => [
                '/vTok=([A-Za-z0-9]{8,})/',
                '/vTok["\']?\s*[:=]\s*["\']([A-Za-z0-9]{8,})["\']/',
            ],
            'jsessionid' => [
                '/jsessionid=([A-Za-z0-9.!_-]{8,})/i',
            ],
        ] as $nazwa => $wzorce
    ) {
        foreach ($wzorce as $wzorzec) {
            if (preg_match_all($wzorzec, $html, $m)) {
                foreach ($m[1] as $wartosc) {
                    $wartosci[$wartosc] = $nazwa;
                }
            }
        }
    }

    // Siatka bezpieczenstwa: podmieniamy KAZDE wystapienie znalezionej wartosci,
    // niezaleznie od tego, w jakiej skladni stoi.
    foreach ($wartosci as $wartosc => $nazwa) {
        $zastepnik = substr(
            str_repeat($zastepniki[$nazwa], 4),
            0,
            max(1, mb_strlen($wartosc))
        );
        $html = str_replace($wartosc, $zastepnik, $html);
    }

    return $html;
}

/**
 * Sprawdza, czy w probce nie zostalo nic, czego nie chcemy w repozytorium.
 * Zwraca liste zastrzezen; pusta lista znaczy „czysto”.
 *
 * @return list<string>
 */
function zastrzezenia_do_probki(string $nazwa, string $tresc): array
{
    $uwagi = [];

    $wzorce = [
        'klucz Google (AIza...)' => '/AIza[A-Za-z0-9_-]{35}/',
        'klucz reCAPTCHA (6L...)' => '/\b6L[A-Za-z0-9_-]{38}\b/',
        'klucz Turnstile (0x4...)' => '/\b0x4[A-Za-z0-9_-]{20,}\b/',
        'identyfikator sesji w adresie' => '/(?:tabToken|vTok|jsessionid)=(?!ZASTEPCZY)[A-Za-z0-9._!-]{8,}/i',
        'identyfikator sesji w kodzie strony' => '/setTabToken\(\s*["\'](?!ZASTEPCZY)[A-Za-z0-9]{8,}/',
        'identyfikator sesji w polu formularza' => '/name=["\'](?:tabToken|vTok)["\']\s*value=["\'](?!ZASTEPCZY)[A-Za-z0-9]{8,}/',
        'pozostaly znacznik script' => '/<script\b/i',
    ];

    foreach ($wzorce as $opis => $wzorzec) {
        if (preg_match($wzorzec, $tresc) === 1) {
            $uwagi[] = "{$nazwa}: {$opis}";
        }
    }

    // Ciagi 32-znakowe szesnastkowe to u tego serwisu ksztalt tokena sesji.
    // Zgłaszamy je nawet wtedy, gdy nie stoja przy zadnej znanej nazwie —
    // wlasnie tak przeoczylem tokeny za pierwszym razem.
    if (preg_match_all('/\b[0-9a-f]{32}\b/', $tresc, $m)) {
        $uwagi[] = sprintf('%s: %d ciagow 32-znakowych szesnastkowych (mozliwe tokeny)', $nazwa, count($m[0]));
    }

    return $uwagi;
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

$client = EpodroznikClient::fromSession();

/** @var list<string> $wszystkieZastrzezenia */
$wszystkieZastrzezenia = [];

/**
 * Zapisuje probke po przygotowaniu i sprawdzeniu. Zastrzezenia zbieramy,
 * zeby na koncu ODMOWIC calosci — probka z cudzym kluczem albo naszym tokenem
 * nie moze wyladowac w repozytorium tylko dlatego, ze ostrzezenie przewinelo
 * sie w logu.
 */
$zapiszProbke = static function (string $sciezka, string $html) use (&$wszystkieZastrzezenia): void {
    $gotowe = przygotuj_probke($html);
    $uwagi = zastrzezenia_do_probki(basename($sciezka), $gotowe);
    foreach ($uwagi as $u) {
        fwrite(STDERR, "ZASTRZEZENIE: {$u}\n");
    }
    $wszystkieZastrzezenia = array_merge($wszystkieZastrzezenia, $uwagi);
    zapisz($sciezka, $gotowe);
};

// 1. Rozklad z przystanku (Sieradz — ten sam, ktorego uzywa monitoring, bo
//    odpowiada szybciej niz wielkie wezly).
$stopId = '103250';
$html = $client->getGeneralTimetableStop($stopId, forceRefresh: true);
$zapiszProbke($outDir . '/timetable_sieradz.html', $html);

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
$zapiszProbke($outDir . '/search_warszawa_kutno.html', $searchHtml);

// 3. Surowa odpowiedz podpowiedzi (JSON, nie HTML) — do testu warstwy wyboru.
$suggest = $client->suggest('Krak', 'SOURCE', 'ALL');
$suggestJson = (string)json_encode($suggest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$uwagiJson = zastrzezenia_do_probki('suggest_krak.json', $suggestJson);
foreach ($uwagiJson as $u) {
    fwrite(STDERR, "ZASTRZEZENIE: {$u}\n");
}
$wszystkieZastrzezenia = array_merge($wszystkieZastrzezenia, $uwagiJson);
zapisz($outDir . '/suggest_krak.json', $suggestJson);

// Bramka koncowa. Probki ida do PUBLICZNEGO repozytorium, wiec przy jakimkolwiek
// zastrzezeniu konczymy bledem — swiadomie PO zapisaniu plikow, zeby dalo sie
// zobaczyc, co dokladnie zostalo wykryte, ale z kodem wyjscia, ktory zatrzymuje
// wszystko, co wola ten skrypt.
if ($wszystkieZastrzezenia !== []) {
    fwrite(STDERR, "\nODMOWA: probki zawieraja tresc, ktorej nie commitujemy:\n");
    foreach ($wszystkieZastrzezenia as $u) {
        fwrite(STDERR, "  - {$u}\n");
    }
    fwrite(STDERR, "Popraw przygotowanie probki (usun_skrypty / anonimizuj) i uruchom ponownie.\n");
    exit(1);
}

echo "gotowe, bez zastrzezen\n";

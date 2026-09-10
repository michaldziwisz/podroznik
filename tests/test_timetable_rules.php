#!/usr/bin/env php
<?php
// Testy TimetableRules: filtrowanie odjazdów po oknie godzinowym i po tym, czy
// kurs jedzie w danym dniu.
//
// To najbardziej podstępny kawałek tego projektu: 657 linii reguł zapisanych
// polszczyzną przewoźników („kursuje w pn - pt w okresie 31.08.2026-06.09.2026”,
// „nie kursuje w okresie ...”, daty z miesiącami rzymskimi). Błąd tutaj nie
// wywala strony, tylko cicho ukrywa kurs albo pokazuje nieistniejący — czyli
// najgorszy możliwy rodzaj awarii w rozkładzie jazdy.
//
// Wzorce reguł pochodzą z prawdziwej odpowiedzi serwisu (tests/fixtures),
// nie z wyobraźni.
//
// Uruchomienie: php tests/test_timetable_rules.php
declare(strict_types=1);

namespace TyfloPodroznik\Tests;

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/Biegacz.php';

use TyfloPodroznik\TimetableRules;

/**
 * Buduje rozkład o kształcie, jaki daje TimetableParser, z jednym kierunkiem.
 *
 * @param list<array{time:string, info?:list<string>, carrier?:string}> $odjazdy
 */
function rozklad(array $odjazdy, string $kierunek = 'Łódź'): array
{
    $deps = [];
    foreach ($odjazdy as $o) {
        $info = $o['info'] ?? [];
        $deps[] = [
            'id' => 'conn_' . count($deps),
            'time' => $o['time'],
            'carrier' => $o['carrier'] ?? 'Przewoźnik testowy',
            'validity' => $info[0] ?? '',
            'notes' => array_slice($info, 1),
            'infoItems' => $info,
        ];
    }

    return [
        'stop' => ['name' => 'SIERADZ', 'city' => 'Sieradz', 'stopId' => '103250'],
        'stopOptions' => [],
        'destinations' => [
            ['destination' => $kierunek, 'through' => [], 'departures' => $deps],
        ],
    ];
}

/** @return list<string> godziny odjazdów, które przeszły filtr */
function godziny_po_filtrze(array $rozklad, array $filtry): array
{
    $wynik = TimetableRules::filter($rozklad, $filtry);
    $godziny = [];
    foreach ($wynik['destinations'] as $g) {
        foreach ($g['departures'] as $d) {
            $godziny[] = (string)$d['time'];
        }
    }
    return $godziny;
}

/** Czy kurs z takimi adnotacjami jedzie danego dnia. */
function jedzie(array $info, string $data): bool
{
    $r = rozklad([['time' => '08:00', 'info' => $info]]);
    return godziny_po_filtrze($r, ['date' => $data]) === ['08:00'];
}

$t = new Biegacz();

// ---------------------------------------------------------------------------
$t->grupa('Okno godzinowe');

$r = rozklad([
    ['time' => '05:30'],
    ['time' => '08:00'],
    ['time' => '12:15'],
    ['time' => '23:45'],
]);

$t->rowne('bez filtrów zostają wszystkie odjazdy', ['05:30', '08:00', '12:15', '23:45'], godziny_po_filtrze($r, []));
$t->rowne('od 08:00 odcina wcześniejsze', ['08:00', '12:15', '23:45'], godziny_po_filtrze($r, ['from_time' => '08:00']));
$t->rowne('do 12:15 odcina późniejsze', ['05:30', '08:00', '12:15'], godziny_po_filtrze($r, ['to_time' => '12:15']));
$t->rowne('okno domknięte z dwóch stron', ['08:00', '12:15'], godziny_po_filtrze($r, ['from_time' => '08:00', 'to_time' => '12:15']));
// Granice muszą być domknięte: pasażer pytający „od 08:00” chce też kursu o 08:00.
$t->rowne('granica dolna należy do okna', ['08:00'], godziny_po_filtrze($r, ['from_time' => '08:00', 'to_time' => '08:00']));
// PARA NEGATYWNA: okno odwrócone nie może po cichu zwrócić wszystkiego.
$t->rowne('okno odwrócone (od 20:00 do 06:00) nie daje nic', [], godziny_po_filtrze($r, ['from_time' => '20:00', 'to_time' => '06:00']));
// Śmieciowa godzina w filtrze = brak ograniczenia, a nie pusty wynik.
$t->rowne('nieprawidłowa godzina filtru jest ignorowana', ['05:30', '08:00', '12:15', '23:45'], godziny_po_filtrze($r, ['from_time' => '25:99']));

$rZeSmieciem = rozklad([['time' => '08:00'], ['time' => 'brak'], ['time' => '']]);
$t->rowne('odjazd bez czytelnej godziny wypada z wyników', ['08:00'], godziny_po_filtrze($rZeSmieciem, ['from_time' => '00:00']));

// ---------------------------------------------------------------------------
$t->grupa('Odsiewanie powtórzeń');

$rDubel = rozklad([
    ['time' => '08:00', 'info' => ['kursuje codziennie'], 'carrier' => 'PKS'],
    ['time' => '08:00', 'info' => ['kursuje codziennie'], 'carrier' => 'PKS'],
    ['time' => '08:00', 'info' => ['kursuje codziennie'], 'carrier' => 'Inny przewoźnik'],
]);
$t->rowne(
    'identyczne wiersze odsiewane, różny przewoźnik zostaje',
    ['08:00', '08:00'],
    godziny_po_filtrze($rDubel, [])
);

// ---------------------------------------------------------------------------
$t->grupa('Dni tygodnia (wzorce z prawdziwego rozkładu)');

// 2026-09-10 to czwartek, 2026-09-12 sobota, 2026-09-13 niedziela.
$t->prawda('"pn - pt" jedzie w czwartek', jedzie(['kursuje w pn - pt w okresie 31.08.2026-25.10.2026'], '2026-09-10'));
$t->falsz('"pn - pt" NIE jedzie w sobotę', jedzie(['kursuje w pn - pt w okresie 31.08.2026-25.10.2026'], '2026-09-12'));
$t->falsz('"pn - pt" NIE jedzie w niedzielę', jedzie(['kursuje w pn - pt w okresie 31.08.2026-25.10.2026'], '2026-09-13'));

$t->prawda('"pn - sb" jedzie w sobotę', jedzie(['kursuje w pn - sb w okresie 31.08.2026-25.10.2026'], '2026-09-12'));
$t->falsz('"pn - sb" NIE jedzie w niedzielę', jedzie(['kursuje w pn - sb w okresie 31.08.2026-25.10.2026'], '2026-09-13'));

$t->prawda('"w nd" jedzie w niedzielę', jedzie(['kursuje w nd w okresie 31.08.2026-25.10.2026'], '2026-09-13'));
$t->falsz('"w nd" NIE jedzie w czwartek', jedzie(['kursuje w nd w okresie 31.08.2026-25.10.2026'], '2026-09-10'));

// Wyliczenie z przecinkami — realny wzorzec z próbki.
$wyliczenie = ['kursuje w pn, wt, cz, pt w okresie 09.11.2026-15.11.2026'];
$t->prawda('wyliczenie dni: poniedziałek jedzie', jedzie($wyliczenie, '2026-11-09'));
$t->prawda('wyliczenie dni: czwartek jedzie', jedzie($wyliczenie, '2026-11-12'));
$t->falsz('wyliczenie dni: środa NIE jedzie', jedzie($wyliczenie, '2026-11-11'));
$t->falsz('wyliczenie dni: sobota NIE jedzie', jedzie($wyliczenie, '2026-11-14'));

// Zakres przechodzący przez koniec tygodnia (cz - nd), też z próbki.
$przezKoniec = ['kursuje w pn, wt, cz - nd w okresie 31.08.2026-06.09.2026'];
$t->prawda('zakres cz - nd obejmuje niedzielę', jedzie($przezKoniec, '2026-09-06'));
$t->prawda('zakres cz - nd obejmuje sobotę', jedzie($przezKoniec, '2026-09-05'));
$t->falsz('zakres cz - nd nie obejmuje środy', jedzie($przezKoniec, '2026-09-02'));

// Zakres ZAWIJAJĄCY, czyli taki, w którym pierwszy dzień jest późniejszy niż
// ostatni: „pt - pn” znaczy piątek, sobota, niedziela, poniedziałek. Kod jawnie
// to obsługuje (licznik dni wraca z niedzieli na poniedziałek), więc musi to
// być sprawdzone — inaczej regresja w zawijaniu przechodzi niezauważona.
// 2026-09-11 piątek, 12 sobota, 13 niedziela, 14 poniedziałek, 15 wtorek.
$zawijajacy = ['kursuje w pt - pn w okresie 01.09.2026-30.09.2026'];
$t->prawda('zakres pt - pn obejmuje piątek', jedzie($zawijajacy, '2026-09-11'));
$t->prawda('zakres pt - pn obejmuje sobotę', jedzie($zawijajacy, '2026-09-12'));
$t->prawda('zakres pt - pn obejmuje niedzielę', jedzie($zawijajacy, '2026-09-13'));
$t->prawda('zakres pt - pn obejmuje poniedziałek', jedzie($zawijajacy, '2026-09-14'));
// PARY NEGATYWNE: dni w środku tygodnia muszą wypaść, inaczej „zawijanie”
// mogłoby po prostu znaczyć „wszystkie dni”.
$t->falsz('zakres pt - pn nie obejmuje wtorku', jedzie($zawijajacy, '2026-09-15'));
$t->falsz('zakres pt - pn nie obejmuje środy', jedzie($zawijajacy, '2026-09-16'));
$t->falsz('zakres pt - pn nie obejmuje czwartku', jedzie($zawijajacy, '2026-09-17'));

// Zakres jednodniowy podany jako zakres.
$t->prawda('zakres sb - sb obejmuje sobotę', jedzie(['kursuje w sb - sb'], '2026-09-12'));
$t->falsz('zakres sb - sb nie obejmuje niedzieli', jedzie(['kursuje w sb - sb'], '2026-09-13'));

$t->prawda('"codziennie" jedzie w czwartek', jedzie(['kursuje codziennie w okresie 31.08.2026-18.10.2026'], '2026-09-10'));
$t->prawda('"codziennie" jedzie też w niedzielę', jedzie(['kursuje codziennie w okresie 31.08.2026-18.10.2026'], '2026-09-13'));

// ---------------------------------------------------------------------------
$t->grupa('Zakresy dat');

$okres = ['kursuje codziennie w okresie 31.08.2026-18.10.2026'];
$t->prawda('dzień w środku okresu', jedzie($okres, '2026-09-10'));
$t->prawda('pierwszy dzień okresu należy do niego', jedzie($okres, '2026-08-31'));
$t->prawda('ostatni dzień okresu należy do niego', jedzie($okres, '2026-10-18'));
// PARA NEGATYWNA: dzień o jeden poza granicą musi wypaść. Bez tego test
// „mieści się w okresie” przechodziłby także wtedy, gdyby daty były ignorowane.
$t->falsz('dzień przed okresem wypada', jedzie($okres, '2026-08-30'));
$t->falsz('dzień po okresie wypada', jedzie($okres, '2026-10-19'));
// Stary rozkład z próbki (2018/2019) nie może jeździć dziś.
$t->falsz('kurs z okresu 2018/2019 nie jedzie w 2026', jedzie(['kursuje codziennie w okresie 09.12.2018-08.06.2019'], '2026-09-10'));

$t->grupa('Daty z miesiącami rzymskimi');

// Wzorzec z próbki: „30.08.2026 - 24.10.2026: kursuje 30.VIII-24.X”.
$rzymskie = ['30.08.2026 - 24.10.2026: kursuje 30.VIII-24.X'];
$t->prawda('zakres rzymski obejmuje dzień w środku', jedzie($rzymskie, '2026-09-10'));
$t->falsz('zakres rzymski nie obejmuje dnia po', jedzie($rzymskie, '2026-10-25'));

// Wyliczenie pojedynczych dni z miesiącami rzymskimi, też z próbki.
$dniWyliczone = ['Kursuje w dniach: 25.X.2026, 26.X.2026, 27.X.2026'];
$t->prawda('wyliczona data jedzie', jedzie($dniWyliczone, '2026-10-26'));
$t->falsz('data poza wyliczeniem nie jedzie', jedzie($dniWyliczone, '2026-10-28'));

// Miesiąc rzymski musi być czytany jako całość: IX to wrzesień, nie I + X.
$t->prawda('IX czytane jako wrzesień', jedzie(['Kursuje w dniach: 15.IX.2026'], '2026-09-15'));
$t->falsz('IX nie jest czytane jako styczeń', jedzie(['Kursuje w dniach: 15.IX.2026'], '2026-01-15'));
$t->prawda('XII czytane jako grudzień', jedzie(['Kursuje w dniach: 05.XII.2026'], '2026-12-05'));
$t->falsz('XII nie jest czytane jako listopad', jedzie(['Kursuje w dniach: 05.XII.2026'], '2026-11-05'));

// Data nieistniejąca nie może zostać po cichu przeliczona na inną (31 lutego
// jako 3 marca). Regułę podajemy razem z datą prawidłową, żeby ograniczenie
// daty w ogóle się włączyło — sama nieprawidłowa data znaczy „nie rozumiem
// reguły”, a wtedy kurs zostaje widoczny (patrz sekcja o odporności niżej).
$zBledna = ['Kursuje w dniach: 31.II.2026, 15.IX.2026'];
$t->prawda('prawidłowa data z tej samej reguły jedzie', jedzie($zBledna, '2026-09-15'));
$t->falsz('31 lutego nie jest przeliczane na 3 marca', jedzie($zBledna, '2026-03-03'));
$t->falsz('31 lutego nie jest przeliczane na 28 lutego', jedzie($zBledna, '2026-02-28'));

// ---------------------------------------------------------------------------
$t->grupa('Wyłączenia ("nie kursuje")');

$zWylaczeniem = [
    'kursuje codziennie w okresie 01.09.2026-30.09.2026',
    'nie kursuje w okresie 10.09.2026-12.09.2026',
];
$t->falsz('dzień objęty wyłączeniem wypada', jedzie($zWylaczeniem, '2026-09-11'));
$t->prawda('dzień przed wyłączeniem jedzie', jedzie($zWylaczeniem, '2026-09-09'));
$t->prawda('dzień po wyłączeniu jedzie', jedzie($zWylaczeniem, '2026-09-13'));
// Kolejność wpisów nie może zmieniać wyniku.
$t->falsz(
    'wyłączenie działa też gdy stoi przed regułą kursowania',
    jedzie(array_reverse($zWylaczeniem), '2026-09-11')
);

// ---------------------------------------------------------------------------
$t->grupa('Dni robocze, dni wolne i święta');

// 2026-11-11 (Święto Niepodległości) wypada w środę: to jest dzień, w którym
// „dni robocze” i „środa” dają różne odpowiedzi, więc rozstrzyga o tym, czy
// obsługa świąt w ogóle działa.
$t->falsz('"dni robocze" nie jedzie w święto wypadające w środę', jedzie(['kursuje w dni robocze'], '2026-11-11'));
$t->prawda('"dni robocze" jedzie w zwykłą środę', jedzie(['kursuje w dni robocze'], '2026-11-04'));
$t->prawda('"dni wolne" jedzie w niedzielę', jedzie(['kursuje w dni wolne'], '2026-09-13'));
$t->prawda('"dni wolne" jedzie w święto w środku tygodnia', jedzie(['kursuje w dni wolne'], '2026-11-11'));
$t->falsz('"dni wolne" nie jedzie w zwykły czwartek', jedzie(['kursuje w dni wolne'], '2026-09-10'));

// Wielkanoc jest ruchoma — sprawdzamy dwa różne lata, bo stała data by tego
// nie wykryła. Wielkanoc 2026: 5 kwietnia, poniedziałek wielkanocny 6 kwietnia.
$t->prawda('"dni wolne" jedzie w Wielkanoc 2026', jedzie(['kursuje w dni wolne'], '2026-04-05'));
$t->falsz('"dni robocze" nie jedzie w poniedziałek wielkanocny 2026', jedzie(['kursuje w dni robocze'], '2026-04-06'));
// Wielkanoc 2027: 28 marca, poniedziałek 29 marca.
$t->falsz('"dni robocze" nie jedzie w poniedziałek wielkanocny 2027', jedzie(['kursuje w dni robocze'], '2027-03-29'));
// PARA NEGATYWNA: dzień o tej samej dacie rok później NIE jest świętem
// ruchomym, więc kurs w dni robocze musi jechać.
$t->prawda('"dni robocze" jedzie 29.03.2028 (nie jest to już święto)', jedzie(['kursuje w dni robocze'], '2028-03-29'));
// Boże Ciało 2026: 4 czerwca (czwartek).
$t->falsz('"dni robocze" nie jedzie w Boże Ciało 2026', jedzie(['kursuje w dni robocze'], '2026-06-04'));
$t->prawda('"dni robocze" jedzie w czwartek tygodnia obok', jedzie(['kursuje w dni robocze'], '2026-06-11'));

// ---------------------------------------------------------------------------
$t->grupa('Odporność na wejście, którego nie rozumiemy');

// Zasada: gdy reguły nie da się zinterpretować, kurs ZOSTAJE widoczny.
// Ukrycie realnego kursu jest dla pasażera gorsze niż pokazanie kursu, który
// akurat nie jedzie — bo o tym drugim powie mu adnotacja przy godzinie.
$t->prawda('adnotacja bez zrozumiałej reguły nie ukrywa kursu', jedzie(['pociąg z miejscami do leżenia'], '2026-09-10'));
$t->prawda('brak adnotacji w ogóle nie ukrywa kursu', jedzie([], '2026-09-10'));
$t->prawda('zlepiony zapis dat z próbki nie ukrywa kursu', jedzie(['Nie kursuje: 01.0121.04'], '2026-09-10'));
$t->prawda('pusta data filtru nie ukrywa niczego', jedzie(['kursuje w pn - pt w okresie 31.08.2026-25.10.2026'], ''));
$t->prawda('nieprawidłowa data filtru nie ukrywa niczego', jedzie(['kursuje w nd'], 'nie-data'));

// Struktura wejścia bywa niepełna (parser zwraca to, co znalazł) — filtr nie
// może się na tym wywrócić.
$t->rowne('rozkład bez kierunków przechodzi bez wyjątku', [], godziny_po_filtrze(['destinations' => []], ['date' => '2026-09-10']));
$t->rowne('brak klucza destinations przechodzi bez wyjątku', [], godziny_po_filtrze([], ['date' => '2026-09-10']));
$t->rowne(
    'śmieci w miejscu kierunku są pomijane',
    [],
    godziny_po_filtrze(['destinations' => ['nie-tablica', ['departures' => 'też-nie']]], [])
);

// Kierunek, w którym po filtrowaniu nic nie zostało, znika z wyników —
// pusta sekcja w interfejsie byłaby dla czytnika ekranu tylko szumem.
$rDwaKierunki = [
    'stop' => ['name' => 'SIERADZ', 'city' => 'Sieradz', 'stopId' => '103250'],
    'stopOptions' => [],
    'destinations' => [
        ['destination' => 'Łódź', 'through' => [], 'departures' => [
            ['time' => '06:00', 'carrier' => 'A', 'validity' => '', 'notes' => [], 'infoItems' => []],
        ]],
        ['destination' => 'Poznań', 'through' => [], 'departures' => [
            ['time' => '22:00', 'carrier' => 'B', 'validity' => '', 'notes' => [], 'infoItems' => []],
        ]],
    ],
];
$wynik = TimetableRules::filter($rDwaKierunki, ['to_time' => '12:00']);
$t->rowne('zostaje tylko kierunek z odjazdami w oknie', 1, count($wynik['destinations']));
$t->rowne('i jest to właściwy kierunek', 'Łódź', $wynik['destinations'][0]['destination']);
// Filtr nie może gubić danych o samym przystanku.
$t->rowne('dane przystanku przechodzą przez filtr', 'SIERADZ', $wynik['stop']['name']);

// ---------------------------------------------------------------------------
$t->grupa('Filtr na prawdziwej odpowiedzi serwisu');

$probka = __DIR__ . '/fixtures/timetable_sieradz.html';
if (!is_file($probka)) {
    echo "POMINIĘTO (brak {$probka} — uruchom scripts/dev/collect_fixtures.php)\n";
} else {
    $parser = new \TyfloPodroznik\TimetableParser();
    $pelny = $parser->parseGeneralTimetableHtml((string)file_get_contents($probka));

    $policz = static function (array $r): int {
        $n = 0;
        foreach ($r['destinations'] as $g) {
            $n += count($g['departures']);
        }
        return $n;
    };

    $wszystkie = $policz($pelny);
    $t->prawda('próbka ma dużo odjazdów', $wszystkie > 100);

    // Filtr musi ZAWĘŻAĆ, nigdy nie dodawać. To pilnuje najgroźniejszej
    // pomyłki: wymyślenia kursu, którego w rozkładzie nie ma.
    $poranek = $policz(TimetableRules::filter($pelny, ['from_time' => '06:00', 'to_time' => '09:00']));
    $t->prawda('okno poranne zawęża liczbę odjazdów', $poranek < $wszystkie);
    $t->prawda('okno poranne coś zostawia', $poranek > 0);

    $zData = $policz(TimetableRules::filter($pelny, ['date' => '2026-09-10']));
    $t->prawda('filtr po dacie zawęża liczbę odjazdów', $zData < $wszystkie);
    $t->prawda('filtr po dacie coś zostawia', $zData > 0);

    // Wąskie okno musi dać podzbiór szerokiego.
    $szerokie = TimetableRules::filter($pelny, ['from_time' => '06:00', 'to_time' => '12:00']);
    $waskie = TimetableRules::filter($pelny, ['from_time' => '07:00', 'to_time' => '08:00']);
    $identy = static function (array $r): array {
        $out = [];
        foreach ($r['destinations'] as $g) {
            foreach ($g['departures'] as $d) {
                $out[$g['destination'] . '|' . $d['id'] . '|' . $d['time']] = true;
            }
        }
        return $out;
    };
    $idSzerokie = $identy($szerokie);
    $poza = array_values(array_filter(
        array_keys($identy($waskie)),
        static fn(string $k): bool => !isset($idSzerokie[$k])
    ));
    $t->rowne('wynik wąskiego okna zawiera się w szerokim', 0, count($poza));

    // Każdy odjazd, który przeszedł filtr godzinowy, faktycznie mieści się w oknie.
    $pozaOknem = [];
    foreach ($waskie['destinations'] as $g) {
        foreach ($g['departures'] as $d) {
            $czas = (string)$d['time'];
            if ($czas < '07:00' || $czas > '08:00') {
                $pozaOknem[] = $czas;
            }
        }
    }
    $t->rowne('żaden odjazd po filtrze nie wypada z okna', 0, count($pozaOknem));

    // Filtr niemożliwy do spełnienia daje pustkę, a nie wszystko.
    $pusty = $policz(TimetableRules::filter($pelny, ['from_time' => '03:00', 'to_time' => '03:01']));
    $t->rowne('okno bez żadnego kursu daje zero', 0, $pusty);
}

exit($t->podsumuj());

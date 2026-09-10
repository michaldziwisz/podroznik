#!/usr/bin/env php
<?php
// Testy parserów HTML: TimetableParser i ResultsParser.
//
// Dlaczego na utrwalonych próbkach, a nie na żywym serwisie: parsery żyją
// wyłącznie z kształtu cudzego HTML-a. Test odpytujący e-podroznik.pl byłby
// wolny, kruchy i padałby przy każdej awarii upstreamu, czyli mówiłby o czymś
// innym niż o naszym kodzie. Próbki zbiera scripts/dev/collect_fixtures.php.
//
// Uruchomienie: php tests/run.php   (albo bezpośrednio ten plik)
declare(strict_types=1);

namespace TyfloPodroznik\Tests;

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/Biegacz.php';

use TyfloPodroznik\ResultsParser;
use TyfloPodroznik\TimetableParser;

/** @return array{0:Biegacz,1:int} */
function uruchom_testy_parserow(Biegacz $t): void
{
    $katalogProbek = __DIR__ . '/fixtures';
    $probkaRozkladu = $katalogProbek . '/timetable_sieradz.html';
    $probkaWynikow = $katalogProbek . '/search_warszawa_kutno.html';

    $t->grupa('TimetableParser: puste i śmieciowe wejście');

    $tp = new TimetableParser();

    // Pusty HTML musi dać zrozumiały komunikat, a nie cichą pustą strukturę:
    // monitoring klasyfikuje błędy po TREŚCI komunikatu (error_kind=parser).
    $t->rzuca('pusty HTML rzuca wyjątek', 'Pusta odpowiedź', static fn() => $tp->parseGeneralTimetableHtml(''));
    $t->rzuca('same spacje to też puste wejście', 'Pusta odpowiedź', static fn() => $tp->parseGeneralTimetableHtml("  \n\t "));
    // PARA NEGATYWNA: poprawny HTML bez kontenera rozkładu to INNY błąd niż
    // pusta odpowiedź — inaczej nie odróżnimy „serwis milczy” od „serwis
    // zmienił układ strony”.
    $t->rzuca(
        'HTML bez kontenera rozkładu daje komunikat o braku danych',
        'Nie znaleziono danych rozkładu',
        static fn() => $tp->parseGeneralTimetableHtml('<div class="cosinnego">nic tu nie ma</div>')
    );

    if (!is_file($probkaRozkladu)) {
        echo "POMINIĘTO testy na próbce rozkładu (brak {$probkaRozkladu} — uruchom scripts/dev/collect_fixtures.php)\n";
    } else {
        $t->grupa('TimetableParser: prawdziwa odpowiedź serwisu (Sieradz)');
        $rozklad = $tp->parseGeneralTimetableHtml((string)file_get_contents($probkaRozkladu));

        $t->rowne('nazwa przystanku', 'SIERADZ', $rozklad['stop']['name']);
        $t->rowne('identyfikator przystanku', '103250', $rozklad['stop']['stopId']);
        $t->prawda('miejscowość przystanku niepusta', $rozklad['stop']['city'] !== '');

        // Lista przystanków do przełączania: sam fakt, że jest niepusta, nie
        // wystarcza — sprawdzamy też, że bieżący przystanek jest w niej
        // zaznaczony, bo z tego korzysta lista rozwijana w interfejsie.
        $t->prawda('lista przystanków ma wiele pozycji', count($rozklad['stopOptions']) > 10);
        $zaznaczone = array_values(array_filter(
            $rozklad['stopOptions'],
            static fn(array $o): bool => $o['selected'] === true
        ));
        $t->rowne('dokładnie jeden przystanek zaznaczony', 1, count($zaznaczone));
        $t->rowne('zaznaczony jest ten, o który pytaliśmy', '103250', $zaznaczone[0]['id']);
        $t->prawda(
            'każda pozycja listy ma niepuste id i etykietę',
            array_reduce(
                $rozklad['stopOptions'],
                static fn(bool $akum, array $o): bool => $akum && $o['id'] !== '' && $o['label'] !== '',
                true
            )
        );

        $t->prawda('są kierunki odjazdów', count($rozklad['destinations']) > 0);

        $wszystkieOdjazdy = [];
        foreach ($rozklad['destinations'] as $kierunek) {
            $t->prawda(
                'kierunek "' . $kierunek['destination'] . '" ma niepustą nazwę i odjazdy',
                $kierunek['destination'] !== '' && count($kierunek['departures']) > 0
            );
            foreach ($kierunek['departures'] as $odjazd) {
                $wszystkieOdjazdy[] = $odjazd;
            }
        }

        $t->prawda('zebrano wiele odjazdów', count($wszystkieOdjazdy) > 50);

        // Godzina to jedyne pole, bez którego wiersz odjazdu jest bezwartościowy.
        $zleGodziny = array_values(array_filter(
            $wszystkieOdjazdy,
            static fn(array $d): bool => preg_match('/^\d{1,2}:\d{2}$/', (string)$d['time']) !== 1
        ));
        $t->rowne('każdy odjazd ma godzinę w formacie H:MM', 0, count($zleGodziny));

        $bezPrzewoznika = array_values(array_filter(
            $wszystkieOdjazdy,
            static fn(array $d): bool => trim((string)$d['carrier']) === ''
        ));
        $t->rowne('każdy odjazd ma przewoźnika', 0, count($bezPrzewoznika));

        // Umowa wewnętrzna, na której opiera się filtrowanie po dacie:
        // validity to PIERWSZA pozycja infoItems, notes to reszta.
        foreach (array_slice($wszystkieOdjazdy, 0, 25) as $i => $odjazd) {
            $oczekiwane = $odjazd['infoItems'] === [] ? '' : $odjazd['infoItems'][0];
            $t->rowne("odjazd {$i}: validity to pierwsza pozycja infoItems", $oczekiwane, $odjazd['validity']);
            $t->rowne(
                "odjazd {$i}: notes to reszta infoItems",
                array_slice($odjazd['infoItems'], 1),
                $odjazd['notes']
            );
        }

        // „przez” bywa puste (kierunek bezpośredni), ale gdy jest, nie może
        // zawierać przedrostka „przez:” ani pustych elementów.
        $zlePrzez = [];
        foreach ($rozklad['destinations'] as $kierunek) {
            foreach ($kierunek['through'] as $przystanek) {
                if (trim($przystanek) === '' || preg_match('/^przez\s*:/iu', $przystanek) === 1) {
                    $zlePrzez[] = $kierunek['destination'] . ': ' . $przystanek;
                }
            }
        }
        $t->rowne('lista "przez" bez pustych elementów i bez przedrostka', 0, count($zlePrzez));
    }

    $t->grupa('ResultsParser: puste i śmieciowe wejście');

    $rp = new ResultsParser();
    $t->rzuca('pusty HTML rzuca wyjątek', 'Pusta odpowiedź', static fn() => $rp->parseResultsPageHtml(''));
    // PARA NEGATYWNA: strona bez wyników NIE jest błędem. Zero połączeń to
    // prawidłowa odpowiedź serwisu (np. nocą albo na trasie bez kursów),
    // a wyjątek zamieniłby ją w komunikat o awarii.
    $bezWynikow = $rp->parseResultsPageHtml('<html><body><h1>Brak połączeń</h1></body></html>');
    $t->rowne('strona bez wyników daje count=0, nie wyjątek', 0, $bezWynikow['count']);
    $t->rowne('strona bez wyników daje pustą listę', [], $bezWynikow['results']);
    $t->rowne('brak odnośnika wcześniej to null', null, $bezWynikow['extendBackUrl']);

    if (!is_file($probkaWynikow)) {
        echo "POMINIĘTO testy na próbce wyników (brak {$probkaWynikow} — uruchom scripts/dev/collect_fixtures.php)\n";
        return;
    }

    $t->grupa('ResultsParser: prawdziwa odpowiedź serwisu (Warszawa - Kutno)');
    $wyniki = $rp->parseResultsPageHtml((string)file_get_contents($probkaWynikow));

    $t->prawda('są połączenia', $wyniki['count'] > 0);
    $t->rowne('count zgadza się z liczbą pozycji', $wyniki['count'], count($wyniki['results']));

    // Odnośniki „wcześniej/później” są jedynym sposobem przeglądania sąsiednich
    // godzin, więc ich zniknięcie to realna utrata funkcji.
    $t->prawda('jest odnośnik do wcześniejszych połączeń', is_string($wyniki['extendBackUrl']) && $wyniki['extendBackUrl'] !== '');
    $t->prawda('jest odnośnik do późniejszych połączeń', is_string($wyniki['extendForwardUrl']) && $wyniki['extendForwardUrl'] !== '');
    $t->zawiera('odnośnik wcześniej wskazuje kierunek BACKWARDS', 'BACKWARDS', (string)$wyniki['extendBackUrl']);
    $t->zawiera('odnośnik później wskazuje kierunek FORWARD', 'FORWARD', (string)$wyniki['extendForwardUrl']);

    foreach ($wyniki['results'] as $i => $polaczenie) {
        $t->prawda("połączenie {$i}: ma identyfikator", trim((string)$polaczenie['resId']) !== '');
        $t->prawda(
            "połączenie {$i}: ma przystanek początkowy i godzinę",
            trim((string)$polaczenie['from']['stop']) !== ''
            && preg_match('/^\d{1,2}:\d{2}$/', (string)$polaczenie['from']['time']) === 1
        );
        $t->prawda(
            "połączenie {$i}: ma przystanek końcowy i godzinę",
            trim((string)$polaczenie['to']['stop']) !== ''
            && preg_match('/^\d{1,2}:\d{2}$/', (string)$polaczenie['to']['time']) === 1
        );
        $t->prawda("połączenie {$i}: ma czas podróży", trim((string)$polaczenie['duration']) !== '');
        $t->prawda("połączenie {$i}: liczba przesiadek nie jest ujemna", (int)$polaczenie['sort']['changes'] >= 0);
    }

    // Tytuł strony: e-podroznik.pl wstawia w pierwszy nagłówek treści zupełnie
    // niezwiązane z wyszukiwaniem (w tej próbce dosłownie „Nieprawidłowy adres
    // email” — komunikat z formularza newslettera). Dlatego pole 'title' NIE
    // nadaje się na nagłówek naszej strony i widok go nie używa. Ten test
    // pilnuje, żeby nikt go tam przez pomyłkę nie wstawił, i dokumentuje powód.
    $t->prawda('pole title istnieje, ale jest tylko informacyjne', is_string($wyniki['title']));
    $szablonWynikow = (string)file_get_contents(__DIR__ . '/../src/templates/results.php');
    $t->nie_zawiera(
        'szablon wyników nie pokazuje tytułu przepisanego z e-podroznik.pl',
        "results['title']",
        $szablonWynikow
    );
}

$test = new Biegacz();
uruchom_testy_parserow($test);
exit($test->podsumuj());

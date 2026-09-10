#!/usr/bin/env php
<?php
// Testy Input: normalizacja daty i godziny wpisanej przez człowieka.
//
// To jest brama, przez którą wszystko z formularza wchodzi do reszty kodu.
// Zasada tej klasy: albo zwraca wartość w jednym, przewidywalnym kształcie,
// albo null. Nigdy „coś podobnego” — bo dalej ta wartość idzie do zapytania
// do e-podroznik.pl i do filtrowania rozkładu.
//
// Uruchomienie: php tests/test_input.php
declare(strict_types=1);

namespace TyfloPodroznik\Tests;

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/Biegacz.php';

use TyfloPodroznik\Input;

$t = new Biegacz();

$t->grupa('Data: przyjmowane zapisy');

$t->rowne('zapis z kontrolki daty', '2026-09-10', Input::normalizeDateYmd('2026-09-10'));
$t->rowne('zapis polski z kropkami', '2026-09-10', Input::normalizeDateYmd('10.09.2026'));
$t->rowne('zapis polski bez zer wiodących', '2026-09-05', Input::normalizeDateYmd('5.9.2026'));
$t->rowne('zapis zbity, same cyfry', '2026-09-10', Input::normalizeDateYmd('20260910'));
$t->rowne('spacje wokół są obcinane', '2026-09-10', Input::normalizeDateYmd('  2026-09-10  '));

$t->grupa('Data: odrzucane wejście');

// PARY NEGATYWNE. Każda z tych wartości mogłaby przy naiwnej implementacji
// zostać „naprawiona” przez PHP na jakąś sąsiednią datę — a cicha podmiana
// daty w wyszukiwarce połączeń to błąd, którego użytkownik nie zauważy.
$t->rowne('pusty ciąg', null, Input::normalizeDateYmd(''));
$t->rowne('same spacje', null, Input::normalizeDateYmd('   '));
$t->rowne('31 lutego nie jest przeliczane na 3 marca', null, Input::normalizeDateYmd('2026-02-31'));
$t->rowne('31 kwietnia nie jest przeliczane na 1 maja', null, Input::normalizeDateYmd('31.04.2026'));
$t->rowne('miesiąc 13 odrzucony', null, Input::normalizeDateYmd('2026-13-01'));
$t->rowne('miesiąc 00 odrzucony', null, Input::normalizeDateYmd('2026-00-10'));
$t->rowne('dzień 00 odrzucony', null, Input::normalizeDateYmd('2026-09-00'));
$t->rowne('tekst zamiast daty', null, Input::normalizeDateYmd('jutro'));
$t->rowne('data z godziną nie jest przyjmowana', null, Input::normalizeDateYmd('2026-09-10 08:00'));
$t->rowne('rok dwucyfrowy odrzucony', null, Input::normalizeDateYmd('10.09.26'));
$t->rowne('zapis z ukośnikami odrzucony', null, Input::normalizeDateYmd('2026/09/10'));

// Rok przestępny: 29 lutego istnieje w 2028, nie istnieje w 2026. Ta para
// pilnuje, żeby sprawdzanie daty naprawdę patrzyło na kalendarz.
$t->rowne('29 lutego w roku przestępnym jest przyjmowany', '2028-02-29', Input::normalizeDateYmd('2028-02-29'));
$t->rowne('29 lutego w roku zwykłym jest odrzucany', null, Input::normalizeDateYmd('2026-02-29'));

$t->grupa('Godzina: przyjmowane zapisy');

$t->rowne('zapis z dwukropkiem', '08:05', Input::normalizeTimeHm('08:05'));
$t->rowne('bez zera wiodącego', '08:05', Input::normalizeTimeHm('8:05'));
$t->rowne('z kropką zamiast dwukropka', '08:05', Input::normalizeTimeHm('8.05'));
$t->rowne('z przecinkiem zamiast dwukropka', '08:05', Input::normalizeTimeHm('8,05'));
$t->rowne('cztery cyfry bez separatora', '08:05', Input::normalizeTimeHm('0805'));
$t->rowne('trzy cyfry bez separatora', '08:05', Input::normalizeTimeHm('805'));
$t->rowne('sama godzina', '08:00', Input::normalizeTimeHm('8'));
$t->rowne('sama godzina dwucyfrowa', '18:00', Input::normalizeTimeHm('18'));
$t->rowne('sekundy są obcinane', '08:05', Input::normalizeTimeHm('08:05:30'));
$t->rowne('północ', '00:00', Input::normalizeTimeHm('00:00'));
$t->rowne('ostatnia minuta doby', '23:59', Input::normalizeTimeHm('23:59'));
$t->rowne('spacje wokół są obcinane', '08:05', Input::normalizeTimeHm('  08:05 '));

$t->grupa('Godzina: odrzucane wejście');

$t->rowne('pusty ciąg', null, Input::normalizeTimeHm(''));
$t->rowne('same spacje', null, Input::normalizeTimeHm('   '));
// 24:00 bywa zapisem „koniec doby”, ale nasz filtr porównuje godziny jako
// tekst, więc przyjęcie go dałoby okno, do którego nic nie wpada.
$t->rowne('godzina 24 odrzucona', null, Input::normalizeTimeHm('24:00'));
$t->rowne('godzina 25 odrzucona', null, Input::normalizeTimeHm('25:00'));
$t->rowne('minuta 60 odrzucona', null, Input::normalizeTimeHm('08:60'));
$t->rowne('minuta 99 odrzucona', null, Input::normalizeTimeHm('0899'));
$t->rowne('pięć cyfr odrzucone', null, Input::normalizeTimeHm('123456'));
$t->rowne('sam tekst odrzucony', null, Input::normalizeTimeHm('rano'));

// Litery są usuwane przed rozpoznaniem, więc „8:05 rano” jest czytane jako
// godzina. To zamierzone (wybaczamy zapis potoczny), ale samo „rano” bez cyfr
// musi zostać odrzucone — inaczej wybaczanie zamieniłoby się w zgadywanie.
$t->rowne('godzina z dopiskiem słownym jest czytana', '08:05', Input::normalizeTimeHm('8:05 rano'));

$t->grupa('Zgodność z resztą kodu');

// Wynik normalizacji musi pasować do wzorca, którego używa filtr rozkładu
// (TimetableRules::timeToMinutesOrNull) oraz do porównań tekstowych godzin.
foreach (['8', '805', '8.05', '08:05:30', '23:59'] as $wejscie) {
    $wynik = Input::normalizeTimeHm($wejscie);
    $t->prawda(
        "\"{$wejscie}\" daje godzinę w kształcie HH:MM",
        is_string($wynik) && preg_match('/^\d{2}:\d{2}$/', $wynik) === 1
    );
}

foreach (['2026-09-10', '10.09.2026', '20260910'] as $wejscie) {
    $wynik = Input::normalizeDateYmd($wejscie);
    $t->prawda(
        "\"{$wejscie}\" daje datę w kształcie RRRR-MM-DD",
        is_string($wynik) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $wynik) === 1
    );
}

// Normalizacja musi być stabilna: wynik podany ponownie na wejście daje to samo.
foreach (['2026-09-10', '5.9.2026', '20260910'] as $wejscie) {
    $raz = Input::normalizeDateYmd($wejscie);
    $t->rowne("powtórna normalizacja \"{$wejscie}\" nic nie zmienia", $raz, Input::normalizeDateYmd((string)$raz));
}
foreach (['8', '8.05', '0805'] as $wejscie) {
    $raz = Input::normalizeTimeHm($wejscie);
    $t->rowne("powtórna normalizacja \"{$wejscie}\" nic nie zmienia", $raz, Input::normalizeTimeHm((string)$raz));
}

exit($t->podsumuj());

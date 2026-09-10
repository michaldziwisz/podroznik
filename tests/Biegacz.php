<?php
// Minimalny biegacz testów. Świadomie bez composera i PHPUnita: projekt nie ma
// żadnych zależności zewnętrznych, a wprowadzanie ich tylko po to, żeby
// uruchomić asercje, kosztowałoby więcej niż daje. Gdy kiedyś dojdzie composer,
// te testy przenoszą się na PHPUnit bez przepisywania asercji.
//
// Zasada: każdy przypadek pozytywny ma parę negatywną. Test, który przechodzi
// także wtedy, gdy sprawdzana rzecz jest zepsuta, jest gorszy niż jego brak.
declare(strict_types=1);

namespace TyfloPodroznik\Tests;

final class Biegacz
{
    private int $ok = 0;
    /** @var list<string> */
    private array $bledy = [];
    private string $grupa = '';

    public function grupa(string $nazwa): void
    {
        $this->grupa = $nazwa;
        echo "\n=== {$nazwa} ===\n";
    }

    public function rowne(string $opis, mixed $oczekiwane, mixed $otrzymane): void
    {
        if ($oczekiwane === $otrzymane) {
            $this->zdane($opis);
            return;
        }
        $this->oblane($opis, sprintf(
            "oczekiwano: %s\n      otrzymano:  %s",
            $this->pokaz($oczekiwane),
            $this->pokaz($otrzymane)
        ));
    }

    public function prawda(string $opis, mixed $warunek): void
    {
        if ($warunek === true) {
            $this->zdane($opis);
            return;
        }
        $this->oblane($opis, 'oczekiwano true, otrzymano ' . $this->pokaz($warunek));
    }

    public function falsz(string $opis, mixed $warunek): void
    {
        if ($warunek === false) {
            $this->zdane($opis);
            return;
        }
        $this->oblane($opis, 'oczekiwano false, otrzymano ' . $this->pokaz($warunek));
    }

    public function zawiera(string $opis, string $igla, string $stog): void
    {
        if ($igla !== '' && str_contains($stog, $igla)) {
            $this->zdane($opis);
            return;
        }
        $this->oblane($opis, sprintf('brak fragmentu %s w: %s', $this->pokaz($igla), $this->skroc($stog)));
    }

    public function nie_zawiera(string $opis, string $igla, string $stog): void
    {
        if (!str_contains($stog, $igla)) {
            $this->zdane($opis);
            return;
        }
        $this->oblane($opis, sprintf('fragment %s NIE POWINIEN wystąpić w: %s', $this->pokaz($igla), $this->skroc($stog)));
    }

    /** Sprawdza, że wywołanie rzuca wyjątek z podanym fragmentem komunikatu. */
    public function rzuca(string $opis, string $fragmentKomunikatu, callable $wywolanie): void
    {
        try {
            $wywolanie();
        } catch (\Throwable $e) {
            if ($fragmentKomunikatu === '' || str_contains($e->getMessage(), $fragmentKomunikatu)) {
                $this->zdane($opis);
                return;
            }
            $this->oblane($opis, sprintf(
                'wyjątek rzucony, ale komunikat nie zawiera %s (jest: %s)',
                $this->pokaz($fragmentKomunikatu),
                $this->skroc($e->getMessage())
            ));
            return;
        }
        $this->oblane($opis, 'oczekiwano wyjątku, a wywołanie zakończyło się spokojnie');
    }

    private function zdane(string $opis): void
    {
        $this->ok++;
        echo "OK    {$opis}\n";
    }

    private function oblane(string $opis, string $szczegoly): void
    {
        $this->bledy[] = ($this->grupa !== '' ? "[{$this->grupa}] " : '') . $opis;
        echo "BŁĄD  {$opis}\n      {$szczegoly}\n";
    }

    private function pokaz(mixed $w): string
    {
        if (is_string($w)) {
            return '"' . $this->skroc($w) . '"';
        }
        return $this->skroc(var_export($w, true));
    }

    private function skroc(string $t): string
    {
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
        return mb_strlen($t) > 160 ? mb_substr($t, 0, 160) . '…' : $t;
    }

    public function podsumuj(): int
    {
        $bledow = count($this->bledy);
        echo "\n";
        printf("WYNIK: %d asercji zdanych, %d oblanych\n", $this->ok, $bledow);
        if ($bledow > 0) {
            echo "OBLANE:\n";
            foreach ($this->bledy as $b) {
                echo "  - {$b}\n";
            }
            return 1;
        }
        if ($this->ok === 0) {
            // Zero asercji to NIE sukces. Bez tego "wszystko zielone" mogłoby
            // znaczyć "nic się nie wykonało".
            echo "UWAGA: nie wykonała się ani jedna asercja — traktuję to jako błąd.\n";
            return 1;
        }
        return 0;
    }
}

#!/usr/bin/env php
<?php
declare(strict_types=1);

// Reuse the same session between runs (prevents creating tons of cookie-jar temp files),
// but keep it separate from the web app session storage.
if (PHP_SAPI === 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    $savePath = sys_get_temp_dir() . '/podroznik-monitor-sessions';
    if (!is_dir($savePath)) {
        @mkdir($savePath, 0o700, true);
    }
    if (is_dir($savePath) && is_writable($savePath)) {
        ini_set('session.save_path', $savePath);
    }
    session_id('podroznik-monitor');
}

require __DIR__ . '/../../src/bootstrap.php';

use TyfloPodroznik\EpodroznikClient;
use TyfloPodroznik\ResultsParser;
use TyfloPodroznik\TimetableParser;

final class UpstreamCheck
{
    /** @var list<string> */
    private array $errors = [];

    /** @var list<string> */
    private array $info = [];

    private string $errorKind = 'unknown';

    public function run(): int
    {
        $startedAt = microtime(true);

        try {
            $client = EpodroznikClient::fromSession();
        } catch (\Throwable $e) {
            $this->addError('init', $e);
            $client = null;
        }

        if ($client instanceof EpodroznikClient) {
            try {
                $client = $this->checkSuggest($client);
            } catch (\Throwable $e) {
                $this->addError('suggest', $e);
            }
        }

        if ($this->errors === [] && $client instanceof EpodroznikClient) {
            try {
                $client = $this->checkSearch($client);
            } catch (\Throwable $e) {
                $this->addError('search', $e);
            }
        }

        if ($this->errors === [] && $client instanceof EpodroznikClient) {
            try {
                $this->checkTimetable($client);
            } catch (\Throwable $e) {
                $this->addError('timetable', $e);
            }
        }

        $elapsedMs = (int)round((microtime(true) - $startedAt) * 1000);

        if ($this->errors !== []) {
            fwrite(STDERR, "FAILED\n");
            fwrite(STDERR, "elapsed_ms={$elapsedMs}\n");
            fwrite(STDERR, "error_kind={$this->errorKind}\n");
            foreach ($this->errors as $line) {
                fwrite(STDERR, $line . "\n");
            }
            if ($this->info !== []) {
                fwrite(STDERR, "\ninfo:\n");
                foreach ($this->info as $line) {
                    fwrite(STDERR, $line . "\n");
                }
            }
            return 2;
        }

        echo "OK\n";
        echo "elapsed_ms={$elapsedMs}\n";
        foreach ($this->info as $line) {
            echo $line . "\n";
        }
        return 0;
    }

    private function checkSuggest(EpodroznikClient $client): EpodroznikClient
    {
        $query = 'Warszawa';
        $lastError = null;

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $resp = $client->suggest($query, 'SOURCE');
                $suggestions = $resp['suggestions'] ?? null;
                if (!is_array($suggestions) || $suggestions === []) {
                    throw new \RuntimeException('empty suggestions for "' . $query . '"');
                }
                $this->info[] = 'suggest: ok (count=' . count($suggestions) . ')';
                return $client;
            } catch (\Throwable $e) {
                $lastError = $e;
                if ($attempt >= 2) {
                    break;
                }
                $this->resetRemoteSessionState();
                $client = EpodroznikClient::fromSession();
            }
        }

        throw ($lastError ?? new \RuntimeException('suggest failed for "' . $query . '"'));
    }

    private function checkSearch(EpodroznikClient $client): EpodroznikClient
    {
        // Keep the monitoring route lightweight and stable.
        $from = 'Warszawa';
        $to = 'Kutno';
        $date = date('Y-m-d');
        $lastError = null;

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $fromV = $this->resolvePlaceDataString($client, $from, 'SOURCE', 'CITIES');
                $toV = $this->resolvePlaceDataString($client, $to, 'DESTINATION', 'CITIES');

                $html = $client->search([
                    'fromV' => $fromV,
                    'toV' => $toV,
                    'fromQuery' => $from,
                    'toQuery' => $to,
                    'date' => $date,
                    'omitTime' => true,
                ]);

                $parser = new ResultsParser();
                $results = $parser->parseResultsPageHtml($html);
                $count = (int)($results['count'] ?? 0);
                if ($count < 1) {
                    throw new \RuntimeException('parsed 0 results for ' . $from . ' → ' . $to . ' on ' . $date);
                }
                $this->info[] = 'search: ok (count=' . $count . ', route="' . $from . ' → ' . $to . '", date=' . $date . ')';
                return $client;
            } catch (\Throwable $e) {
                $lastError = $e;
                if ($attempt >= 2) {
                    break;
                }
                $this->resetRemoteSessionState();
                $client = EpodroznikClient::fromSession();
            }
        }

        throw ($lastError ?? new \RuntimeException('search failed for ' . $from . ' → ' . $to));
    }

    private function checkTimetable(EpodroznikClient $client): void
    {
        // A stable stop that responds noticeably faster than the busiest Warsaw hubs.
        $stopId = '103250'; // Sieradz

        $html = $client->getGeneralTimetableStop($stopId, forceRefresh: true);
        $parser = new TimetableParser();
        $tt = $parser->parseGeneralTimetableHtml($html);

        $name = (string)($tt['stop']['name'] ?? '');
        $currentStopId = (string)($tt['stop']['stopId'] ?? '');
        $destCount = is_array($tt['destinations'] ?? null) ? count($tt['destinations']) : 0;

        if ($name === '' || $currentStopId === '') {
            throw new \RuntimeException('parsed empty stop name/stopId for stopId=' . $stopId);
        }
        $this->info[] = 'timetable: ok (stop="' . $name . '", destinations=' . $destCount . ')';
    }

    private function resolvePlaceDataString(EpodroznikClient $client, string $query, string $kind, string $type): string
    {
        $resp = $client->suggest($query, $kind, $type);
        $suggestions = $resp['suggestions'] ?? null;
        if (!is_array($suggestions) || $suggestions === []) {
            throw new \RuntimeException('suggest empty for "' . $query . '" (kind=' . $kind . ', type=' . $type . ')');
        }

        $q = mb_strtolower(trim($query));
        $first = null;
        foreach ($suggestions as $s) {
            if (!is_array($s)) {
                continue;
            }
            $pds = $s['placeDataString'] ?? null;
            if (!is_string($pds) || $pds === '') {
                continue;
            }
            $n = isset($s['n']) && is_string($s['n']) ? mb_strtolower(trim($s['n'])) : '';
            if ($first === null) {
                $first = $pds;
            }
            if ($n !== '' && $q !== '' && $n === $q) {
                return $pds;
            }
        }

        if ($first === null) {
            throw new \RuntimeException('suggestions missing placeDataString for "' . $query . '"');
        }
        return $first;
    }

    private function msg(\Throwable $e): string
    {
        $m = trim($e->getMessage());
        if ($m === '') {
            $m = get_class($e);
        }
        return $m;
    }

    private function addError(string $step, \Throwable $e): void
    {
        $this->errors[] = $step . ': ' . $this->msg($e);
        $this->errorKind = $this->mergeKind($this->errorKind, $this->classify($e));
    }

    private function mergeKind(string $a, string $b): string
    {
        $prio = [
            'unknown' => 0,
            'network' => 1,
            'upstream' => 2,
            'parser' => 3,
        ];
        $pa = $prio[$a] ?? 0;
        $pb = $prio[$b] ?? 0;
        return $pb > $pa ? $b : $a;
    }

    private function classify(\Throwable $e): string
    {
        $m = $e->getMessage();
        $m = is_string($m) ? $m : '';
        $m = mb_strtolower($m);

        if (
            str_contains($m, 'błąd połączenia z e-podroznik.pl')
            || str_contains($m, 'failed to connect')
            || str_contains($m, 'ssl')
            || str_contains($m, 'timed out')
            || str_contains($m, 'timeout')
        ) {
            return 'network';
        }

        if (str_contains($m, 'e-podroznik.pl zwrócił błąd http')) {
            return 'upstream';
        }

        if (
            str_contains($m, 'ochrona ddos')
            || str_contains($m, 'blacklist')
            || str_contains($m, 'blacklisted')
            || str_contains($m, 'denial of service')
            || str_contains($m, 'strona błędu')
            || str_contains($m, 'nieoczekiwany błąd')
        ) {
            return 'upstream';
        }

        if (
            str_contains($m, 'pusta odpowiedź')
            || str_contains($m, 'pustą odpowiedź')
            || str_contains($m, 'nie znaleziono danych')
            || str_contains($m, 'nie udało się odczytać')
            || str_contains($m, 'nie udało się pobrać tabtoken')
            || str_contains($m, 'błąd podpowiedzi')
            || str_contains($m, 'domdocument')
        ) {
            return 'parser';
        }

        return 'unknown';
    }

    private function resetRemoteSessionState(): void
    {
        $cookieJar = $_SESSION['ep_cookiejar'] ?? null;
        if (is_string($cookieJar) && $cookieJar !== '' && is_file($cookieJar)) {
            @unlink($cookieJar);
        }
        unset($_SESSION['ep_cookiejar'], $_SESSION['ep_tabToken']);
    }
}

$check = new UpstreamCheck();
exit($check->run());

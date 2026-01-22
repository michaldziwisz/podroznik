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

    public function run(): int
    {
        $startedAt = microtime(true);

        try {
            $client = EpodroznikClient::fromSession();
        } catch (\Throwable $e) {
            $this->errors[] = 'init: ' . $this->msg($e);
            $client = null;
        }

        if ($client instanceof EpodroznikClient) {
            try {
                $this->checkSuggest($client);
            } catch (\Throwable $e) {
                $this->errors[] = 'suggest: ' . $this->msg($e);
            }
        }

        if ($this->errors === [] && $client instanceof EpodroznikClient) {
            try {
                $this->checkSearch($client);
            } catch (\Throwable $e) {
                $this->errors[] = 'search: ' . $this->msg($e);
            }
        }

        if ($this->errors === [] && $client instanceof EpodroznikClient) {
            try {
                $this->checkTimetable($client);
            } catch (\Throwable $e) {
                $this->errors[] = 'timetable: ' . $this->msg($e);
            }
        }

        $elapsedMs = (int)round((microtime(true) - $startedAt) * 1000);

        if ($this->errors !== []) {
            fwrite(STDERR, "FAILED\n");
            fwrite(STDERR, "elapsed_ms={$elapsedMs}\n");
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

    private function checkSuggest(EpodroznikClient $client): void
    {
        $resp = $client->suggest('Warszawa', 'SOURCE');
        $suggestions = $resp['suggestions'] ?? null;
        if (!is_array($suggestions) || $suggestions === []) {
            throw new \RuntimeException('empty suggestions for "Warszawa"');
        }
        $this->info[] = 'suggest: ok (count=' . count($suggestions) . ')';
    }

    private function checkSearch(EpodroznikClient $client): void
    {
        $fromV = $this->resolvePlaceDataString($client, 'Warszawa', 'SOURCE', 'CITIES');
        $toV = $this->resolvePlaceDataString($client, 'Łódź', 'DESTINATION', 'CITIES');

        $date = date('Y-m-d');
        $html = $client->search([
            'fromV' => $fromV,
            'toV' => $toV,
            'fromQuery' => 'Warszawa',
            'toQuery' => 'Łódź',
            'date' => $date,
            'omitTime' => true,
        ]);

        $parser = new ResultsParser();
        $results = $parser->parseResultsPageHtml($html);
        $count = (int)($results['count'] ?? 0);
        if ($count < 1) {
            throw new \RuntimeException('parsed 0 results for Warszawa → Łódź on ' . $date);
        }
        $this->info[] = 'search: ok (count=' . $count . ', date=' . $date . ')';
    }

    private function checkTimetable(EpodroznikClient $client): void
    {
        // A stable, busy stop. If this ever changes, update the stopId.
        $stopId = '103163'; // WARSZAWA CENTRALNA

        $html = $client->getGeneralTimetableStop($stopId);
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
}

$check = new UpstreamCheck();
exit($check->run());

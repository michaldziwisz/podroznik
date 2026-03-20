#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI === 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    $savePath = sys_get_temp_dir() . '/podroznik-monitor-sessions';
    if (!is_dir($savePath)) {
        @mkdir($savePath, 0o700, true);
    }
    if (is_dir($savePath) && is_writable($savePath)) {
        ini_set('session.save_path', $savePath);
    }
    session_id('podroznik-timetable-warmer');
}

require __DIR__ . '/../../src/bootstrap.php';

use TyfloPodroznik\EpodroznikClient;
use TyfloPodroznik\TimetableParser;

final class TimetableWarmer
{
    /** @var list<string> */
    private array $errors = [];

    /** @var list<string> */
    private array $info = [];

    public function run(): int
    {
        $stopIds = $this->readStopIds();
        if ($stopIds === []) {
            echo "SKIP\n";
            echo "reason=no stop ids configured\n";
            return 0;
        }

        $startedAt = microtime(true);
        $client = EpodroznikClient::fromSession();
        $parser = new TimetableParser();

        foreach ($stopIds as $stopId) {
            $itemStartedAt = microtime(true);
            try {
                $html = $client->getGeneralTimetableStop($stopId, forceRefresh: true);
                $tt = $parser->parseGeneralTimetableHtml($html);
                $name = (string)($tt['stop']['name'] ?? '');
                $destCount = is_array($tt['destinations'] ?? null) ? count($tt['destinations']) : 0;
                if ($name === '') {
                    throw new \RuntimeException('parsed empty stop name');
                }
                $elapsedMs = (int)round((microtime(true) - $itemStartedAt) * 1000);
                $this->info[] = 'warm: ok (stopId=' . $stopId . ', stop="' . $name . '", destinations=' . $destCount . ', elapsed_ms=' . $elapsedMs . ')';
            } catch (\Throwable $e) {
                $elapsedMs = (int)round((microtime(true) - $itemStartedAt) * 1000);
                $msg = trim($e->getMessage());
                if ($msg === '') {
                    $msg = get_class($e);
                }
                $this->errors[] = 'warm: fail (stopId=' . $stopId . ', elapsed_ms=' . $elapsedMs . '): ' . $msg;
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

    /**
     * @return list<string>
     */
    private function readStopIds(): array
    {
        $raw = $_SERVER['PODROZNIK_TIMETABLE_WARM_STOP_IDS'] ?? getenv('PODROZNIK_TIMETABLE_WARM_STOP_IDS');
        if (!is_string($raw)) {
            return [];
        }

        $parts = preg_split('/[\s,;]+/', trim($raw)) ?: [];
        $stopIds = [];
        foreach ($parts as $part) {
            $stopId = trim((string)$part);
            if ($stopId === '' || !preg_match('/^\d+$/', $stopId)) {
                continue;
            }
            $stopIds[$stopId] = $stopId;
        }

        return array_values($stopIds);
    }
}

$app = new TimetableWarmer();
exit($app->run());

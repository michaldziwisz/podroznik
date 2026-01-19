#!/usr/bin/env php
<?php
declare(strict_types=1);

// Keep monitoring sessions separate from the web app session storage.
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

use TyfloPodroznik\SygnalistaClient;

final class SygnalistaReportCli
{
    public function run(): int
    {
        $opts = getopt('', [
            'kind::',
            'title:',
            'description::',
            'description-stdin',
            'dry-run',
        ]);

        $kind = isset($opts['kind']) && is_string($opts['kind']) ? trim($opts['kind']) : 'bug';
        if (!in_array($kind, ['bug', 'suggestion'], true)) {
            $kind = 'bug';
        }

        $title = isset($opts['title']) && is_string($opts['title']) ? trim($opts['title']) : '';
        if ($title === '') {
            fwrite(STDERR, "Missing --title\n");
            return 2;
        }

        $description = '';
        if (isset($opts['description-stdin'])) {
            $description = (string)stream_get_contents(STDIN);
        } elseif (isset($opts['description']) && is_string($opts['description'])) {
            $description = (string)$opts['description'];
        }
        $description = trim($description);
        if ($description === '') {
            fwrite(STDERR, "Missing description (use --description or --description-stdin)\n");
            return 2;
        }

        $diagnostics = [
            'monitor' => [
                'timestamp' => date(DATE_ATOM),
                'hostname' => $this->hostname(),
                'script' => 'scripts/monitoring/report_to_sygnalista.php',
            ],
            'server' => [
                'php' => PHP_VERSION,
            ],
        ];

        $payloadPreview = [
            'kind' => $kind,
            'title' => $title,
            'description' => $description,
            'diagnostics' => $diagnostics,
        ];

        if (isset($opts['dry-run'])) {
            echo json_encode(['dryRun' => true, 'payload' => $payloadPreview], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
            return 0;
        }

        $client = SygnalistaClient::fromEnv();
        if ($client === null) {
            fwrite(STDERR, "Sygnalista is not configured (SYGNALISTA_BASE_URL / SYGNALISTA_APP_ID).\n");
            return 3;
        }

        $result = $client->sendReport(
            kind: $kind,
            title: $title,
            description: $description,
            email: null,
            diagnostics: $diagnostics,
            forwardedFor: '',
        );

        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        return 0;
    }

    private function hostname(): string
    {
        $h = gethostname();
        if (is_string($h) && $h !== '') {
            return $h;
        }
        $out = trim((string)@shell_exec('hostname'));
        return $out !== '' ? $out : 'unknown';
    }
}

$cli = new SygnalistaReportCli();
exit($cli->run());


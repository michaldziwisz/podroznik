<?php
declare(strict_types=1);

namespace TyfloPodroznik;

final class SygnalistaClient
{
    private function __construct(
        private readonly string $baseUrl,
        private readonly string $appId,
        private readonly ?string $appVersion,
        private readonly ?string $appBuild,
        private readonly ?string $appChannel,
        private readonly ?string $appToken,
    ) {
    }

    public static function fromEnv(): ?self
    {
        $env = static function (string $name): string {
            $v = $_SERVER[$name] ?? getenv($name);
            return is_string($v) ? $v : '';
        };

        $baseUrl = trim($env('SYGNALISTA_BASE_URL'));
        if ($baseUrl === '') {
            $baseUrl = 'https://sygnalista.michaldziwisz.workers.dev';
        }
        $baseUrl = rtrim($baseUrl, '/');

        $appId = trim($env('SYGNALISTA_APP_ID'));
        if ($appId === '') {
            $appId = 'podroznik';
        }

        $appToken = trim($env('SYGNALISTA_APP_TOKEN'));
        if ($appToken === '') {
            $appToken = null;
        }

        $appVersion = trim($env('SYGNALISTA_APP_VERSION'));
        if ($appVersion === '') {
            $appVersion = null;
        }
        $appBuild = trim($env('SYGNALISTA_APP_BUILD'));
        if ($appBuild === '') {
            $appBuild = null;
        }
        $appChannel = trim($env('SYGNALISTA_APP_CHANNEL'));
        if ($appChannel === '') {
            $appChannel = null;
        }

        if (!preg_match('/^https?:\\/\\//i', $baseUrl)) {
            return null;
        }
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/i', $appId)) {
            return null;
        }

        return new self(
            baseUrl: $baseUrl,
            appId: $appId,
            appVersion: $appVersion,
            appBuild: $appBuild,
            appChannel: $appChannel,
            appToken: $appToken,
        );
    }

    public function sendReport(
        string $kind,
        string $title,
        string $description,
        ?string $email,
        array $diagnostics,
        string $forwardedFor = '',
    ): array {
        if (!in_array($kind, ['bug', 'suggestion'], true)) {
            throw new \InvalidArgumentException('Nieprawidłowy typ zgłoszenia.');
        }
        $title = trim($title);
        $description = trim($description);
        if ($title === '' || $description === '') {
            throw new \InvalidArgumentException('Brak tytułu lub opisu zgłoszenia.');
        }

        $body = [
            'app' => array_filter([
                'id' => $this->appId,
                'version' => $this->appVersion,
                'build' => $this->appBuild,
                'channel' => $this->appChannel,
            ], static fn($v): bool => is_string($v) && $v !== ''),
            'kind' => $kind,
            'title' => $title,
            'description' => $description,
            'diagnostics' => $diagnostics,
        ];

        if (is_string($email) && $email !== '') {
            $body['email'] = $email;
        }

        $json = json_encode($body, JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || $json === '') {
            throw new \RuntimeException('Nie udało się zbudować payload JSON.');
        }

        $url = $this->baseUrl . '/v1/report';
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Nie można zainicjować cURL.');
        }

        $headers = [
            'Content-Type: application/json; charset=utf-8',
            'Accept: application/json',
        ];
        if ($this->appToken !== null) {
            $headers[] = 'x-sygnalista-app-token: ' . $this->appToken;
        }
        if ($forwardedFor !== '') {
            $headers[] = 'X-Forwarded-For: ' . $forwardedFor;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 18,
            CURLOPT_USERAGENT => 'PodroznikTyflo/1.0 (+https://podroznik.tyflo.eu.org)',
        ]);

        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('Błąd połączenia z sygnalistą: ' . $err);
        }
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $decoded = json_decode((string)$resp, true);
        if ($code >= 400) {
            $msg = null;
            if (is_array($decoded) && isset($decoded['error']['message']) && is_string($decoded['error']['message'])) {
                $msg = $decoded['error']['message'];
            }
            if (!is_string($msg) || $msg === '') {
                $msg = 'HTTP ' . $code;
            }
            throw new \RuntimeException($msg);
        }

        return is_array($decoded) ? $decoded : [];
    }
}

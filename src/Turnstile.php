<?php
declare(strict_types=1);

namespace TyfloPodroznik;

final class Turnstile
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    private function __construct(
        private readonly string $siteKey,
        private readonly string $secretKey,
        private readonly int $ttlSeconds,
    ) {
    }

    public static function fromEnv(): ?self
    {
        $env = static function (string $name): string {
            $v = $_SERVER[$name] ?? getenv($name);
            return is_string($v) ? trim($v) : '';
        };

        $siteKey = $env('TURNSTILE_SITE_KEY');
        $secretKey = $env('TURNSTILE_SECRET_KEY');
        if ($siteKey === '' || $secretKey === '') {
            return null;
        }

        $ttl = (int)($env('TURNSTILE_TTL_SECONDS') !== '' ? $env('TURNSTILE_TTL_SECONDS') : '600');
        if ($ttl <= 0) {
            $ttl = 600;
        }

        return new self(
            siteKey: $siteKey,
            secretKey: $secretKey,
            ttlSeconds: $ttl,
        );
    }

    public function siteKey(): string
    {
        return $this->siteKey;
    }

    public function isSessionValid(string $clientIp, string $userAgent): bool
    {
        $until = $_SESSION['turnstile_ok_until'] ?? null;
        if (is_string($until) && ctype_digit($until)) {
            $until = (int)$until;
        }
        if (!is_int($until) || $until <= 0 || $until < time()) {
            return false;
        }

        $expectedIp = (string)($_SESSION['turnstile_ok_ip'] ?? '');
        if ($expectedIp !== '' && $clientIp !== '' && $expectedIp !== $clientIp) {
            return false;
        }
        $expectedUa = (string)($_SESSION['turnstile_ok_ua'] ?? '');
        if ($expectedUa !== '' && $userAgent !== '' && $expectedUa !== $userAgent) {
            return false;
        }

        return true;
    }

    public function markSessionValid(string $clientIp, string $userAgent): void
    {
        $_SESSION['turnstile_ok_until'] = time() + $this->ttlSeconds;
        $_SESSION['turnstile_ok_ip'] = $clientIp;
        $_SESSION['turnstile_ok_ua'] = $userAgent;
    }

    public function verifyToken(string $token, string $clientIp = ''): bool
    {
        $token = trim($token);
        if ($token === '') {
            return false;
        }

        $data = [
            'secret' => $this->secretKey,
            'response' => $token,
        ];
        if ($clientIp !== '') {
            $data['remoteip'] = $clientIp;
        }

        $ch = curl_init(self::VERIFY_URL);
        if ($ch === false) {
            throw new \RuntimeException('Nie można zainicjować cURL.');
        }

        $headers = [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'PodroznikTyflo/1.0 (+https://podroznik.tyflo.eu.org)',
        ]);

        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('Błąd połączenia z Turnstile: ' . $err);
        }
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($code >= 400) {
            return false;
        }

        $decoded = json_decode((string)$resp, true);
        return is_array($decoded) && (($decoded['success'] ?? false) === true);
    }
}

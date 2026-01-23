<?php
declare(strict_types=1);

namespace TyfloPodroznik;

final class Html
{
    public static function epodroznikUrl(?string $href): ?string
    {
        if ($href === null) {
            return null;
        }
        $href = trim($href);
        if ($href === '') {
            return null;
        }

        if (str_starts_with($href, '/')) {
            return 'https://www.e-podroznik.pl' . $href;
        }

        $parts = parse_url($href);
        if (!is_array($parts)) {
            return null;
        }
        $scheme = isset($parts['scheme']) && is_string($parts['scheme']) ? strtolower($parts['scheme']) : '';
        $host = isset($parts['host']) && is_string($parts['host']) ? strtolower($parts['host']) : '';
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }
        if ($host === 'e-podroznik.pl' || str_ends_with($host, '.e-podroznik.pl')) {
            return $href;
        }
        return null;
    }

    public static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function url(string $path, array $query = []): string
    {
        if ($query === []) {
            return $path;
        }
        return $path . '?' . http_build_query($query);
    }

    public static function redirect(string $to, int $statusCode = 303): never
    {
        $to = self::sanitizeRedirectTarget($to);
        header('Location: ' . $to, true, $statusCode);
        exit;
    }

    private static function sanitizeRedirectTarget(string $to): string
    {
        $to = trim($to);
        if ($to === '' || strpbrk($to, "\r\n") !== false) {
            return '/';
        }
        if (preg_match('/^https?:\\/\\//i', $to) === 1) {
            return $to;
        }
        if (!str_starts_with($to, '/')) {
            return '/';
        }
        return $to;
    }

    public static function text(?string $value): string
    {
        return self::e($value ?? '');
    }
}

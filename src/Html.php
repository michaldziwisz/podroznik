<?php
declare(strict_types=1);

namespace TyfloPodroznik;

final class Html
{
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

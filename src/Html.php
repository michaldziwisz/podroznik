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
        header('Location: ' . $to, true, $statusCode);
        exit;
    }

    public static function text(?string $value): string
    {
        return self::e($value ?? '');
    }
}


<?php
declare(strict_types=1);

namespace TyfloPodroznik;

final class Input
{
    public static function normalizeDateYmd(string $date): ?string
    {
        $date = trim($date);
        if ($date === '') {
            return null;
        }

        if (preg_match('/^(\\d{4})-(\\d{2})-(\\d{2})$/', $date, $m)) {
            $y = (int)$m[1];
            $mo = (int)$m[2];
            $d = (int)$m[3];
            if (!checkdate($mo, $d, $y)) {
                return null;
            }
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }

        if (preg_match('/^(\\d{1,2})\\.(\\d{1,2})\\.(\\d{4})$/', $date, $m)) {
            $d = (int)$m[1];
            $mo = (int)$m[2];
            $y = (int)$m[3];
            if (!checkdate($mo, $d, $y)) {
                return null;
            }
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }

        if (preg_match('/^(\\d{4})(\\d{2})(\\d{2})$/', $date, $m)) {
            $y = (int)$m[1];
            $mo = (int)$m[2];
            $d = (int)$m[3];
            if (!checkdate($mo, $d, $y)) {
                return null;
            }
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }

        return null;
    }

    public static function normalizeTimeHm(string $time): ?string
    {
        $time = trim($time);
        if ($time === '') {
            return null;
        }

        $time = preg_replace('/[^0-9:.,]/u', '', $time) ?? $time;
        $time = str_replace([',', '.'], ':', $time);

        if (preg_match('/^(\\d{1,2}):(\\d{1,2})(?::(\\d{1,2}))?(?:\\.\\d+)?$/', $time, $m)) {
            $h = (int)$m[1];
            $min = (int)$m[2];
            if ($h < 0 || $h > 23 || $min < 0 || $min > 59) {
                return null;
            }
            return sprintf('%02d:%02d', $h, $min);
        }

        if (preg_match('/^\\d{1,4}$/', $time)) {
            $digits = $time;
            $len = strlen($digits);
            if ($len <= 2) {
                $h = (int)$digits;
                $min = 0;
            } else {
                $min = (int)substr($digits, -2);
                $h = (int)substr($digits, 0, -2);
            }
            if ($h < 0 || $h > 23 || $min < 0 || $min > 59) {
                return null;
            }
            return sprintf('%02d:%02d', $h, $min);
        }

        return null;
    }
}

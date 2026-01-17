<?php
declare(strict_types=1);

namespace TyfloPodroznik;

final class TimetableRules
{
    public static function filter(array $timetable, array $filters): array
    {
        $dateYmd = (string)($filters['date'] ?? '');
        $fromTime = (string)($filters['from_time'] ?? '');
        $toTime = (string)($filters['to_time'] ?? '');

        $fromMin = self::timeToMinutesOrNull($fromTime);
        $toMin = self::timeToMinutesOrNull($toTime);

        $groups = $timetable['destinations'] ?? [];
        if (!is_array($groups)) {
            $groups = [];
        }

        $filteredGroups = [];
        foreach ($groups as $g) {
            if (!is_array($g)) {
                continue;
            }
            $deps = $g['departures'] ?? [];
            if (!is_array($deps)) {
                $deps = [];
            }

            $outDeps = [];
            foreach ($deps as $d) {
                if (!is_array($d)) {
                    continue;
                }
                if (!self::matchesTimeWindow((string)($d['time'] ?? ''), $fromMin, $toMin)) {
                    continue;
                }

                $infoItems = $d['infoItems'] ?? [];
                if (!is_array($infoItems)) {
                    $infoItems = [];
                }
                if ($dateYmd !== '' && !self::runsOnDate($infoItems, $dateYmd)) {
                    continue;
                }

                $outDeps[] = $d;
            }

            if ($outDeps === []) {
                continue;
            }

            $g['departures'] = $outDeps;
            $filteredGroups[] = $g;
        }

        $timetable['destinations'] = $filteredGroups;
        return $timetable;
    }

    private static function matchesTimeWindow(string $time, ?int $fromMin, ?int $toMin): bool
    {
        $t = self::timeToMinutesOrNull($time);
        if ($t === null) {
            return false;
        }
        if ($fromMin !== null && $t < $fromMin) {
            return false;
        }
        if ($toMin !== null && $t > $toMin) {
            return false;
        }
        return true;
    }

    private static function timeToMinutesOrNull(string $timeHm): ?int
    {
        $timeHm = trim($timeHm);
        if (!preg_match('/^(\\d{1,2}):(\\d{2})$/', $timeHm, $m)) {
            return null;
        }
        $h = (int)$m[1];
        $min = (int)$m[2];
        if ($h < 0 || $h > 23 || $min < 0 || $min > 59) {
            return null;
        }
        return ($h * 60) + $min;
    }

    private static function runsOnDate(array $infoItems, string $dateYmd): bool
    {
        $dateYmd = Input::normalizeDateYmd($dateYmd) ?? '';
        if ($dateYmd === '') {
            return true;
        }

        try {
            $dt = new \DateTimeImmutable($dateYmd);
        } catch (\Throwable) {
            return true;
        }
        $dow = (int)$dt->format('N'); // 1=Mon .. 7=Sun
        $year = (int)$dt->format('Y');
        $isHoliday = self::isPolishHoliday($dt);

        $items = [];
        foreach ($infoItems as $it) {
            if (!is_string($it)) {
                continue;
            }
            $t = trim($it);
            if ($t !== '') {
                $items[] = $t;
            }
        }

        $inclusions = [];
        $exclusions = [];
        foreach ($items as $it) {
            $low = mb_strtolower($it);
            if (str_contains($low, 'nie kursuje')) {
                $exclusions[] = $it;
                continue;
            }
            if (str_contains($low, 'kursuje')) {
                $inclusions[] = $it;
            }
        }

        $include = true;
        $hasParsedInclusion = false;
        $matchedInclusion = false;
        foreach ($inclusions as $it) {
            $res = self::matchesInclusionRule($it, $dateYmd, $dow, $year, $isHoliday);
            if ($res === null) {
                continue;
            }
            $hasParsedInclusion = true;
            if ($res) {
                $matchedInclusion = true;
                break;
            }
        }
        if ($hasParsedInclusion) {
            $include = $matchedInclusion;
        }

        if (!$include) {
            return false;
        }

        foreach ($exclusions as $it) {
            $res = self::matchesExclusionRule($it, $dateYmd, $dow, $year, $isHoliday);
            if ($res === true) {
                return false;
            }
        }

        return true;
    }

    private static function matchesInclusionRule(string $text, string $dateYmd, int $dow, int $contextYear, bool $isHoliday): ?bool
    {
        $ranges = self::extractDateRangesYmd($text, $contextYear);
        $singleDates = self::extractSingleDatesYmd($text, $contextYear);

        $hasDateConstraint = ($ranges !== [] || $singleDates !== []);
        $dateOk = true;
        if ($ranges !== []) {
            $dateOk = self::dateInAnyRange($dateYmd, $ranges);
        } elseif ($singleDates !== []) {
            $dateOk = in_array($dateYmd, $singleDates, true);
        }

        if (!$dateOk) {
            return false;
        }

        if (str_contains(mb_strtolower($text), 'codziennie')) {
            return $dateOk;
        }

        $daysClause = self::extractDaysClause($text);
        $dayRule = $daysClause !== null ? self::parseDayRule($daysClause) : null;

        if ($dayRule === null) {
            if ($hasDateConstraint) {
                return true;
            }
            return null;
        }

        return self::matchesDayRule($dayRule, $dow, $isHoliday);
    }

    private static function matchesExclusionRule(string $text, string $dateYmd, int $dow, int $contextYear, bool $isHoliday): ?bool
    {
        $ranges = self::extractDateRangesYmd($text, $contextYear);
        $singleDates = self::extractSingleDatesYmd($text, $contextYear);

        $hasDateConstraint = ($ranges !== [] || $singleDates !== []);
        $dateOk = true;
        if ($ranges !== []) {
            $dateOk = self::dateInAnyRange($dateYmd, $ranges);
        } elseif ($singleDates !== []) {
            $dateOk = in_array($dateYmd, $singleDates, true);
        }

        if ($hasDateConstraint && !$dateOk) {
            return false;
        }

        if (str_contains(mb_strtolower($text), 'codziennie')) {
            return $hasDateConstraint ? $dateOk : true;
        }

        $daysClause = self::extractDaysClause($text);
        $dayRule = $daysClause !== null ? self::parseDayRule($daysClause) : null;

        if ($dayRule === null) {
            return $hasDateConstraint ? $dateOk : null;
        }

        return $dateOk && self::matchesDayRule($dayRule, $dow, $isHoliday);
    }

    /**
     * Extracts "pn - pt, sb" part from phrases like "kursuje w pn - pt w okresie ...".
     */
    private static function extractDaysClause(string $text): ?string
    {
        $t = mb_strtolower($text);
        $t = preg_split('/\\bw\\s+okresie\\b/iu', $t, 2)[0] ?? $t;
        $t = trim($t);
        if ($t === '') {
            return null;
        }
        if (preg_match('/\\b(?:w|we)\\b\\s+(.+)/iu', $t, $m)) {
            $v = trim((string)$m[1]);
            return $v !== '' ? $v : null;
        }

        if (str_contains($t, 'dni robocze')) {
            return 'dni robocze';
        }
        if (str_contains($t, 'dni wolne')) {
            return 'dni wolne';
        }

        return null;
    }

    /**
     * @return array{dows: int[], includeHolidays: bool, excludeHolidays: bool}|null
     */
    private static function parseDayRule(string $daysClause): ?array
    {
        $c = mb_strtolower(trim($daysClause));
        if ($c === '') {
            return null;
        }

        if ($c === 'dni robocze' || str_contains($c, 'dni robocze')) {
            return [
                'dows' => [1, 2, 3, 4, 5],
                'includeHolidays' => false,
                'excludeHolidays' => true,
            ];
        }
        if ($c === 'dni wolne' || str_contains($c, 'dni wolne')) {
            return [
                'dows' => [6, 7],
                'includeHolidays' => true,
                'excludeHolidays' => false,
            ];
        }

        $includeHolidays = (bool)preg_match('/\\b(?:święt|swiet)\\w*\\b/iu', $c);
        $excludeHolidays = (bool)preg_match('/\\b(?:oprócz|oprocz|z\\s+wyjątkiem|z\\s+wyjatkiem)\\b[^\\n]{0,40}\\b(?:święt|swiet)\\w*\\b/iu', $c);

        $c = preg_replace('/\\s+(?:i|oraz)\\s+/u', ', ', $c) ?? $c;
        $parts = preg_split('/\\s*,\\s*/u', $c) ?: [];
        $set = [];
        $parsedAny = false;

        foreach ($parts as $p) {
            $p = trim((string)$p);
            if ($p === '') {
                continue;
            }

            if (str_contains($p, '-')) {
                $rangeParts = preg_split('/\\s*-\\s*/u', $p, 2) ?: [];
                $start = self::dayTokenToIndex($rangeParts[0] ?? '');
                $end = self::dayTokenToIndex($rangeParts[1] ?? '');
                if ($start === null || $end === null) {
                    continue;
                }
                $parsedAny = true;
                foreach (self::expandDowRange($start, $end) as $d) {
                    $set[$d] = true;
                }
                continue;
            }

            $day = self::dayTokenToIndex($p);
            if ($day === null) {
                continue;
            }
            $parsedAny = true;
            $set[$day] = true;
        }

        if (!$parsedAny && !$includeHolidays) {
            return null;
        }

        $out = array_keys($set);
        sort($out);
        return [
            'dows' => $out,
            'includeHolidays' => $includeHolidays,
            'excludeHolidays' => $excludeHolidays,
        ];
    }

    private static function dayTokenToIndex(string $token): ?int
    {
        $t = mb_strtolower(trim($token));
        $t = preg_replace('/[^a-ząćęłńóśźż]/u', '', $t) ?? $t;
        $t = strtr($t, [
            'ą' => 'a',
            'ć' => 'c',
            'ę' => 'e',
            'ł' => 'l',
            'ń' => 'n',
            'ó' => 'o',
            'ś' => 's',
            'ź' => 'z',
            'ż' => 'z',
        ]);

        if ($t === 'pn' || str_starts_with($t, 'pon')) {
            return 1;
        }
        if ($t === 'wt' || str_starts_with($t, 'wto') || str_starts_with($t, 'wtor')) {
            return 2;
        }
        if ($t === 'sr' || str_starts_with($t, 'sro') || str_starts_with($t, 'srod')) {
            return 3;
        }
        if ($t === 'cz' || str_starts_with($t, 'czw') || str_starts_with($t, 'czwar')) {
            return 4;
        }
        if ($t === 'pt' || str_starts_with($t, 'pia') || str_starts_with($t, 'piat')) {
            return 5;
        }
        if ($t === 'sb' || str_starts_with($t, 'sob')) {
            return 6;
        }
        if ($t === 'nd' || str_starts_with($t, 'nie') || str_starts_with($t, 'nied')) {
            return 7;
        }

        return null;
    }

    private static function expandDowRange(int $start, int $end): array
    {
        if ($start === $end) {
            return [$start];
        }
        $out = [];
        $d = $start;
        while (true) {
            $out[] = $d;
            if ($d === $end) {
                break;
            }
            $d++;
            if ($d === 8) {
                $d = 1;
            }
        }
        return $out;
    }

    private static function matchesDayRule(array $dayRule, int $dow, bool $isHoliday): bool
    {
        $dows = $dayRule['dows'] ?? [];
        $includeHolidays = (bool)($dayRule['includeHolidays'] ?? false);
        $excludeHolidays = (bool)($dayRule['excludeHolidays'] ?? false);

        if ($includeHolidays && $isHoliday) {
            return true;
        }

        if (is_array($dows) && in_array($dow, $dows, true)) {
            if ($excludeHolidays && $isHoliday) {
                return false;
            }
            return true;
        }

        return false;
    }

    private static function extractDateRangesYmd(string $text, ?int $contextYear = null): array
    {
        $out = [];
        if (preg_match_all('/(\\d{1,2}\\.\\d{1,2}\\.\\d{4})\\s*[-–]\\s*(\\d{1,2}\\.\\d{1,2}\\.\\d{4})/u', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $mm) {
                $start = self::dmyToYmd((string)($mm[1] ?? ''));
                $end = self::dmyToYmd((string)($mm[2] ?? ''));
                if ($start === null || $end === null) {
                    continue;
                }
                if ($start > $end) {
                    [$start, $end] = [$end, $start];
                }
                $out[] = [$start, $end];
            }
        }

        if (preg_match_all('/(\\d{1,2})\\.(XII|XI|X|IX|VIII|VII|VI|V|IV|III|II|I)(?![IVX])(?:\\.(\\d{4}))?\\s*[-–]\\s*(\\d{1,2})\\.(XII|XI|X|IX|VIII|VII|VI|V|IV|III|II|I)(?![IVX])(?:\\.(\\d{4}))?/iu', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $mm) {
                $d1 = (int)($mm[1] ?? 0);
                $m1 = self::romanToMonth((string)($mm[2] ?? ''));
                $y1 = isset($mm[3]) && $mm[3] !== '' ? (int)$mm[3] : null;

                $d2 = (int)($mm[4] ?? 0);
                $m2 = self::romanToMonth((string)($mm[5] ?? ''));
                $y2 = isset($mm[6]) && $mm[6] !== '' ? (int)$mm[6] : null;

                if ($m1 === null || $m2 === null) {
                    continue;
                }

                if ($y1 === null && $y2 !== null) {
                    $y1 = $y2;
                }
                if ($y2 === null && $y1 !== null) {
                    $y2 = $y1;
                }

                if ($y1 === null && $y2 === null) {
                    if ($contextYear === null) {
                        continue;
                    }

                    $startThis = self::ymd($contextYear, $m1, $d1);
                    $endThis = self::ymd($contextYear, $m2, $d2);
                    if ($startThis === null || $endThis === null) {
                        continue;
                    }

                    if ($startThis <= $endThis) {
                        $out[] = [$startThis, $endThis];
                        continue;
                    }

                    $startPrev = self::ymd($contextYear - 1, $m1, $d1);
                    $endNext = self::ymd($contextYear + 1, $m2, $d2);
                    if ($startPrev !== null) {
                        $out[] = [$startPrev, $endThis];
                    }
                    if ($endNext !== null) {
                        $out[] = [$startThis, $endNext];
                    }
                    continue;
                }

                $start = self::ymd((int)$y1, $m1, $d1);
                $end = self::ymd((int)$y2, $m2, $d2);
                if ($start === null || $end === null) {
                    continue;
                }
                if ($start > $end) {
                    [$start, $end] = [$end, $start];
                }
                $out[] = [$start, $end];
            }
        }

        return $out;
    }

    private static function extractSingleDatesYmd(string $text, ?int $contextYear = null): array
    {
        $out = [];
        if (preg_match_all('/\\b(\\d{1,2}\\.\\d{1,2}\\.\\d{4})\\b/u', $text, $m)) {
            foreach ($m[1] as $dmy) {
                $ymd = self::dmyToYmd((string)$dmy);
                if ($ymd !== null) {
                    $out[$ymd] = true;
                }
            }
        }

        if (preg_match_all('/\\b(\\d{1,2})\\.(XII|XI|X|IX|VIII|VII|VI|V|IV|III|II|I)(?![IVX])(?:\\.(\\d{4}))?\\b/iu', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $mm) {
                $d = (int)($mm[1] ?? 0);
                $mo = self::romanToMonth((string)($mm[2] ?? ''));
                $y = isset($mm[3]) && $mm[3] !== '' ? (int)$mm[3] : $contextYear;
                if ($mo === null || !is_int($y)) {
                    continue;
                }
                $ymd = self::ymd($y, $mo, $d);
                if ($ymd !== null) {
                    $out[$ymd] = true;
                }
            }
        }

        return array_keys($out);
    }

    private static function dmyToYmd(string $dmy): ?string
    {
        if (!preg_match('/^(\\d{1,2})\\.(\\d{1,2})\\.(\\d{4})$/', $dmy, $m)) {
            return null;
        }
        $d = (int)$m[1];
        $mo = (int)$m[2];
        $y = (int)$m[3];
        return self::ymd($y, $mo, $d);
    }

    private static function ymd(int $y, int $mo, int $d): ?string
    {
        if (!checkdate($mo, $d, $y)) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }

    private static function romanToMonth(string $roman): ?int
    {
        $r = strtoupper(trim($roman));
        return match ($r) {
            'I' => 1,
            'II' => 2,
            'III' => 3,
            'IV' => 4,
            'V' => 5,
            'VI' => 6,
            'VII' => 7,
            'VIII' => 8,
            'IX' => 9,
            'X' => 10,
            'XI' => 11,
            'XII' => 12,
            default => null,
        };
    }

    private static function isPolishHoliday(\DateTimeImmutable $date): bool
    {
        $year = (int)$date->format('Y');
        $ymd = $date->format('Y-m-d');
        $set = self::polishHolidaySet($year);
        return isset($set[$ymd]);
    }

    /**
     * @return array<string, true>
     */
    private static function polishHolidaySet(int $year): array
    {
        static $cache = [];
        if (isset($cache[$year]) && is_array($cache[$year])) {
            return $cache[$year];
        }

        $set = [];
        foreach ([
            sprintf('%04d-01-01', $year),
            sprintf('%04d-01-06', $year),
            sprintf('%04d-05-01', $year),
            sprintf('%04d-05-03', $year),
            sprintf('%04d-08-15', $year),
            sprintf('%04d-11-01', $year),
            sprintf('%04d-11-11', $year),
            sprintf('%04d-12-25', $year),
            sprintf('%04d-12-26', $year),
        ] as $d) {
            $set[$d] = true;
        }

        $easter = self::easterSunday($year);
        $set[$easter->format('Y-m-d')] = true;
        $set[$easter->modify('+1 day')->format('Y-m-d')] = true; // Easter Monday
        $set[$easter->modify('+49 days')->format('Y-m-d')] = true; // Pentecost (Sunday)
        $set[$easter->modify('+60 days')->format('Y-m-d')] = true; // Corpus Christi

        $cache[$year] = $set;
        return $set;
    }

    private static function easterSunday(int $year): \DateTimeImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);

        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }

    /**
     * @param array<int, array{0:string,1:string}> $ranges
     */
    private static function dateInAnyRange(string $dateYmd, array $ranges): bool
    {
        foreach ($ranges as $r) {
            $start = $r[0] ?? '';
            $end = $r[1] ?? '';
            if ($start !== '' && $end !== '' && $dateYmd >= $start && $dateYmd <= $end) {
                return true;
            }
        }
        return false;
    }
}

<?php

declare(strict_types=1);

namespace App\Schedule\Domain;

/**
 * Time string parsing/formatting helpers. Stateless.
 */
final class TimeRange
{
    /** "09:30" → 9.5, garbage → 0.0 */
    public static function toHours(string $hhmm): float
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $hhmm, $m)) return 0.0;
        return (int)$m[1] + ((int)$m[2]) / 60;
    }

    /**
     * Accepts "09:00-17:00", "09-17", "09:00–17:00", "09–17:30", etc.
     * Returns [startHours, endHours] or null.
     */
    public static function parse(string $s): ?array
    {
        if ($s === '') return null;
        if (!preg_match('/^(\d{1,2})(?::(\d{2}))?[\s\-–—]+(\d{1,2})(?::(\d{2}))?$/u', $s, $m)) {
            return null;
        }
        return [
            (int)$m[1] + ((int)($m[2] ?? 0)) / 60,
            (int)$m[3] + ((int)($m[4] ?? 0)) / 60,
        ];
    }

    /** "09:00" → "09"  ;  "09:30" → "09:30" */
    public static function shortHhmm(string $hhmm): string
    {
        return str_ends_with($hhmm, ':00') ? substr($hhmm, 0, 2) : $hhmm;
    }

    public static function shortRange(string $start, string $end): string
    {
        return self::shortHhmm($start) . '–' . self::shortHhmm($end);
    }
}

<?php

declare(strict_types=1);

namespace App\Payday3\Domain;

/**
 * Poster timestamps arrive as unix seconds, unix MILLIseconds, or a
 * date string depending on the endpoint. One parser for all of them.
 *
 * The ms threshold used to differ between copies: 20_000_000_000 in
 * PosterSyncService vs 2_000_000_000_000 in FinanceTransferService — the
 * latter never fired for real ms values (now ≈ 1.8e12) and produced dates
 * in the year ~57 000. 2e10 s is the year 2603, so anything above it is ms.
 */
final class PosterTime
{
    private const MS_THRESHOLD = 20_000_000_000;

    /** @return int|null unix seconds, null when missing/unparseable */
    public static function toUnix(mixed $raw): ?int
    {
        if (is_int($raw) || is_float($raw) || (is_string($raw) && is_numeric(trim($raw)))) {
            $n = (int)round((float)$raw);
            if ($n > self::MS_THRESHOLD) $n = (int)round($n / 1000);
            return $n > 0 ? $n : null;
        }
        if (is_string($raw) && trim($raw) !== '') {
            $t = strtotime($raw);
            return ($t !== false && $t > 0) ? $t : null;
        }
        return null;
    }
}

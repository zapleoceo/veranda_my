<?php

declare(strict_types=1);

namespace App\Cashflow\Domain;

/**
 * Poster → canonical integer VND (đồng) adapters, one per endpoint family.
 *
 * Poster returns money in DIFFERENT units depending on the endpoint. Getting
 * this wrong is the single most likely source of bugs (spec §3.2), so every
 * converter is explicit and pinned to real values captured from live Poster on
 * 2026-08-07 (see scripts/cashflow/verify_poster.php):
 *
 *   dash.getAnalytics            counters.revenue "675355750.0000" → whole VND    (no ÷100)
 *   dash.getCategoriesSales      revenue          "235000000"      → cents ×100    (÷100)
 *   dash.getTransactions         payed_sum        "143500000"      → cents ×100    (÷100)
 *   finance.getTransactions      amount           "-572500000"     → cents, signed (÷100)
 *   transactions.getTransactions payed_sum        "1435000.00"     → whole VND     (no ÷100) [v3]
 *
 * Every amount in the report is an int VND. Never float, never a money string.
 */
final class PosterMoney
{
    /** dash.getAnalytics counters.* — already whole đồng (may carry a ".0000" fraction). */
    public static function fromAnalytics(int|float|string $raw): int
    {
        return self::toInt($raw);
    }

    /** transactions.getTransactions (v3 REST) — whole đồng as a decimal string. */
    public static function fromTransactionsV3(int|float|string $raw): int
    {
        return self::toInt($raw);
    }

    /** dash.getCategoriesSales / dash.getTransactions — integer cents (÷100). */
    public static function fromDashCents(int|float|string $raw): int
    {
        return intdiv(self::toInt($raw), 100);
    }

    /** finance.getTransactions amount — signed integer cents (÷100, sign preserved). */
    public static function fromFinanceCents(int|float|string $raw): int
    {
        return intdiv(self::toInt($raw), 100);
    }

    /**
     * Parse a Poster money scalar to an int without float rounding error:
     * pure-integer strings are taken verbatim; decimal strings (in practice
     * only ".0000"-style zero fractions) are rounded to the đồng.
     */
    private static function toInt(int|float|string $raw): int
    {
        if (is_int($raw)) {
            return $raw;
        }
        $s = trim((string) $raw);
        if ($s === '' || $s === '-') {
            return 0;
        }
        if (preg_match('/^-?\d+$/', $s) === 1) {
            return (int) $s;
        }
        return (int) round((float) $s);
    }
}

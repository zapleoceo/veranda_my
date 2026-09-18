<?php

declare(strict_types=1);

namespace App\Payday3\Domain;

/**
 * The one auto-match algorithm for every pair of tables on the page:
 *   incoming bank ↔ Poster checks, incoming bank ↔ Poster finance income,
 *   outgoing bank ↔ Poster finance expense.
 * Pure: no DB, no API — callers build the candidate lists, persist the
 * result. Direct port of payday2's auto_link, three greedy passes over
 * the Poster side in chronological order:
 *
 *   GREEN (tight)         same amount and |Δt| ≤ 10 min — the bank row
 *                         with the smallest Δt wins;
 *   GREEN (interpolation) a Poster row sandwiched between two rows that
 *                         went green in THIS run takes the same-amount
 *                         bank row regardless of Δt — the neighbours
 *                         vouch for it;
 *   YELLOW (loose)        same amount, any Δt within the day — closest
 *                         wins; flagged for a human to confirm.
 *
 * Every bank row and every Poster row is used at most once.
 */
final class AmountTimeMatcher
{
    /** ±10 minutes — same window as payday2. */
    public const GREEN_WINDOW_SECONDS = 600;

    public const GREEN  = 'auto_green';
    public const YELLOW = 'auto_yellow';

    /**
     * @param list<array{id:int, amount:int, ts:int}> $bank
     *        Bank rows still free to link (not hidden, not linked yet).
     * @param list<array{id:int, amount:int, ts:int, eligible:bool}> $poster
     *        ALL Poster rows of the range, eligible or not — ineligible
     *        rows still count as neighbours for the interpolation pass.
     * @return list<array{bank:int, poster:int, type:string}>
     */
    public static function match(array $bank, array $poster): array
    {
        // Chronological order is the contract of the interpolation pass;
        // don't trust the source order (Poster finance comes newest-first).
        usort($poster, static fn(array $a, array $b) => $a['ts'] <=> $b['ts']);

        $byAmount = [];
        foreach ($bank as $b) {
            if ($b['amount'] > 0 && $b['ts'] > 0) $byAmount[$b['amount']][] = $b;
        }
        $eligible = [];
        foreach ($poster as $i => $p) {
            $eligible[$i] = $p['eligible'] && $p['amount'] > 0 && $p['ts'] > 0;
        }

        $usedBank = [];
        $green    = [];   // poster index → true (green in this run)
        $pairs    = [];
        $take = static function (int $i, int $bankId, string $type) use (&$usedBank, &$eligible, &$pairs, &$green, $poster): void {
            $usedBank[$bankId] = true;
            $eligible[$i] = false;
            $pairs[] = ['bank' => $bankId, 'poster' => $poster[$i]['id'], 'type' => $type];
            if ($type === self::GREEN) $green[$i] = true;
        };

        // Pass 1: GREEN — amount + ±10 min.
        foreach ($poster as $i => $p) {
            if (!$eligible[$i]) continue;
            $best = self::closest($byAmount[$p['amount']] ?? [], $usedBank, $p['ts'], self::GREEN_WINDOW_SECONDS);
            if ($best !== null) $take($i, $best, self::GREEN);
        }

        // Pass 2: GREEN by interpolation between two fresh greens.
        $n = count($poster);
        for ($i = 1; $i < $n - 1; $i++) {
            if (!$eligible[$i] || empty($green[$i - 1]) || empty($green[$i + 1])) continue;
            $best = self::closest($byAmount[$poster[$i]['amount']] ?? [], $usedBank, $poster[$i]['ts'], PHP_INT_MAX);
            if ($best !== null) $take($i, $best, self::GREEN);
        }

        // Pass 3: YELLOW — amount only, closest time wins.
        foreach ($poster as $i => $p) {
            if (!$eligible[$i]) continue;
            $best = self::closest($byAmount[$p['amount']] ?? [], $usedBank, $p['ts'], PHP_INT_MAX);
            if ($best !== null) $take($i, $best, self::YELLOW);
        }

        return $pairs;
    }

    /** Free bank row with the smallest |Δt| inside the window, or null. */
    private static function closest(array $candidates, array $usedBank, int $ts, int $window): ?int
    {
        $best = null;
        $bestDiff = PHP_INT_MAX;
        foreach ($candidates as $c) {
            if (isset($usedBank[$c['id']])) continue;
            $diff = abs($c['ts'] - $ts);
            if ($diff <= $window && $diff < $bestDiff) {
                $best = $c['id'];
                $bestDiff = $diff;
            }
        }
        return $best;
    }

    /** Tolerant timestamp parse — 'Y-m-d H:i:s' or anything strtotime reads; 0 if none. */
    public static function ts(string $raw): int
    {
        if ($raw === '') return 0;
        $t = strtotime($raw);
        return $t === false ? 0 : $t;
    }
}

<?php

declare(strict_types=1);

namespace App\Payday3\Services;

use App\Payday3\Contracts\PosterApiProviderInterface;
use App\Payday3\Contracts\PosterCashShiftServiceInterface;
use App\Payday3\Domain\DateRange;
use App\Payday3\Domain\Money;

/**
 * Cash-shifts read-only service. Wraps two Poster endpoints:
 *   finance.getCashShifts        — list per date range
 *   finance.getCashShiftTransactions — single-shift drill-down
 *
 * Deleted txs (delete = 1) are filtered out of the detail result
 * to match payday2's contract. All monetary fields (anything whose
 * key contains 'amount' or 'sum') are converted from Poster cents
 * to VND server-side so the JS can render them directly.
 */
final class PosterCashShiftService implements PosterCashShiftServiceInterface
{
    public function __construct(private readonly PosterApiProviderInterface $poster) {}

    public function list(DateRange $range): array
    {
        $rows = $this->poster->client()->request('finance.getCashShifts', [
            'dateFrom' => str_replace('-', '', $range->from),
            'dateTo'   => str_replace('-', '', $range->to),
        ]);
        return is_array($rows) ? array_map([self::class, 'normaliseRowMoney'], $rows) : [];
    }

    public function detail(string $shiftId): array
    {
        $shiftId = trim($shiftId);
        if ($shiftId === '') {
            throw new \InvalidArgumentException('shiftId is required');
        }
        $rows = $this->poster->client()->request('finance.getCashShiftTransactions', [
            'shift_id' => $shiftId,
        ]);
        if (!is_array($rows)) return [];
        $rows = array_values(array_filter(
            $rows,
            static fn($tx) => is_array($tx) && (int)($tx['delete'] ?? 0) !== 1,
        ));
        return array_map([self::class, 'normaliseRowMoney'], $rows);
    }

    /**
     * Convert any field whose key looks monetary (contains amount /
     * sum / payed / balance) from cents to VND. Mirrors payday2's
     * JS-side heuristic — payday2 did this in the browser, we do it
     * here so the wire payload is already in display units.
     *
     * @param  mixed $row
     * @return mixed
     */
    private static function normaliseRowMoney($row): mixed
    {
        if (!is_array($row)) return $row;
        foreach ($row as $k => $v) {
            if (!is_string($k)) continue;
            $kl = strtolower($k);
            if (str_contains($kl, 'amount') || str_contains($kl, 'sum')
                || str_contains($kl, 'payed') || str_contains($kl, 'balance')) {
                if (is_array($v)) continue;          // nested struct, skip
                $row[$k] = Money::posterMinorToVnd($v);
            }
        }
        return $row;
    }
}

<?php

declare(strict_types=1);

namespace App\Payday3\Services;

use App\Payday3\Contracts\PosterApiProviderInterface;
use App\Payday3\Contracts\PosterCashShiftServiceInterface;
use App\Payday3\Domain\DateRange;

/**
 * Cash-shifts read-only service. Wraps two Poster endpoints:
 *   finance.getCashShifts        — list per date range
 *   finance.getCashShiftTransactions — single-shift drill-down
 *
 * Deleted txs (delete = 1) are filtered out of the detail result
 * to match payday2's contract.
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
        return is_array($rows) ? $rows : [];
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
        return array_values(array_filter(
            $rows,
            static fn($tx) => is_array($tx) && (int)($tx['delete'] ?? 0) !== 1,
        ));
    }
}

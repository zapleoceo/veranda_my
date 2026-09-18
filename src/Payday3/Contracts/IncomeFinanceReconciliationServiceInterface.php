<?php

declare(strict_types=1);

namespace App\Payday3\Contracts;

use App\Payday3\Domain\DateRange;

/** Incoming bank (SePay) ↔ Poster finance income reconciliation. */
interface IncomeFinanceReconciliationServiceInterface
{
    /** @return array{added:int, total:int} */
    public function autoLink(DateRange $range): array;

    public function manualLink(int $sepayId, int $financeId, string $dateTo): void;

    public function unlink(int $sepayId, int $financeId): void;

    /** @return int rows deleted */
    public function clearLinks(DateRange $range): int;
}

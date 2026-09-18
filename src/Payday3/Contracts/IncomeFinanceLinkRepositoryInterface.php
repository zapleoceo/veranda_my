<?php

declare(strict_types=1);

namespace App\Payday3\Contracts;

use App\Payday3\Domain\DateRange;
use App\Payday3\Domain\IncomeFinanceLink;

/** Storage of incoming bank ↔ Poster finance income edges. */
interface IncomeFinanceLinkRepositoryInterface
{
    /** @return IncomeFinanceLink[] edges created for a day inside the range */
    public function listInRange(DateRange $range): array;

    /** Idempotent: an existing (sepay, finance) pair is left as is. */
    public function add(IncomeFinanceLink $link, string $dateTo): void;

    public function remove(int $sepayId, int $financeId): void;

    /** @return int rows deleted */
    public function clearInRange(DateRange $range): int;
}

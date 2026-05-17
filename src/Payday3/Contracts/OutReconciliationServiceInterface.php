<?php

declare(strict_types=1);

namespace App\Payday3\Contracts;

use App\Payday3\Domain\DateRange;

interface OutReconciliationServiceInterface
{
    /** @return array{added:int, total:int} */
    public function autoLink(DateRange $range): array;

    public function manualLink(int $mailUid, int $financeId, string $dateTo): void;

    public function unlink(int $mailUid, int $financeId): void;

    public function clearLinks(DateRange $range): int;
}

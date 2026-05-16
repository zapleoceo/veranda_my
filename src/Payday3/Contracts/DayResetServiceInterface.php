<?php

declare(strict_types=1);

namespace App\Payday3\Contracts;

use App\Payday3\Domain\DateRange;

interface DayResetServiceInterface
{
    /**
     * Soft-reset every sepay/poster row in the range — flips
     * was_deleted=1 + deleted_at=NOW(). Records are hidden, not
     * physically removed; the next sync from mail/Poster API
     * recreates them with fresh data.
     *
     * @return array{sepay:int, poster:int} affected row counts
     */
    public function softReset(DateRange $range): array;
}

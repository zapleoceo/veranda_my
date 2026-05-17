<?php

declare(strict_types=1);

namespace App\Payday3\Contracts;

use App\Payday3\Domain\DateRange;

interface PosterCashShiftServiceInterface
{
    /** @return array list of cash-shift records from Poster */
    public function list(DateRange $range): array;

    /**
     * Return the transactions inside one shift, filtering out the
     * soft-deleted ones (delete = 1).
     *
     * @return array
     */
    public function detail(string $shiftId): array;
}

<?php

declare(strict_types=1);

namespace App\Payday3\Contracts;

use App\Payday3\Domain\DateRange;
use App\Payday3\Domain\SepayTransaction;

interface SepayRepositoryInterface
{
    /** @return SepayTransaction[] inbound transactions in range, not hidden, not linked. */
    public function listOpenInRange(DateRange $range): array;

    /** @return SepayTransaction[] inbound transactions in range that have been hidden by the user. */
    public function listHiddenInRange(DateRange $range): array;
}

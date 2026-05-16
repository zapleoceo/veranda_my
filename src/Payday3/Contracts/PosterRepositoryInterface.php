<?php

declare(strict_types=1);

namespace App\Payday3\Contracts;

use App\Payday3\Domain\DateRange;
use App\Payday3\Domain\PosterTransaction;

interface PosterRepositoryInterface
{
    /** @return PosterTransaction[] closed Poster transactions in range with card/third-party > 0. */
    public function listClosedInRange(DateRange $range): array;
}

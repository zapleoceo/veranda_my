<?php

declare(strict_types=1);

namespace App\Payday3\Contracts;

use App\Payday3\Domain\DateRange;

interface PosterSyncServiceInterface
{
    /**
     * Pulls Poster transactions for the range from the Poster API
     * and upserts them into poster_checks + poster_payment_methods.
     *
     * @return array{
     *   inserted: int,
     *   updated:  int,
     *   skipped:  int,
     *   methods:  int,
     * }
     */
    public function sync(DateRange $range): array;
}

<?php

declare(strict_types=1);

namespace App\Payday3\Contracts;

use App\Payday3\Domain\ActualBalances;

interface ActualBalanceRepositoryInterface
{
    /** Latest snapshot saved at or before $date, or null if none. */
    public function latestFor(string $date): ?ActualBalances;

    /** Insert a new row (every save creates a fresh snapshot — history is kept). */
    public function save(ActualBalances $bal): int;
}

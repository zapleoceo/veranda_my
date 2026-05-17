<?php

declare(strict_types=1);

namespace App\Payday3\Contracts;

use App\Payday3\Domain\DateRange;

interface FinanceServiceInterface
{
    /**
     * Fetch Poster finance.getTransactions for the relevant accounts
     * (Andrey + Tips, per LocalSettings) in the given range.
     *
     * @return \App\Payday3\Domain\FinanceTransaction[]
     */
    public function fetch(DateRange $range): array;
}

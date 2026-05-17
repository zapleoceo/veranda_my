<?php

declare(strict_types=1);

namespace App\Payday3\Contracts;

use App\Payday3\Domain\DateRange;

interface PosterSuppliesServiceInterface
{
    /**
     * @return array{supplies: array, accounts: array}
     */
    public function listWithAccounts(DateRange $range): array;

    /**
     * Reassign the finance account of a supply via
     * PosterSupplyManager. Returns the raw Poster response.
     */
    public function changeAccount(int $supplyId, int $newAccountId): array;
}

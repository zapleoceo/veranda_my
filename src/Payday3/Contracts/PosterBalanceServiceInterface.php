<?php

declare(strict_types=1);

namespace App\Payday3\Contracts;

interface PosterBalanceServiceInterface
{
    /**
     * Current balances of the configured accounts (Andrey / Vietnam /
     * Cash) plus the computed total — all in integer VND.
     *
     * @return array{andrey:?int, vietnam:?int, cash:?int, total:?int}
     *   null marks "account not present in finance.getAccounts" so
     *   the UI can render '—' instead of 0.
     */
    public function snapshot(): array;
}

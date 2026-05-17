<?php

declare(strict_types=1);

namespace App\Payday3\Contracts;

interface PosterBalanceServiceInterface
{
    /**
     * Current balances of the configured accounts (Andrey / Vietnam /
     * Cash) plus the computed total — all in integer VND. Also returns
     * the full list of accounts Poster knows about, normalised to
     * {account_id, name, balance} (also VND).
     *
     * @return array{
     *   andrey: ?int,
     *   vietnam: ?int,
     *   cash: ?int,
     *   total: ?int,
     *   accounts: list<array{account_id:int, name:string, balance:int}>
     * }
     *   null marks "account not present in finance.getAccounts" so
     *   the UI can render '—' instead of 0.
     */
    public function snapshot(): array;
}

<?php

declare(strict_types=1);

namespace App\Payday3\Contracts;

use App\Payday3\Domain\DateRange;

interface PosterCheckServiceInterface
{
    /**
     * Paginated search for a check by transaction_id inside a range.
     *
     * @return array{found:bool, transaction?:array, products?:array}
     */
    public function find(int $transactionId, DateRange $range): array;

    /**
     * Recent checks for the range — the Check-Finder modal renders
     * this on open so the operator picks visually rather than
     * typing transaction_ids. Capped server-side at $limit rows.
     *
     * @return array<int,array> normalised check rows
     */
    public function listRecent(DateRange $range, int $limit = 200): array;

    /**
     * Remove a check via transactions.removeTransaction.
     * Notifies Telegram on success. Returns
     * {ok:true, telegram_ok:bool, telegram_error?:string}.
     */
    public function remove(int $transactionId, string $byLabel): array;
}

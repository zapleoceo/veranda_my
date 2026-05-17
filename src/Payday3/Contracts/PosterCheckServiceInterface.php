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
     * Remove a check via transactions.removeTransaction.
     * Notifies Telegram on success. Returns
     * {ok:true, telegram_ok:bool, telegram_error?:string}.
     */
    public function remove(int $transactionId, string $byLabel): array;
}

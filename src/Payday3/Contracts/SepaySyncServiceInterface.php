<?php

declare(strict_types=1);

namespace App\Payday3\Contracts;

use App\Payday3\Domain\DateRange;

interface SepaySyncServiceInterface
{
    /**
     * Pulls fresh transactions from SePay's REST API (not IMAP — the
     * BIDV inbox is the OUT-direction source) for the given range
     * and upserts them into sepay_transactions.
     *
     * @return array{inserted:int, updated:int, skipped:int, apiRows:int}
     */
    public function sync(DateRange $range): array;
}

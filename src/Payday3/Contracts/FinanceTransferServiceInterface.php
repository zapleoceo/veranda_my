<?php

declare(strict_types=1);

namespace App\Payday3\Contracts;

use App\Payday3\Domain\DateRange;

/**
 * Финансовые транзакции card (Vietnam Company + Tips):
 *   - DB-side sum of expected transfer cents (poster_checks aggregate);
 *   - Poster-side `finance.getTransactions` walk for matching transfers;
 *
 * One method per kind keeps the action thin — the controller fans both
 * calls out and assembles a `{vietnam: …, tips: …}` envelope.
 */
interface FinanceTransferServiceInterface
{
    /**
     * @return array{total_vnd:?int, found:list<array{ts:int,sum_minor:int,type:string,comment:string,user:string,account:string,transaction_id:int}>}
     */
    public function vietnam(DateRange $range): array;

    /**
     * @return array{total_vnd:?int, found:list<array{ts:int,sum_minor:int,type:string,comment:string,user:string,account:string,transaction_id:int}>}
     */
    public function tips(DateRange $range): array;
}

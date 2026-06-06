<?php

declare(strict_types=1);

namespace App\Payday3\Contracts;

use App\Payday3\Domain\DateRange;

/**
 * Финансовые транзакции card (Vietnam Company + Tips):
 *   - Live aggregate from Poster `dash.getTransactions` for the
 *     expected-transfer total (no local snapshot read);
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

    /**
     * Push the Vietnam/Tips transfer into Poster — same shape
     * payday2 emitted via ?ajax=create_transfer. Idempotent: if a
     * matching finance.getTransactions row already exists for the
     * day, returns `already:true` without re-creating.
     *
     * @param 'vietnam'|'tips' $kind
     * @return array{
     *   ok:true,
     *   already:bool,
     *   amount_vnd:int,
     *   date:string,
     *   comment:string,
     *   user:string
     * }
     * @throws \InvalidArgumentException on bad input
     * @throws \RuntimeException         on Poster API failure / zero amount
     */
    public function createTransfer(string $kind, DateRange $range, string $byLabel = ''): array;
}

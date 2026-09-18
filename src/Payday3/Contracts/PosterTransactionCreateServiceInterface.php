<?php

declare(strict_types=1);

namespace App\Payday3\Contracts;

use App\Payday3\Domain\Actor;

/**
 * "+" button on each OUT-mail row opens a popup that creates a Poster
 * finance transaction at the amount/date of the bank email. This
 * interface owns the build-payload + API-call sequence; the UI just
 * collects fields and POSTs them.
 */
interface PosterTransactionCreateServiceInterface
{
    /**
     * @param array{
     *   type:int,                  // 1=income, 2=expense, 3=transfer
     *   amount:int,                // VND, integer (no cents)
     *   date:string,               // 'Y-m-d H:i:s'
     *   account_from?:int,
     *   account_to?:int,
     *   category_id?:int,
     *   comment?:string
     * } $input
     * @return array{ok:true, response?:mixed}
     * @param Actor|null $actor  who creates it (audit row + per-session idempotency)
     * @throws \InvalidArgumentException on validation failure (bad account / amount / date)
     * @throws \DomainException         on an identical request from the same session < 10 s ago
     * @throws \RuntimeException         on Poster API failure
     */
    public function create(array $input, ?Actor $actor = null): array;
}

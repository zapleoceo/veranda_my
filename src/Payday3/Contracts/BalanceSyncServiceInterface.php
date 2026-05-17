<?php

declare(strict_types=1);

namespace App\Payday3\Contracts;

/**
 * "UPLD" balance correction flow — pushes the Factual − Poster delta
 * into Poster as a `finance.createTransactions` correction.
 *
 * Two-step protocol, exactly as payday2:
 *   plan(diffVnd)   → builds the payload, stashes a nonce in session,
 *                     returns a human-readable preview the operator
 *                     confirms client-side.
 *   commit(nonce)   → validates nonce age (<5 min), checks today's
 *                     finance.getTransactions for an existing match
 *                     to make the call idempotent, then POSTs the
 *                     payload.
 */
interface BalanceSyncServiceInterface
{
    /**
     * @return array{
     *   nonce: string,
     *   plan: array{
     *     type:int, account_id:int, account_name:string,
     *     amount_vnd:int, sum:string, comment:string, user_id:int,
     *     diff_vnd:int
     *   }
     * }
     */
    public function plan(int $diffVnd, string $byLabel = ''): array;

    /**
     * @return array{ok:bool, already?:bool, response?:mixed}
     */
    public function commit(string $nonce): array;
}

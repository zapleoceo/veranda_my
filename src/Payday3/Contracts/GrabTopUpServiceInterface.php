<?php

declare(strict_types=1);

namespace App\Payday3\Contracts;

use App\Payday3\Domain\Actor;
use App\Payday3\Domain\DateRange;

/**
 * «Пополнить Grab» row of the Финансовые транзакции card: the surplus on
 * the Vietnam account (saved Факт. − live Poster balance) is booked as a
 * Poster finance income in category GRAB on that account.
 */
interface GrabTopUpServiceInterface
{
    /**
     * @return array{
     *   surplus_vnd:?int, fact_vnd:?int, poster_vnd:?int,
     *   vietnam_ok:bool, tips_ok:bool,
     *   found:list<array{transaction_id:int,ts:int,sum_minor:int,type:string,comment:string,user:string,account:string}>,
     *   reason:string, can_create:bool, message:string, error?:string
     * }
     *   reason ∈ ok | exists | no_fact | no_poster | not_reconciled | no_surplus | error
     */
    public function status(DateRange $range): array;

    /**
     * Re-validates everything server-side under a named lock and creates
     * the income. Rejections are \DomainException (→ HTTP 400).
     *
     * @return array{ok:true, already:false, amount_vnd:int, date:string, comment:string, user:string}
     */
    public function create(DateRange $range, Actor $actor): array;
}

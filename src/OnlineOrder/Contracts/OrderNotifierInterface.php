<?php

declare(strict_types=1);

namespace App\OnlineOrder\Contracts;

/**
 * Tells the restaurant a new delivery order just landed. Implementation:
 * TelegramOrderNotifier (the staff already lives in Telegram). MUST be
 * best-effort: implementations swallow their own errors — a missed ping
 * never fails an order that's already in Poster.
 */
interface OrderNotifierInterface
{
    /**
     * @param array{
     *   order_id:int, name:string, phone:string, address:string,
     *   items:array<int,string>, total_vnd:int,
     *   delivery?:?array, payment?:?array, dispatch?:?array
     * } $summary
     */
    public function notifyNewOrder(array $summary): void;
}

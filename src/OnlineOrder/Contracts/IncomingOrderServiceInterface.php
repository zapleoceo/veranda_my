<?php

declare(strict_types=1);

namespace App\OnlineOrder\Contracts;

use App\OnlineOrder\Domain\CustomerInfo;
use App\OnlineOrder\Domain\DeliveryAddress;
use App\OnlineOrder\Domain\DeliveryQuote;

/**
 * Writes a delivery order into Poster via
 * incomingOrders.createIncomingOrder (service_mode=3) — the endpoint
 * built for exactly this: it carries client phone/name/address and
 * shows up on the POS register as an incoming online order the staff
 * accepts.
 */
interface IncomingOrderServiceInterface
{
    /**
     * @param \App\Order\Domain\CartLine[] $lines     validated cart lines
     * @param array<int,string>            $lineLabels display labels per line index (for the kitchen comment)
     * @return array{incoming_order_id:int}
     * @throws \InvalidArgumentException on empty/invalid input
     * @throws \RuntimeException         on Poster API failure
     */
    public function create(
        CustomerInfo    $customer,
        DeliveryAddress $address,
        array           $lines,
        array           $lineLabels,
        string          $comment,
        ?DeliveryQuote  $quote,
    ): array;
}

<?php

declare(strict_types=1);

namespace App\OnlineOrder\Contracts;

use App\OnlineOrder\Domain\CustomerInfo;
use App\OnlineOrder\Domain\GeoPoint;

/**
 * Books a courier/taxi to pick the food up at the restaurant and
 * bring it to the customer. Separate from quoting (ISP): only the
 * real ride-hailing providers implement both; the distance fallback
 * can quote but never dispatch.
 *
 * Dispatch failures throw RuntimeException — the caller decides
 * whether that fails the order (it must not: the order is already in
 * Poster, the operator can always book manually).
 */
interface TaxiDispatchInterface
{
    /**
     * @param GeoPoint     $pickup    restaurant pin
     * @param GeoPoint     $dropoff   customer pin
     * @param CustomerInfo $recipient who the courier hands the food to
     * @param string       $orderRef  merchant-side reference ("VRD-123")
     * @return array{provider:string, tracking_id:string, status:string, raw?:array}
     */
    public function dispatch(GeoPoint $pickup, GeoPoint $dropoff, CustomerInfo $recipient, string $orderRef): array;
}

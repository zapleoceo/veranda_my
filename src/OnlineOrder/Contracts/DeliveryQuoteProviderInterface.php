<?php

declare(strict_types=1);

namespace App\OnlineOrder\Contracts;

use App\OnlineOrder\Domain\DeliveryQuote;
use App\OnlineOrder\Domain\GeoPoint;

/**
 * "How much will the courier charge from A to B?"
 *
 * Implementations: GrabDeliveryProvider (live Grab Express quote),
 * MaximDeliveryProvider (live Maxim, pending partner contract),
 * DistanceTariffProvider (keyless base+per-km, for testing/interim),
 * NullDeliveryProvider (no quoting configured at all).
 *
 * A provider must never throw out of quote() — connectivity and
 * credential problems come back as DeliveryQuote::unavailable() so
 * the checkout flow degrades to "courier fee confirmed by operator"
 * instead of breaking.
 */
interface DeliveryQuoteProviderInterface
{
    /** Stable machine name: 'grab' | 'maxim' | 'distance' | 'none'. */
    public function name(): string;

    public function quote(GeoPoint $from, GeoPoint $to): DeliveryQuote;
}

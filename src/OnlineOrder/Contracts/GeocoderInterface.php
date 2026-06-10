<?php

declare(strict_types=1);

namespace App\OnlineOrder\Contracts;

use App\OnlineOrder\Domain\GeoPoint;

/**
 * Resolves a customer-typed address into coordinates (and back).
 * Implementations: GoogleGeocoder (server key), NominatimGeocoder
 * (keyless OSM fallback). The container picks one based on config —
 * callers never know which.
 */
interface GeocoderInterface
{
    /** Free-text address → coordinates, or null when nothing matched. */
    public function geocode(string $address): ?GeoPoint;

    /** Coordinates → human-readable address, or null when unresolvable. */
    public function reverse(GeoPoint $point): ?string;
}

<?php

declare(strict_types=1);

namespace App\OnlineOrder\Domain;

/**
 * An immutable WGS-84 coordinate, optionally carrying the human-
 * readable address it was resolved from. Used as the delivery
 * destination and the restaurant origin for distance / dispatch.
 */
final class GeoPoint
{
    public function __construct(
        public readonly float   $lat,
        public readonly float   $lng,
        public readonly ?string $address = null,
    ) {}

    /**
     * Lenient builder from a JS payload. Accepts {lat,lng} or
     * {latitude,longitude}. Returns null if coordinates are absent or
     * implausible (the (0,0) "null island" is treated as missing).
     */
    public static function fromArray(array $a): ?self
    {
        $lat = $a['lat'] ?? $a['latitude']  ?? null;
        $lng = $a['lng'] ?? $a['lon'] ?? $a['lng'] ?? $a['longitude'] ?? null;
        if ($lat === null || $lng === null || !is_numeric($lat) || !is_numeric($lng)) {
            return null;
        }
        $lat = (float)$lat;
        $lng = (float)$lng;
        if (!self::valid($lat, $lng)) return null;

        $addr = isset($a['address']) && is_string($a['address']) && $a['address'] !== ''
            ? $a['address']
            : null;
        return new self($lat, $lng, $addr);
    }

    public static function valid(float $lat, float $lng): bool
    {
        if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) return false;
        // (0,0) is almost always a missing-coordinate sentinel, not a real
        // delivery address in the Gulf of Guinea.
        if (abs($lat) < 1e-7 && abs($lng) < 1e-7) return false;
        return true;
    }

    /** Great-circle distance in kilometres (Haversine). */
    public function distanceKm(GeoPoint $other): float
    {
        $earthKm = 6371.0;
        $dLat = deg2rad($other->lat - $this->lat);
        $dLng = deg2rad($other->lng - $this->lng);
        $a = sin($dLat / 2) ** 2
           + cos(deg2rad($this->lat)) * cos(deg2rad($other->lat)) * sin($dLng / 2) ** 2;
        return $earthKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    public function toArray(): array
    {
        return ['lat' => $this->lat, 'lng' => $this->lng, 'address' => $this->address];
    }
}

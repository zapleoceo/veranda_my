<?php

declare(strict_types=1);

namespace App\OnlineOrder\Services;

use App\Infrastructure\HttpClient;
use App\OnlineOrder\Contracts\GeocoderInterface;
use App\OnlineOrder\Domain\GeoPoint;

/**
 * Keyless fallback geocoder — OpenStreetMap Nominatim. Works the day
 * the page ships, before any Google key exists. Usage policy requires
 * an identifying User-Agent and tolerates ~1 rps — fine for a single
 * restaurant's checkout flow.
 */
final class NominatimGeocoder implements GeocoderInterface
{
    private const SEARCH  = 'https://nominatim.openstreetmap.org/search';
    private const REVERSE = 'https://nominatim.openstreetmap.org/reverse';
    private const UA      = 'VerandaOnlineOrder/1.0 (veranda.my; delivery checkout)';

    public function __construct(private readonly HttpClient $http) {}

    public function geocode(string $address): ?GeoPoint
    {
        $address = trim($address);
        if ($address === '') return null;

        $j = $this->http->getJsonWithHeaders(self::SEARCH, [
            'q'              => $address,
            'format'         => 'jsonv2',
            'limit'          => 1,
            'countrycodes'   => 'vn',
            'addressdetails' => 0,
        ], ['User-Agent: ' . self::UA]);

        $first = $j[0] ?? null;
        if (!is_array($first) || !isset($first['lat'], $first['lon'])) return null;

        $lat = (float)$first['lat'];
        $lng = (float)$first['lon'];
        if (!GeoPoint::valid($lat, $lng)) return null;

        return new GeoPoint($lat, $lng, (string)($first['display_name'] ?? $address));
    }

    public function reverse(GeoPoint $point): ?string
    {
        $j = $this->http->getJsonWithHeaders(self::REVERSE, [
            'lat'    => $point->lat,
            'lon'    => $point->lng,
            'format' => 'jsonv2',
        ], ['User-Agent: ' . self::UA]);

        $name = $j['display_name'] ?? null;
        return is_string($name) && $name !== '' ? $name : null;
    }
}

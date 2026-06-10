<?php

declare(strict_types=1);

namespace App\OnlineOrder\Services;

use App\Infrastructure\HttpClient;
use App\OnlineOrder\Contracts\GeocoderInterface;
use App\OnlineOrder\Domain\GeoPoint;

/**
 * Google Geocoding API (server-side). Used when the customer typed a
 * plain-text address (no Places pick) and we still need coordinates
 * for the radius check / courier dispatch. Biased to Vietnam.
 */
final class GoogleGeocoder implements GeocoderInterface
{
    private const URL = 'https://maps.googleapis.com/maps/api/geocode/json';

    public function __construct(
        private readonly HttpClient $http,
        private readonly string     $apiKey,
    ) {}

    public function geocode(string $address): ?GeoPoint
    {
        $address = trim($address);
        if ($address === '' || $this->apiKey === '') return null;

        $j = $this->http->getJson(self::URL, [
            'address'    => $address,
            'key'        => $this->apiKey,
            'region'     => 'vn',
            'components' => 'country:VN',
            'language'   => 'vi',
        ]);
        $first = $j['results'][0] ?? null;
        if (!is_array($first)) return null;

        $loc = $first['geometry']['location'] ?? null;
        if (!is_array($loc) || !isset($loc['lat'], $loc['lng'])) return null;

        $lat = (float)$loc['lat'];
        $lng = (float)$loc['lng'];
        if (!GeoPoint::valid($lat, $lng)) return null;

        return new GeoPoint($lat, $lng, (string)($first['formatted_address'] ?? $address));
    }

    public function reverse(GeoPoint $point): ?string
    {
        if ($this->apiKey === '') return null;

        $j = $this->http->getJson(self::URL, [
            'latlng'   => $point->lat . ',' . $point->lng,
            'key'      => $this->apiKey,
            'language' => 'vi',
        ]);
        $addr = $j['results'][0]['formatted_address'] ?? null;
        return is_string($addr) && $addr !== '' ? $addr : null;
    }
}

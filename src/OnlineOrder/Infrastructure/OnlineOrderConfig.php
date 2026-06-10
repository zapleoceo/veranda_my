<?php

declare(strict_types=1);

namespace App\OnlineOrder\Infrastructure;

use App\Infrastructure\Config;
use App\OnlineOrder\Domain\GeoPoint;

/**
 * Single source of truth for every /onlineorder tunable. Reads from
 * the .env (via Config, loaded at bootstrap) with safe defaults so the
 * page renders and degrades gracefully even before the owner fills in
 * the third-party credentials.
 *
 * Nothing here throws on a missing key — "not configured" is a normal,
 * first-class state the front-end is built to render (it shows the
 * relevant "integration pending" hint instead of breaking).
 *
 * The {@see frontendBootstrap()} array is the contract the JS reads on
 * load to decide which UX branch to show (Google Places vs plain text,
 * live quote vs "operator confirms", QR prepay vs none).
 */
final class OnlineOrderConfig
{
    // ─── Core location ────────────────────────────────────────────
    public function spotId(): int
    {
        $v = Config::get('ONLINEORDER_SPOT_ID', '') ?: Config::get('POSTER_SPOT_ID', '1');
        return max(1, (int)$v);
    }

    public function restaurant(): GeoPoint
    {
        // Defaults: central Nha Trang. REPLACE with the exact Veranda pin
        // in .env (ONLINEORDER_RESTAURANT_LAT/LNG) — the courier dispatch
        // origin and delivery-distance both depend on this being correct.
        $lat = (float)Config::get('ONLINEORDER_RESTAURANT_LAT', '12.238791');
        $lng = (float)Config::get('ONLINEORDER_RESTAURANT_LNG', '109.196749');
        return new GeoPoint($lat, $lng, $this->restaurantAddress());
    }

    public function restaurantAddress(): string
    {
        return Config::get('ONLINEORDER_RESTAURANT_ADDRESS', 'Veranda, Nha Trang');
    }

    public function restaurantPhone(): string
    {
        return Config::get('ONLINEORDER_PHONE', '');
    }

    public function hours(): string
    {
        return Config::get('ONLINEORDER_HOURS', '');
    }

    public function minOrderVnd(): int
    {
        return max(0, (int)Config::get('ONLINEORDER_MIN_ORDER_VND', '0'));
    }

    public function maxRadiusKm(): float
    {
        $v = (float)Config::get('ONLINEORDER_MAX_RADIUS_KM', '15');
        return $v > 0 ? $v : 15.0;
    }

    public function currencySymbol(): string
    {
        return Config::get('ONLINEORDER_CURRENCY', '₫');
    }

    // ─── Geocoder ─────────────────────────────────────────────────
    /** 'google' | 'nominatim' */
    public function geocoderName(): string
    {
        $v = strtolower(trim(Config::get('ONLINEORDER_GEOCODER', 'google')));
        return in_array($v, ['google', 'nominatim'], true) ? $v : 'google';
    }

    /** Browser-side key for Maps JS + Places Autocomplete (publicly exposed). */
    public function googleBrowserKey(): string
    {
        return Config::get('GOOGLE_MAPS_API_KEY', '');
    }

    /** Optional server-side key for Geocoding / Distance Matrix. Falls back to the browser key. */
    public function googleServerKey(): string
    {
        return Config::get('GOOGLE_MAPS_SERVER_KEY', '') ?: $this->googleBrowserKey();
    }

    public function isPlacesConfigured(): bool
    {
        return $this->geocoderName() === 'google' && $this->googleBrowserKey() !== '';
    }

    // ─── Delivery provider ────────────────────────────────────────
    /** Configured intent: 'grab' | 'maxim' | 'distance' | 'none'. */
    public function deliveryProviderName(): string
    {
        $v = strtolower(trim(Config::get('ONLINEORDER_DELIVERY_PROVIDER', 'grab')));
        return in_array($v, ['grab', 'maxim', 'distance', 'none'], true) ? $v : 'grab';
    }

    /** When a live provider lacks credentials, what to do: 'distance' (testing) | 'none'. */
    public function deliveryFallback(): string
    {
        $v = strtolower(trim(Config::get('ONLINEORDER_DELIVERY_FALLBACK', 'none')));
        return in_array($v, ['distance', 'none'], true) ? $v : 'none';
    }

    /**
     * Effective runtime mode after resolving credentials:
     *   'live'     — Grab/Maxim quote+dispatch is ready
     *   'distance' — keyless base+per-km tariff (testing / interim)
     *   'none'     — no auto quote; UI shows "operator confirms / coming soon"
     */
    public function deliveryMode(): string
    {
        $name = $this->deliveryProviderName();
        if ($name === 'distance') return 'distance';
        if ($name === 'none')     return 'none';
        // grab | maxim
        $configured = $name === 'grab' ? $this->isGrabConfigured() : $this->isMaximConfigured();
        if ($configured) return 'live';
        return $this->deliveryFallback() === 'distance' ? 'distance' : 'none';
    }

    public function isGrabConfigured(): bool
    {
        return Config::get('GRAB_CLIENT_ID', '') !== '' && Config::get('GRAB_CLIENT_SECRET', '') !== '';
    }

    public function isMaximConfigured(): bool
    {
        return Config::get('MAXIM_API_KEY', '') !== '' && Config::get('MAXIM_API_BASE', '') !== '';
    }

    /** True when delivery price is computed automatically (live or distance). */
    public function hasLiveQuote(): bool
    {
        return in_array($this->deliveryMode(), ['live', 'distance'], true);
    }

    /** True when a courier can be auto-dispatched (only real providers can). */
    public function hasTaxiDispatch(): bool
    {
        return $this->deliveryMode() === 'live';
    }

    /**
     * Auto-book the courier the moment the Poster order is created.
     * OFF by default: dispatching costs money on an order whose QR
     * payment hasn't been verified yet — the operator triggers it from
     * the Telegram alert until SePay auto-confirmation lands.
     */
    public function autoDispatch(): bool
    {
        return $this->hasTaxiDispatch() && Config::bool('ONLINEORDER_AUTO_DISPATCH', false);
    }

    // ─── Distance-tariff fallback (keyless) ───────────────────────
    public function distanceBaseVnd(): int    { return max(0, (int)Config::get('ONLINEORDER_DELIVERY_BASE_VND', '15000')); }
    public function distancePerKmVnd(): int   { return max(0, (int)Config::get('ONLINEORDER_DELIVERY_PER_KM_VND', '5000')); }
    public function distanceMinVnd(): int     { return max(0, (int)Config::get('ONLINEORDER_DELIVERY_MIN_VND', '15000')); }
    public function roadFactor(): float
    {
        $v = (float)Config::get('ONLINEORDER_DELIVERY_ROAD_FACTOR', '1.3');
        return $v >= 1.0 ? $v : 1.3;
    }

    // ─── Grab Express credentials ─────────────────────────────────
    public function grabApiBase(): string     { return Config::get('GRAB_API_BASE', 'https://partner-api.grab.com'); }
    public function grabClientId(): string    { return Config::get('GRAB_CLIENT_ID', ''); }
    public function grabClientSecret(): string{ return Config::get('GRAB_CLIENT_SECRET', ''); }
    public function grabMerchantId(): string  { return Config::get('GRAB_MERCHANT_ID', ''); }
    public function grabSandbox(): bool       { return Config::bool('GRAB_SANDBOX', true); }

    // ─── Maxim credentials ────────────────────────────────────────
    public function maximApiBase(): string    { return Config::get('MAXIM_API_BASE', ''); }
    public function maximApiKey(): string     { return Config::get('MAXIM_API_KEY', ''); }
    public function maximCityId(): string     { return Config::get('MAXIM_CITY_ID', ''); }

    // ─── Food payment QR ──────────────────────────────────────────
    /** 'vietqr' | 'sepay' | 'none'. */
    public function paymentProviderName(): string
    {
        $v = strtolower(trim(Config::get('ONLINEORDER_PAY_QR_PROVIDER', 'vietqr')));
        return in_array($v, ['vietqr', 'sepay', 'none'], true) ? $v : 'vietqr';
    }

    public function vietQrBankBin(): string     { return Config::get('VIETQR_BANK_BIN', ''); }
    public function vietQrAccountNo(): string   { return Config::get('VIETQR_ACCOUNT_NO', ''); }
    public function vietQrAccountName(): string { return Config::get('VIETQR_ACCOUNT_NAME', ''); }
    public function vietQrTemplate(): string    { return Config::get('VIETQR_TEMPLATE', 'compact2'); }

    public function sepayAccount(): string { return Config::get('SEPAY_QR_ACCOUNT', ''); }
    public function sepayBank(): string    { return Config::get('SEPAY_QR_BANK', ''); }

    public function isPaymentConfigured(): bool
    {
        return match ($this->paymentProviderName()) {
            'vietqr' => $this->vietQrBankBin() !== '' && $this->vietQrAccountNo() !== '',
            'sepay'  => $this->sepayAccount() !== '' && $this->sepayBank() !== '',
            default  => false,
        };
    }

    // ─── Front-end bootstrap ──────────────────────────────────────
    /**
     * The exact shape the JS reads on load. Never contains secrets —
     * the Google *browser* key is meant to be public; server keys and
     * Grab/Maxim secrets are NOT included here.
     */
    public function frontendBootstrap(): array
    {
        $r = $this->restaurant();
        return [
            'currency'      => $this->currencySymbol(),
            'spot_id'       => $this->spotId(),
            'restaurant'    => [
                'lat'     => $r->lat,
                'lng'     => $r->lng,
                'address' => $r->address,
                'phone'   => $this->restaurantPhone(),
            ],
            'min_order_vnd' => $this->minOrderVnd(),
            'max_radius_km' => $this->maxRadiusKm(),
            'hours'         => $this->hours(),
            'geocoder'      => $this->geocoderName(),
            'google_maps_key' => $this->geocoderName() === 'google' ? $this->googleBrowserKey() : '',
            'delivery'      => [
                'provider'   => $this->deliveryProviderName(),
                'mode'       => $this->deliveryMode(),       // live | distance | none
                'configured' => $this->deliveryMode() !== 'none',
                'payer'      => 'courier',                    // delivery paid to the driver
            ],
            'payment'       => [
                'provider'   => $this->paymentProviderName(),
                'configured' => $this->isPaymentConfigured(),
                'method'     => 'qr',                          // food prepaid by QR
            ],
            'features'      => [
                'places'        => $this->isPlacesConfigured(),
                'live_quote'    => $this->hasLiveQuote(),
                'pay_qr'        => $this->isPaymentConfigured(),
                'taxi_dispatch' => $this->hasTaxiDispatch(),
            ],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\OnlineOrder\Services;

use App\Infrastructure\HttpClient;
use App\Infrastructure\Logger;
use App\OnlineOrder\Contracts\DeliveryQuoteProviderInterface;
use App\OnlineOrder\Contracts\TaxiDispatchInterface;
use App\OnlineOrder\Domain\CustomerInfo;
use App\OnlineOrder\Domain\DeliveryQuote;
use App\OnlineOrder\Domain\GeoPoint;
use App\OnlineOrder\Infrastructure\OnlineOrderConfig;

/**
 * Grab Express (GrabExpress Partner API) — live delivery quote AND
 * courier dispatch from the restaurant to the customer.
 *
 * Wire contract (https://developer-beta.stg-myteksi.com / GrabExpress docs):
 *   auth   POST {base}/grabid/v1/oauth2/token
 *          {client_id, client_secret, grant_type:client_credentials,
 *           scope:"grab_express.partner_deliveries"} → {access_token, expires_in}
 *   quote  POST {base}/grab-express[-sandbox]/v1/deliveries/quotes
 *   create POST {base}/grab-express[-sandbox]/v1/deliveries
 *
 * Requires GrabExpress partner onboarding (GRAB_CLIENT_ID/SECRET in
 * .env). GRAB_SANDBOX=true (default) targets the sandbox path so the
 * first smoke tests cost nothing. Amounts come back in plain VND for
 * Vietnam.
 *
 * quote() never throws (interface contract) — failures degrade to
 * DeliveryQuote::unavailable('provider_error') and the checkout falls
 * back to "operator confirms the fee". dispatch() throws, the caller
 * treats it as non-fatal.
 */
final class GrabDeliveryProvider implements DeliveryQuoteProviderInterface, TaxiDispatchInterface
{
    /** Per-process OAuth token cache: [token, expiresAtUnix]. */
    private static ?array $tokenCache = null;

    public function __construct(
        private readonly OnlineOrderConfig $config,
        private readonly HttpClient        $http,
    ) {}

    public function name(): string
    {
        return 'grab';
    }

    // ─── Quote ────────────────────────────────────────────────────
    public function quote(GeoPoint $from, GeoPoint $to): DeliveryQuote
    {
        try {
            $resp = $this->call('/deliveries/quotes', $this->quotePayload($from, $to));
            $q = $resp['quotes'][0] ?? null;
            if (!is_array($q) || !isset($q['amount'])) {
                throw new \RuntimeException('no quotes in response');
            }

            $fee = (int)round((float)$q['amount']);
            $distanceKm = isset($q['distance']) ? ((float)$q['distance']) / 1000.0 : null;

            // estimatedTimeline.dropoff is an RFC3339 timestamp.
            $eta = null;
            $dropoff = $resp['quotes'][0]['estimatedTimeline']['dropoff'] ?? null;
            if (is_string($dropoff) && ($ts = strtotime($dropoff)) !== false) {
                $eta = max(1, (int)round(($ts - time()) / 60));
            }

            return DeliveryQuote::ok($this->name(), $fee, $distanceKm, $eta);
        } catch (\Throwable $e) {
            $this->logWarn('quote failed', $e);
            return DeliveryQuote::unavailable($this->name(), 'provider_error');
        }
    }

    // ─── Dispatch ─────────────────────────────────────────────────
    public function dispatch(GeoPoint $pickup, GeoPoint $dropoff, CustomerInfo $recipient, string $orderRef): array
    {
        $payload = $this->quotePayload($pickup, $dropoff) + [
            'merchantOrderID' => $orderRef,
            'recipient'       => [
                'name'  => $recipient->name,
                'phone' => $recipient->phone,
            ],
            'sender'          => [
                'name'  => 'Veranda',
                'phone' => $this->config->restaurantPhone(),
            ],
        ];

        $resp = $this->call('/deliveries', $payload);
        $deliveryId = (string)($resp['deliveryID'] ?? '');
        if ($deliveryId === '') {
            throw new \RuntimeException('Grab: no deliveryID in response');
        }

        return [
            'provider'    => $this->name(),
            'tracking_id' => $deliveryId,
            'status'      => (string)($resp['status'] ?? 'ALLOCATING'),
            'raw'         => $resp,
        ];
    }

    // ─── Wire plumbing ────────────────────────────────────────────
    private function quotePayload(GeoPoint $from, GeoPoint $to): array
    {
        return [
            'serviceType' => 'INSTANT',
            'packages'    => [[
                'name'       => 'Food order',
                'quantity'   => 1,
                'dimensions' => ['height' => 30, 'width' => 30, 'depth' => 30, 'weight' => 3],
            ]],
            'origin'      => [
                'address'     => $this->config->restaurantAddress(),
                'coordinates' => ['latitude' => $from->lat, 'longitude' => $from->lng],
            ],
            'destination' => [
                'address'     => (string)($to->address ?? ''),
                'coordinates' => ['latitude' => $to->lat, 'longitude' => $to->lng],
            ],
        ];
    }

    private function call(string $path, array $payload): array
    {
        $base = rtrim($this->config->grabApiBase(), '/')
              . ($this->config->grabSandbox() ? '/grab-express-sandbox/v1' : '/grab-express/v1');

        $resp = $this->http->postJsonBodyWithHeaders(
            $base . $path,
            $payload,
            ['Authorization: Bearer ' . $this->token()],
        );
        if (!is_array($resp)) {
            throw new \RuntimeException('Grab: empty/non-JSON response for ' . $path);
        }
        if (isset($resp['message']) && !isset($resp['quotes']) && !isset($resp['deliveryID'])) {
            throw new \RuntimeException('Grab: ' . (string)$resp['message']);
        }
        return $resp;
    }

    private function token(): string
    {
        if (self::$tokenCache !== null && self::$tokenCache[1] > time() + 30) {
            return self::$tokenCache[0];
        }

        $resp = $this->http->postJsonBody(
            rtrim($this->config->grabApiBase(), '/') . '/grabid/v1/oauth2/token',
            [
                'client_id'     => $this->config->grabClientId(),
                'client_secret' => $this->config->grabClientSecret(),
                'grant_type'    => 'client_credentials',
                'scope'         => 'grab_express.partner_deliveries',
            ],
        );
        $token = is_array($resp) ? (string)($resp['access_token'] ?? '') : '';
        if ($token === '') {
            throw new \RuntimeException('Grab OAuth failed (check GRAB_CLIENT_ID / GRAB_CLIENT_SECRET)');
        }

        $ttl = is_array($resp) && isset($resp['expires_in']) ? (int)$resp['expires_in'] : 600;
        self::$tokenCache = [$token, time() + max(60, $ttl)];
        return $token;
    }

    private function logWarn(string $msg, \Throwable $e): void
    {
        try { Logger::get()->warning('[onlineorder/grab] ' . $msg, ['err' => $e->getMessage()]); }
        catch (\Throwable $_) { error_log('[onlineorder/grab] ' . $msg . ': ' . $e->getMessage()); }
    }
}

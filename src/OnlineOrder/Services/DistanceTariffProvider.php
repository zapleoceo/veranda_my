<?php

declare(strict_types=1);

namespace App\OnlineOrder\Services;

use App\OnlineOrder\Contracts\DeliveryQuoteProviderInterface;
use App\OnlineOrder\Domain\DeliveryQuote;
use App\OnlineOrder\Domain\GeoPoint;
use App\OnlineOrder\Infrastructure\OnlineOrderConfig;

/**
 * Keyless tariff: haversine distance × road-curvature factor × per-km
 * rate + base fee. Stands in for Grab/Maxim while their credentials
 * are pending (ONLINEORDER_DELIVERY_FALLBACK=distance) and doubles as
 * the offline testing provider. Same interface — swapping to a live
 * provider is a config change, zero code.
 */
final class DistanceTariffProvider implements DeliveryQuoteProviderInterface
{
    public function __construct(private readonly OnlineOrderConfig $config) {}

    public function name(): string
    {
        return 'distance';
    }

    public function quote(GeoPoint $from, GeoPoint $to): DeliveryQuote
    {
        $roadKm = $from->distanceKm($to) * $this->config->roadFactor();

        $fee = $this->config->distanceBaseVnd()
             + (int)round($roadKm * $this->config->distancePerKmVnd());
        $fee = max($fee, $this->config->distanceMinVnd());
        // Round to a courier-friendly 1000 ₫ step.
        $fee = (int)(ceil($fee / 1000) * 1000);

        // ~22 km/h effective city speed + 10 min pickup/handover.
        $eta = (int)round($roadKm / 22 * 60) + 10;

        return DeliveryQuote::ok($this->name(), $fee, $roadKm, $eta);
    }
}

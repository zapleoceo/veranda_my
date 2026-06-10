<?php

declare(strict_types=1);

namespace App\OnlineOrder\Services;

use App\OnlineOrder\Contracts\DeliveryQuoteProviderInterface;
use App\OnlineOrder\Contracts\GeocoderInterface;
use App\OnlineOrder\Domain\DeliveryAddress;
use App\OnlineOrder\Domain\DeliveryQuote;
use App\OnlineOrder\Domain\GeoPoint;
use App\OnlineOrder\Infrastructure\OnlineOrderConfig;

/**
 * The one entry point the HTTP layer talks to for "what does delivery
 * to this address cost?". Orchestrates: resolve coordinates (use the
 * client-supplied pin, else geocode the text), enforce the delivery
 * radius, then delegate pricing to whichever provider is configured.
 *
 * Both QuoteAction (live preview in checkout) and OrderCreateAction
 * (authoritative re-check at submit — client-sent fees are never
 * trusted) call this same service.
 */
final class DeliveryQuoteService
{
    public function __construct(
        private readonly OnlineOrderConfig              $config,
        private readonly GeocoderInterface              $geocoder,
        private readonly DeliveryQuoteProviderInterface $provider,
    ) {}

    /**
     * @return array{quote: DeliveryQuote, point: ?GeoPoint}
     *         point — resolved destination (echoed to the UI so the
     *         client learns the coordinates we settled on).
     */
    public function quoteFor(DeliveryAddress $address): array
    {
        $point = $address->point;
        if ($point === null && $address->text !== '') {
            $point = $this->geocoder->geocode($address->text);
        }

        if ($point === null) {
            return [
                'quote' => DeliveryQuote::unavailable($this->provider->name(), 'geocode_failed'),
                'point' => null,
            ];
        }

        $from = $this->config->restaurant();
        if ($from->distanceKm($point) > $this->config->maxRadiusKm()) {
            return [
                'quote' => DeliveryQuote::unavailable($this->provider->name(), 'out_of_zone'),
                'point' => $point,
            ];
        }

        return ['quote' => $this->provider->quote($from, $point), 'point' => $point];
    }
}

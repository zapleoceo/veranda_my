<?php

declare(strict_types=1);

namespace App\OnlineOrder\Services;

use App\OnlineOrder\Contracts\DeliveryQuoteProviderInterface;
use App\OnlineOrder\Domain\DeliveryQuote;
use App\OnlineOrder\Domain\GeoPoint;

/**
 * No quoting configured (provider 'none', or live provider without
 * credentials and fallback disabled). The checkout still works — the
 * UI shows "delivery fee will be confirmed by the operator" and the
 * Telegram notification asks the staff to arrange the courier.
 */
final class NullDeliveryProvider implements DeliveryQuoteProviderInterface
{
    public function __construct(private readonly string $intendedProvider) {}

    public function name(): string
    {
        return 'none';
    }

    public function quote(GeoPoint $from, GeoPoint $to): DeliveryQuote
    {
        return DeliveryQuote::unavailable($this->intendedProvider, 'not_configured');
    }
}

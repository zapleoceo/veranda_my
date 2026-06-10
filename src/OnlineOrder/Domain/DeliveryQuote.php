<?php

declare(strict_types=1);

namespace App\OnlineOrder\Domain;

/**
 * The result of asking a delivery provider "how much, how far, how
 * long?". When {@see $available} is false the UI shows {@see $reason}
 * instead of a price (e.g. credentials not configured yet, or the
 * address is outside the delivery radius).
 *
 * {@see $payer} records who settles the fee. Per the Veranda model the
 * food is prepaid by QR but the delivery fee is paid directly to the
 * courier — so this is 'courier', and the fee is informational for the
 * Poster order total.
 */
final class DeliveryQuote
{
    public function __construct(
        public readonly bool    $available,
        public readonly int     $feeVnd,
        public readonly ?float  $distanceKm,
        public readonly ?int    $etaMinutes,
        public readonly string  $provider,
        public readonly string  $payer    = 'courier',
        public readonly ?string $reason   = null,
    ) {}

    public static function ok(string $provider, int $feeVnd, ?float $distanceKm, ?int $etaMinutes): self
    {
        return new self(
            available:  true,
            feeVnd:     max(0, $feeVnd),
            distanceKm: $distanceKm !== null ? round($distanceKm, 2) : null,
            etaMinutes: $etaMinutes,
            provider:   $provider,
        );
    }

    public static function unavailable(string $provider, string $reason): self
    {
        return new self(
            available:  false,
            feeVnd:     0,
            distanceKm: null,
            etaMinutes: null,
            provider:   $provider,
            reason:     $reason,
        );
    }

    public function toJson(): array
    {
        return [
            'available'   => $this->available,
            'fee_vnd'     => $this->feeVnd,
            'distance_km' => $this->distanceKm,
            'eta_minutes' => $this->etaMinutes,
            'provider'    => $this->provider,
            'payer'       => $this->payer,
            'reason'      => $this->reason,
        ];
    }
}

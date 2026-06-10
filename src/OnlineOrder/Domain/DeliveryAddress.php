<?php

declare(strict_types=1);

namespace App\OnlineOrder\Domain;

/**
 * Where the food goes. The free-text {@see $text} is always present
 * (what the customer sees / the courier reads); {@see $point} carries
 * the resolved coordinates when Google Places / the map pin gave them,
 * which is what the courier dispatch and distance calc actually use.
 */
final class DeliveryAddress
{
    public function __construct(
        public readonly string    $text,
        public readonly ?GeoPoint $point     = null,
        public readonly string    $apartment = '',
        public readonly string    $note      = '',
    ) {}

    public static function fromInput(array $r): self
    {
        $point = null;
        if (isset($r['point']) && is_array($r['point'])) {
            $point = GeoPoint::fromArray($r['point']);
        }
        if ($point === null && (isset($r['lat']) || isset($r['lng']))) {
            $point = GeoPoint::fromArray($r);
        }

        $text = trim((string)($r['address'] ?? $r['text'] ?? ''));
        if ($text === '' && $point?->address) {
            $text = $point->address;
        }

        return new self(
            text:      $text,
            point:     $point,
            apartment: trim((string)($r['apartment'] ?? '')),
            note:      trim((string)($r['note'] ?? '')),
        );
    }

    public function isValid(): bool
    {
        return $this->text !== '' || $this->point !== null;
    }

    /** One-line address for Poster's `address` field and the courier. */
    public function fullText(): string
    {
        $parts = [];
        if ($this->apartment !== '') $parts[] = $this->apartment;
        if ($this->text !== '')      $parts[] = $this->text;
        $line = implode(', ', $parts);
        if ($this->note !== '') $line .= ' (' . $this->note . ')';
        return $line;
    }
}

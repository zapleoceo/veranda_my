<?php

declare(strict_types=1);

namespace App\Order\Domain;

/**
 * One option inside a ModifierGroup. `modificatorId` is what gets sent
 * back to Poster when this option is chosen.
 *
 * `priceVnd` is the FULL line price for this option (Poster overrides
 * the base product price with the modificator's price, not a delta).
 */
final class ModifierOption
{
    public function __construct(
        public readonly int    $id,
        public readonly string $name,
        public readonly int    $priceVnd,
    ) {}

    public function toJson(): array
    {
        return [
            'id'    => $this->id,
            'name'  => $this->name,
            'price' => $this->priceVnd,
        ];
    }
}

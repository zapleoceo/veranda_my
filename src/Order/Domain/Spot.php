<?php

declare(strict_types=1);

namespace App\Order\Domain;

/** One physical Poster spot (location/venue). */
final class Spot
{
    public function __construct(
        public readonly int    $id,
        public readonly string $name,
        public readonly int    $tabletId,
    ) {}

    public function toJson(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'tablet_id' => $this->tabletId];
    }
}

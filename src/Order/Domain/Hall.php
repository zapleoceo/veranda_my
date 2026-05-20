<?php

declare(strict_types=1);

namespace App\Order\Domain;

/** One hall (zone) inside a spot. */
final class Hall
{
    public function __construct(
        public readonly int    $id,
        public readonly int    $spotId,
        public readonly string $name,
        public readonly int    $sort,
    ) {}

    public function toJson(): array
    {
        return ['id' => $this->id, 'spot_id' => $this->spotId, 'name' => $this->name, 'sort' => $this->sort];
    }
}

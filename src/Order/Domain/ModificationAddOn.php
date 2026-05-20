<?php

declare(strict_types=1);

namespace App\Order\Domain;

/**
 * Optional add-on modification (Poster's group_modifications[]). The
 * operator may pick zero, one, or many; each contributes its full
 * `priceVnd` per chosen unit to the line total.
 */
final class ModificationAddOn
{
    public function __construct(
        public readonly int    $id,
        public readonly int    $groupId,
        public readonly string $groupName,
        public readonly string $name,
        public readonly int    $priceVnd,
    ) {}

    public function toJson(): array
    {
        return [
            'id'         => $this->id,
            'group_id'   => $this->groupId,
            'group_name' => $this->groupName,
            'name'       => $this->name,
            'price'      => $this->priceVnd,
        ];
    }
}

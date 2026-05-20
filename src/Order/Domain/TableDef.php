<?php

declare(strict_types=1);

namespace App\Order\Domain;

/**
 * One Poster table inside a hall. We use `TableDef` rather than
 * `Table` to avoid colliding with PHP's reserved word in some
 * legacy contexts (PDO, etc.).
 */
final class TableDef
{
    public function __construct(
        public readonly int    $id,
        public readonly int    $hallId,
        public readonly string $name,
        public readonly int    $sort,
    ) {}

    public function toJson(): array
    {
        return ['id' => $this->id, 'hall_id' => $this->hallId, 'name' => $this->name, 'sort' => $this->sort];
    }
}

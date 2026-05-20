<?php

declare(strict_types=1);

namespace App\Order\Domain;

/**
 * One menu category as returned by Poster's menu.getCategories.
 * Flat structure — parents are linked via parentId. The frontend
 * walks the tree itself when laying the menu out.
 */
final class Category
{
    public function __construct(
        public readonly int    $id,
        public readonly string $name,
        public readonly int    $parentId,
        public readonly int    $sort,
    ) {}

    public static function fromPoster(array $r): self
    {
        return new self(
            id:       (int)($r['category_id'] ?? 0),
            name:     trim((string)($r['category_name'] ?? '')),
            parentId: (int)($r['parent_category'] ?? 0),
            sort:     (int)($r['sort_order'] ?? 0),
        );
    }

    public function toJson(): array
    {
        return [
            'id'        => $this->id,
            'name'      => $this->name,
            'parent_id' => $this->parentId,
            'sort'      => $this->sort,
        ];
    }
}

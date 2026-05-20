<?php

declare(strict_types=1);

namespace App\Order\Domain;

/**
 * A single line in a submitted cart — server-side projection of the JS
 * cart state. Validated by OrdersService before it's translated to the
 * Poster wire shape.
 *
 *   modificatorId   single chosen ModifierOption.id (0 = none)
 *   modifications   list of {id, count} picked from ModificationAddOn[]
 *   comment         free-form note attached to this line
 */
final class CartLine
{
    /**
     * @param array<int, array{id:int,count:float}> $modifications
     */
    public function __construct(
        public readonly int    $productId,
        public readonly float  $count,
        public readonly int    $modificatorId,
        public readonly array  $modifications,
        public readonly string $comment,
    ) {}

    public static function fromInput(array $r): self
    {
        $count = $r['count'] ?? 1;
        if (!is_numeric($count)) $count = 1;

        $mods = [];
        foreach (($r['modifications'] ?? []) as $m) {
            if (!is_array($m)) continue;
            $mid = (int)($m['id'] ?? 0);
            $mc  = $m['count'] ?? 1;
            if (!is_numeric($mc)) $mc = 1;
            $mc  = (float)$mc;
            if ($mid <= 0 || $mc <= 0) continue;
            $mods[] = ['id' => $mid, 'count' => $mc];
        }

        return new self(
            productId:     (int)($r['product_id'] ?? 0),
            count:         (float)$count,
            modificatorId: (int)($r['modificator_id'] ?? 0),
            modifications: $mods,
            comment:       trim((string)($r['comment'] ?? '')),
        );
    }

    public function isValid(): bool
    {
        return $this->productId > 0 && $this->count > 0;
    }
}

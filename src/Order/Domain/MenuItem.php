<?php

declare(strict_types=1);

namespace App\Order\Domain;

/**
 * One product on the menu as we surface it to the operator.
 *
 * Two flavours of "modifications" exist on Poster's side; we keep both:
 *
 *   modifierGroups  — required single-choice / multi-choice groups (e.g.
 *                     "Size: S/M/L"). Each group has its own price for
 *                     each option (Poster's `modificators` field on
 *                     dish-type products). The order must pick exactly
 *                     one when the group is required.
 *
 *   modifications   — optional add-ons (e.g. "extra cheese +30 000",
 *                     Poster's `group_modifications` array on dishes).
 *                     User can pick zero, one, or many; each has a
 *                     positive price contributing to the line total.
 *
 * Price is stored in minor units (Poster sends `price[<spot>]` in
 * 1/100 VND — divide by 100 to get the wall-clock VND figure). Our
 * services normalise that before constructing the DTO.
 */
final class MenuItem
{
    /**
     * @param ModifierGroup[]  $modifierGroups
     * @param ModificationAddOn[] $modifications
     */
    public function __construct(
        public readonly int    $id,
        public readonly int    $categoryId,
        public readonly string $name,
        public readonly int    $priceVnd,
        public readonly bool   $hidden,
        public readonly string $photoUrl,
        public readonly array  $modifierGroups,
        public readonly array  $modifications,
        public readonly int    $sort,
    ) {}

    public function toJson(): array
    {
        return [
            'id'              => $this->id,
            'category_id'     => $this->categoryId,
            'name'            => $this->name,
            'price'           => $this->priceVnd,
            'hidden'          => $this->hidden,
            'photo'           => $this->photoUrl,
            'sort'            => $this->sort,
            'modifier_groups' => array_map(fn(ModifierGroup $g) => $g->toJson(), $this->modifierGroups),
            'modifications'   => array_map(fn(ModificationAddOn $m) => $m->toJson(), $this->modifications),
        ];
    }
}

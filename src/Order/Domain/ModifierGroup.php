<?php

declare(strict_types=1);

namespace App\Order\Domain;

/**
 * Required modifier group (Poster's product.modificators[]). The
 * operator must pick exactly one of the options if the group is
 * marked required; we surface that flag to the UI so it can block
 * "add to cart" until a choice is made.
 */
final class ModifierGroup
{
    /**
     * @param ModifierOption[] $options
     */
    public function __construct(
        public readonly int    $id,
        public readonly string $name,
        public readonly bool   $required,
        public readonly array  $options,
    ) {}

    public function toJson(): array
    {
        return [
            'id'       => $this->id,
            'name'     => $this->name,
            'required' => $this->required,
            'options'  => array_map(fn(ModifierOption $o) => $o->toJson(), $this->options),
        ];
    }
}

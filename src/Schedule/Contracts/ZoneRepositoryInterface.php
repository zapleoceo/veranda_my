<?php

declare(strict_types=1);

namespace App\Schedule\Contracts;

interface ZoneRepositoryInterface
{
    /** Active custom zones (Беседка, Терраса, …), sorted by sort_order then id. */
    public function listActive(): array;

    /** Insert a new active zone; returns its id. */
    public function add(string $name, string $icon = '🌿'): int;

    /** Soft-delete by setting is_active = 0. */
    public function softDelete(int $id): void;
}

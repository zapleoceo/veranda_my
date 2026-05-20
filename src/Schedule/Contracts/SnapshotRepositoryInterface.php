<?php

declare(strict_types=1);

namespace App\Schedule\Contracts;

interface SnapshotRepositoryInterface
{
    /** Returns latest snapshot JSON as array, or null if no rows. */
    public function loadCurrent(): ?array;

    /** Stores new snapshot; returns its id. Older rows keep their is_current=0. */
    public function save(array $state, string $label, string $email): int;

    /** Recent snapshots metadata (no JSON payload). */
    public function listRecent(int $limit = 25): array;

    /** Single snapshot's JSON by id, or null if missing. */
    public function loadById(int $id): ?array;

    /** Soft-deletes (skips if it's the current one). True on success. */
    public function delete(int $id): bool;
}

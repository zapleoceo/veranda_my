<?php

declare(strict_types=1);

namespace App\Schedule\Contracts;

interface SnapshotRepositoryInterface
{
    /** Returns the current draft's JSON, or null if no draft exists yet. */
    public function loadCurrent(): ?array;

    /**
     * Persists the current draft state — UPDATEs the single is_current=1
     * row (or inserts if none exists). Never creates a new named version.
     */
    public function saveCurrent(array $state, string $email): int;

    /**
     * Saves the given state as a NEW named version (is_current=0). The
     * label is required and shown in the UI; the current draft row is
     * untouched. Returns the new snapshot id.
     */
    public function saveNamedVersion(array $state, string $label, string $email): int;

    /**
     * Renames an existing named version. Refuses to rename the draft row
     * (is_current=1) or to set an empty label. True on success.
     */
    public function rename(int $id, string $label): bool;

    /** Named versions only (excludes the draft row + legacy auto labels). */
    public function listRecent(int $limit = 25): array;

    /** Single snapshot's JSON by id, or null if missing. */
    public function loadById(int $id): ?array;

    /**
     * Looks up a named version by its public share code. Returns
     * ['id','label','created_at','share_code','state'] or null.
     */
    public function loadByShareCode(string $code): ?array;

    /** Soft-deletes (skips if it's the current draft). True on success. */
    public function delete(int $id): bool;
}

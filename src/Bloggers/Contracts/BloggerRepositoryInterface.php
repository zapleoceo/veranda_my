<?php

declare(strict_types=1);

namespace App\Bloggers\Contracts;

/**
 * Local persistence for blogger data Poster has no field for: cashback %,
 * the active flag, and the module config (Poster group + finance category).
 * Email is Poster's own client.email — no local copy needed.
 */
interface BloggerRepositoryInterface
{
    /**
     * @return array<int,array{cashback_pct:float,is_active:int,created_by:string}>
     *         keyed by poster_client_id
     */
    public function allByClientId(): array;

    /** Insert a new blogger row (or re-activate + refresh cashback on duplicate). */
    public function create(int $clientId, float $cashbackPct, string $createdBy): void;

    /** Update cashback %, preserving is_active / created_by (upsert). */
    public function saveCashback(int $clientId, float $cashbackPct): void;

    public function setActive(int $clientId, bool $active): void;

    /** @return array{group_id:int,payout_category_id:int} (defaults when unset) */
    public function loadConfig(): array;

    public function saveConfig(int $groupId, int $payoutCategoryId): void;
}

<?php

declare(strict_types=1);

namespace App\Bloggers\Contracts;

/**
 * Local persistence for blogger data that Poster has no field for: cashback %,
 * the gmail↔client link (cabinet login, phase 2) and the active flag.
 */
interface BloggerRepositoryInterface
{
    /**
     * @return array<int,array{cashback_pct:float,gmail:string,is_active:int,created_by:string}>
     *         keyed by poster_client_id
     */
    public function allByClientId(): array;

    /** Insert a new blogger row (or re-activate + refresh an existing one). */
    public function create(int $clientId, string $gmail, float $cashbackPct, string $createdBy): void;

    /** Update cashback % and gmail, preserving is_active / created_by (upsert). */
    public function saveCashbackAndGmail(int $clientId, string $gmail, float $cashbackPct): void;

    public function setActive(int $clientId, bool $active): void;
}

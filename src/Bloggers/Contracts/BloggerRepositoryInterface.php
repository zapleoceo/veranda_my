<?php

declare(strict_types=1);

namespace App\Bloggers\Contracts;

/**
 * Local persistence for the two things Poster can't hold server-side: the
 * approval flag (is_active) and who created the row. Everything else —
 * cashback %, limit %, socials, display name — is packed into the Poster
 * client comment (see App\Bloggers\Support\BloggerMeta); discount lives in
 * Poster's native discount_per.
 *
 * `cashback_pct` is still returned by allByClientId() purely as a migration
 * fallback for rows created before the comment became the source of truth.
 */
interface BloggerRepositoryInterface
{
    /**
     * @return array<int,array{cashback_pct:float,is_active:int,created_by:string}>
     *         keyed by poster_client_id
     */
    public function allByClientId(): array;

    /** Insert a local tracking row (or re-activate an existing one). is_active=1. */
    public function create(int $clientId, string $createdBy): void;

    public function setActive(int $clientId, bool $active): void;

    /** @return array{group_id:int,payout_category_id:int} (defaults when unset) */
    public function loadConfig(): array;

    public function saveConfig(int $groupId, int $payoutCategoryId): void;
}

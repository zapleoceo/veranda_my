<?php

declare(strict_types=1);

namespace App\Payday3\Contracts;

use App\Payday3\Domain\DateRange;
use App\Payday3\Domain\ReconciliationLink;

interface LinkRepositoryInterface
{
    /** @return ReconciliationLink[] all sepay↔poster edges that fall in the date window. */
    public function listInRange(DateRange $range): array;

    /** True if a link with this exact pair already exists. */
    public function exists(int $sepayId, int $posterTransactionId): bool;

    /** Idempotent insert. */
    public function add(ReconciliationLink $link): void;

    /** Remove a single edge. */
    public function remove(int $sepayId, int $posterTransactionId): void;

    /** Wipe every edge whose sepay or poster row falls in the range. */
    public function clearInRange(DateRange $range): int;
}

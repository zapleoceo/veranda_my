<?php

declare(strict_types=1);

namespace App\Payday3\Contracts;

use App\Payday3\Domain\DateRange;
use App\Payday3\Domain\SepayTransaction;

interface SepayRepositoryInterface
{
    /** @return SepayTransaction[] inbound transactions in range, not hidden, not linked. */
    public function listOpenInRange(DateRange $range): array;

    /** @return SepayTransaction[] inbound transactions in range that have been hidden by the user. */
    public function listHiddenInRange(DateRange $range): array;

    /**
     * Hide a sepay transaction (INSERT IGNORE into sepay_hidden).
     * Idempotent — calling on an already-hidden row is a no-op.
     */
    public function hide(int $sepayId, string $comment = ''): void;

    /**
     * Restore a previously-hidden sepay transaction (DELETE from sepay_hidden).
     * Idempotent — calling on a non-hidden row is a no-op.
     */
    public function unhide(int $sepayId): void;

    /** Returns true if this sepay_id currently has a sepay_hidden row. */
    public function isHidden(int $sepayId): bool;
}

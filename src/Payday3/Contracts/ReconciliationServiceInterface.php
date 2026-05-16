<?php

declare(strict_types=1);

namespace App\Payday3\Contracts;

use App\Payday3\Domain\DateRange;

interface ReconciliationServiceInterface
{
    /**
     * Run the auto-matcher for the given range.
     *
     * @return array{added:int, total:int} number of newly-created links
     *         plus the total count after the run.
     */
    public function autoLink(DateRange $range): array;

    /**
     * Create a manual link between exactly one sepay row and one
     * poster row. Idempotent. No-op if either side already has a
     * conflicting link.
     */
    public function manualLink(int $sepayId, int $posterTransactionId): void;

    /** Delete one specific edge. */
    public function unlink(int $sepayId, int $posterTransactionId): void;

    /**
     * Drop every link whose poster row falls into the range.
     * @return int rows deleted
     */
    public function clearLinks(DateRange $range): int;
}

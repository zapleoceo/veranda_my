<?php

declare(strict_types=1);

namespace App\PosterApp\Contracts;

use App\PosterApp\Domain\WorkShift;

interface WorkShiftRepositoryInterface
{
    /** Open shifts owned by this user (usually zero or one). */
    public function findOpenForUser(int $posterUserId): ?WorkShift;

    /** Insert a fresh shift row. Returns the new id. */
    public function open(int $posterUserId, ?int $posterShiftId, string $source): int;

    /** Close by row id. No-op when already closed. */
    public function closeById(int $shiftId): void;

    /** Close any open shift owned by the user. Convenience wrapper. */
    public function closeOpenForUser(int $posterUserId): void;

    /**
     * Close by Poster shift id — used when the POS widget receives
     * a shiftClose event. May affect 0 or 1 row.
     */
    public function closeByPosterShiftId(int $posterShiftId): void;

    /**
     * @return WorkShift[]  shifts opened OR closed in the range
     *                      (operator timesheet view uses this).
     */
    public function listInRange(string $dateFrom, string $dateTo): array;
}

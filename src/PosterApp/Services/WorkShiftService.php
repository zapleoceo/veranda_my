<?php

declare(strict_types=1);

namespace App\PosterApp\Services;

use App\PosterApp\Contracts\WorkShiftRepositoryInterface;
use App\PosterApp\Domain\WorkShift;

/**
 * Use-case wrapper over WorkShiftRepository. Encapsulates the
 * "open only when no open one exists" / "close only if open" rules
 * so HTTP actions stay one-liners.
 *
 * source labels:
 *   'pos_widget'    — opened via Poster POS shiftOpen / userLogin event
 *   'neworder_pin'  — opened via /neworder web PIN entry
 *   'admin_manual'  — opened/closed via the admin timesheet page
 */
final class WorkShiftService
{
    public const SOURCE_POS_WIDGET   = 'pos_widget';
    public const SOURCE_NEWORDER_PIN = 'neworder_pin';
    public const SOURCE_ADMIN_MANUAL = 'admin_manual';

    public function __construct(
        private readonly WorkShiftRepositoryInterface $repo,
    ) {}

    /** Open a shift if the user doesn't already have one. Returns the active shift either way. */
    public function ensureOpen(int $posterUserId, ?int $posterShiftId, string $source): WorkShift
    {
        if ($posterUserId <= 0) {
            throw new \InvalidArgumentException('poster_user_id required');
        }
        $existing = $this->repo->findOpenForUser($posterUserId);
        if ($existing !== null) return $existing;
        $id  = $this->repo->open($posterUserId, $posterShiftId, $source);
        $now = date('Y-m-d H:i:s');
        // Synthesize a domain object without a re-read — the values
        // we just wrote are authoritative.
        return new WorkShift(
            id:             $id,
            posterUserId:   $posterUserId,
            posterShiftId:  $posterShiftId,
            startedAt:      $now,
            endedAt:        null,
            source:         $source,
        );
    }

    public function closeForUser(int $posterUserId): void
    {
        $this->repo->closeOpenForUser($posterUserId);
    }

    public function closeByPosterShiftId(int $posterShiftId): void
    {
        $this->repo->closeByPosterShiftId($posterShiftId);
    }

    /** @return WorkShift[] */
    public function listInRange(string $dateFrom, string $dateTo): array
    {
        return $this->repo->listInRange($dateFrom, $dateTo);
    }

    public function currentOpenFor(int $posterUserId): ?WorkShift
    {
        return $this->repo->findOpenForUser($posterUserId);
    }
}

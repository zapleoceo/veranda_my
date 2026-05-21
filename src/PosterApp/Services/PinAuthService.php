<?php

declare(strict_types=1);

namespace App\PosterApp\Services;

use App\PosterApp\Contracts\EmployeePinRepositoryInterface;
use App\PosterApp\Domain\EmployeePin;

/**
 * Two-way bridge over EmployeePinRepository:
 *
 *   learnFromWidget()  POS widget receives a userLogin event with
 *                      `user.posPass` — we trust it, hash & upsert
 *                      so the /neworder web flow can later validate
 *                      a PIN entered manually.
 *
 *   authenticate()     /neworder web flow — operator types PIN on
 *                      a separate browser; we look up the matching
 *                      EmployeePin (constant-time per row).
 *
 * Keeping the repo's storage details out of the actions makes both
 * surfaces testable with a fake repo.
 */
final class PinAuthService
{
    public function __construct(
        private readonly EmployeePinRepositoryInterface $pins,
    ) {}

    public function learnFromWidget(int $posterUserId, string $pinPlain, string $displayName, bool $isAdmin): void
    {
        if ($posterUserId <= 0 || $pinPlain === '') {
            throw new \InvalidArgumentException('poster_user_id and pin are required');
        }
        $this->pins->learn($posterUserId, $pinPlain, $displayName, $isAdmin);
    }

    /** Bcrypt-verify against every known employee. Returns the matching EmployeePin or null. */
    public function authenticate(string $pinPlain): ?EmployeePin
    {
        if ($pinPlain === '') return null;
        $hit = $this->pins->findByPin($pinPlain);
        if ($hit !== null) {
            $this->pins->touchLastSeen($hit->posterUserId);
        }
        return $hit;
    }
}

<?php

declare(strict_types=1);

namespace App\PosterApp\Contracts;

use App\PosterApp\Domain\EmployeePin;

interface EmployeePinRepositoryInterface
{
    /** Upsert from a POS userLogin event — learn or refresh the PIN hash + cached name. */
    public function learn(int $posterUserId, string $pinPlain, string $displayName, bool $isAdmin): void;

    /** Lookup by Poster user id; null when we've never seen this user. */
    public function find(int $posterUserId): ?EmployeePin;

    /**
     * Best-match across all known employees by raw PIN — used for
     * /neworder web-flow when there's no Poster context to identify
     * the user. Returns the matching EmployeePin or null.
     *
     * Be aware: the brute-force surface is the whole table size, so
     * the caller must rate-limit attempts.
     */
    public function findByPin(string $pinPlain): ?EmployeePin;

    public function touchLastSeen(int $posterUserId): void;
}

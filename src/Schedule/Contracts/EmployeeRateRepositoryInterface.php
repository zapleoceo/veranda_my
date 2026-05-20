<?php

declare(strict_types=1);

namespace App\Schedule\Contracts;

/**
 * Canonical source of hourly rates — shared with the /employees/ page.
 * The schedule reads and writes through this so that a rate edited in
 * one UI shows up in the other immediately.
 *
 * Storage: `employee_rates` (user_id PK, rate BIGINT VND/hour).
 */
interface EmployeeRateRepositoryInterface
{
    /** All rates keyed by user_id: [user_id => rate_vnd_per_hour]. */
    public function all(): array;

    /** Upsert a single rate (VND/hour, plain integer). */
    public function save(int $userId, int $rateVndPerHour, ?string $by = null): void;
}

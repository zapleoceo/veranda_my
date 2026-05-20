<?php

declare(strict_types=1);

namespace App\Schedule\Contracts;

interface StaffTagRepositoryInterface
{
    /**
     * Schedule-only tag flags keyed by user_id. The hourly rate is NOT
     * stored here — it's owned by EmployeeRateRepositoryInterface so the
     * /employees/ page and the schedule share a single source of truth.
     *
     *   [user_id => ['in_schedule'=>bool, 'can_be_senior'=>bool,
     *                'only_in_blocks'=>string, 'custom_tag'=>string]]
     */
    public function all(): array;

    /** Upsert single user's tag bundle. */
    public function save(int $userId, array $tag): void;
}

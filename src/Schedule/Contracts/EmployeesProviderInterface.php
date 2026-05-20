<?php

declare(strict_types=1);

namespace App\Schedule\Contracts;

interface EmployeesProviderInterface
{
    /**
     * Roster ready for the schedule UI:
     *   [['id'=>int, 'name'=>string, 'poster_role'=>string, 'tag'=>string,
     *     'in_schedule'=>bool, 'can_be_senior'=>bool, 'only_in_blocks'=>string,
     *     'rate_per_hour'=>int], …]
     */
    public function fetch(): array;

    /** Drop any cached employee data so next fetch() hits the source. */
    public function purgeCache(): void;
}

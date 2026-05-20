<?php

declare(strict_types=1);

namespace App\Schedule\Contracts;

interface HallsProviderInterface
{
    /**
     * List of halls available to assign to blocks:
     *   [['id'=>int, 'name'=>string, 'icon'=>string], …]
     */
    public function fetch(): array;

    public function purgeCache(): void;
}

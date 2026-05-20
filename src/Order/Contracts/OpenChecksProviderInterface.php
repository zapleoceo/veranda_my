<?php

declare(strict_types=1);

namespace App\Order\Contracts;

use App\Order\Domain\OpenCheck;

interface OpenChecksProviderInterface
{
    /**
     * Open checks (status=1) on a specific table.
     *
     * @return OpenCheck[]
     */
    public function fetchForTable(int $spotId, int $tableId): array;
}

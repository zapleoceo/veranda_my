<?php

declare(strict_types=1);

namespace App\Order\Contracts;

use App\Order\Domain\Hall;
use App\Order\Domain\Spot;
use App\Order\Domain\TableDef;

/**
 * Reads spots / halls / tables from Poster (`spots.getSpotTablesHalls`,
 * `spots.getTableHallTables`, `spots.getSpot`). One call returns the
 * whole tree because the operator's table picker is cascading.
 */
interface PosterLocationProviderInterface
{
    /**
     * @return array{spots: Spot[], halls: Hall[], tables: TableDef[]}
     */
    public function fetchAll(): array;
}

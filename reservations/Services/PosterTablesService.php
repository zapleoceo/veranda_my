<?php
declare(strict_types=1);

namespace Reservations\Services;

use App\Classes\PosterAPI;

class PosterTablesService {
    private PosterAPI $api;

    public function __construct(PosterAPI $api) {
        $this->api = $api;
    }

    public function getHallTables(int $spotId, int $hallId): array {
        $rows = $this->api->request('spots.getTableHallTables', [
            'spot_id' => $spotId,
            'hall_id' => $hallId,
            'without_deleted' => 1,
        ], 'GET');
        return is_array($rows) ? $rows : [];
    }
}


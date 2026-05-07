<?php
declare(strict_types=1);

namespace Reservations\Repositories;

use App\Classes\Database;

class HallSettingsRepository {
    private Database $db;
    private string $tbl;

    public function __construct(Database $db) {
        $this->db = $db;
        $this->tbl = $db->t('reservation_hall_settings');
    }

    public function getRotate180(int $spotId, int $hallId): int {
        if ($spotId <= 0 || $hallId <= 0) return 0;
        $row = $this->db->query(
            "SELECT rotate_180 FROM {$this->tbl} WHERE spot_id = ? AND hall_id = ? LIMIT 1",
            [$spotId, $hallId]
        )->fetch();
        return is_array($row) ? ((int)($row['rotate_180'] ?? 0) ? 1 : 0) : 0;
    }

    public function upsertRotate180(int $spotId, int $hallId, int $rotate180): void {
        if ($spotId <= 0 || $hallId <= 0) return;
        $v = $rotate180 ? 1 : 0;
        $this->db->query(
            "INSERT INTO {$this->tbl} (spot_id, hall_id, rotate_180) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE rotate_180 = VALUES(rotate_180)",
            [$spotId, $hallId, $v]
        );
    }
}


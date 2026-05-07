<?php
declare(strict_types=1);

return static function (\App\Classes\Database $db): void {
    $pdo = $db->getPdo();
    $tbl = $db->t('reservation_hall_settings');
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS {$tbl} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            spot_id INT NOT NULL,
            hall_id INT NOT NULL,
            rotate_180 TINYINT(1) NOT NULL DEFAULT 0,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_spot_hall (spot_id, hall_id),
            KEY idx_spot_hall (spot_id, hall_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
};


<?php
declare(strict_types=1);

return static function (\App\Classes\Database $db): void {
    $pdo = $db->getPdo();

    $tbl = $db->t('reservation_table_settings');
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS {$tbl} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            spot_id INT NOT NULL,
            hall_id INT NOT NULL,
            poster_table_id INT NOT NULL,
            scheme_num INT NULL,
            display_name VARCHAR(80) NULL,
            show_on_canvas TINYINT(1) NOT NULL DEFAULT 1,
            bookable TINYINT(1) NOT NULL DEFAULT 1,
            capacity INT NOT NULL DEFAULT 0,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_spot_hall_table (spot_id, hall_id, poster_table_id),
            KEY idx_spot_hall (spot_id, hall_id),
            KEY idx_scheme (spot_id, hall_id, scheme_num)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $decor = $db->t('reservation_hall_decor_items');
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS {$decor} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            spot_id INT NOT NULL,
            hall_id INT NOT NULL,
            decor_type VARCHAR(32) NOT NULL,
            x FLOAT NOT NULL,
            y FLOAT NOT NULL,
            w FLOAT NOT NULL,
            h FLOAT NOT NULL,
            z INT NOT NULL DEFAULT 1,
            props_json TEXT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_spot_hall (spot_id, hall_id),
            KEY idx_type (spot_id, hall_id, decor_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
};


<?php
declare(strict_types=1);

return static function (\App\Classes\Database $db): void {
    $pdo = $db->getPdo();
    $tbl = $db->t('reservations');
    $pdo->exec("CREATE TABLE IF NOT EXISTS {$tbl} (
        id INT AUTO_INCREMENT PRIMARY KEY,
        created_at DATETIME NOT NULL,
        start_time DATETIME NOT NULL,
        duration INT DEFAULT 120,
        guests INT NOT NULL,
        table_num VARCHAR(32) NOT NULL,
        name VARCHAR(128) NOT NULL,
        phone VARCHAR(64) NOT NULL,
        KEY idx_start_time (start_time)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $cols = [];
    foreach ($pdo->query("SHOW COLUMNS FROM {$tbl}") as $c) {
        $f = strtolower((string)($c['Field'] ?? ''));
        if ($f !== '') $cols[$f] = true;
    }
    if (empty($cols['poster_table_id'])) {
        $pdo->exec("ALTER TABLE {$tbl} ADD COLUMN poster_table_id INT NULL AFTER table_num");
    }
};


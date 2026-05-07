<?php
declare(strict_types=1);

function reservations_run_migrations(\App\Classes\Database $db): void {
    $root = __DIR__;
    $migrations = [
        1 => $root . '/001_create_table_settings.php',
        2 => $root . '/002_create_hall_settings.php',
        3 => $root . '/003_add_poster_table_id_to_reservations.php',
    ];

    require_once dirname(__DIR__, 2) . '/src/classes/MetaRepository.php';
    $meta = new \App\Classes\MetaRepository($db);
    $key = 'reservations_migration_version';
    $vals = $meta->getMany([$key]);
    $cur = array_key_exists($key, $vals) ? (int)trim((string)$vals[$key]) : 0;

    ksort($migrations);
    foreach ($migrations as $ver => $file) {
        if ($ver <= $cur) continue;
        if (!file_exists($file)) continue;
        $fn = require $file;
        if (is_callable($fn)) $fn($db);
        $meta->set($key, (string)$ver);
        $cur = $ver;
    }
}

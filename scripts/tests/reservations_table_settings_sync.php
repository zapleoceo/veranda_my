<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$envFile = $root . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $t = trim($line);
        if ($t === '' || str_starts_with($t, '#')) continue;
        if (!str_contains($t, '=')) continue;
        [$name, $value] = explode('=', $line, 2);
        $_ENV[trim($name)] = trim($value);
    }
}

$host = trim((string)($_ENV['DB_HOST'] ?? ''));
$name = trim((string)($_ENV['DB_NAME'] ?? ''));
$user = trim((string)($_ENV['DB_USER'] ?? ''));
$pass = (string)($_ENV['DB_PASS'] ?? '');
$suffix = (string)($_ENV['DB_TABLE_SUFFIX'] ?? '');

if ($host === '' || $name === '' || $user === '') {
    fwrite(STDOUT, "SKIP: DB not configured\n");
    exit(0);
}

require_once $root . '/src/classes/Database.php';
require_once $root . '/src/classes/MetaRepository.php';
require_once $root . '/reservations/migrations/run.php';
require_once $root . '/reservations/legacy_meta_sync.php';

$db = new \App\Classes\Database($host, $name, $user, $pass, $suffix);
reservations_run_migrations($db);

$spotId = 9999;
$hallId = 9999;
$tbl = $db->t('reservation_table_settings');

$db->query(
    "DELETE FROM {$tbl} WHERE spot_id = ? AND hall_id = ?",
    [$spotId, $hallId]
);

$db->query(
    "INSERT INTO {$tbl} (spot_id, hall_id, poster_table_id, scheme_num, display_name, show_on_canvas, bookable, capacity)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE scheme_num = VALUES(scheme_num), bookable = VALUES(bookable), capacity = VALUES(capacity)",
    [$spotId, $hallId, 101, 10, 'T10', 1, 1, 4]
);
$db->query(
    "INSERT INTO {$tbl} (spot_id, hall_id, poster_table_id, scheme_num, display_name, show_on_canvas, bookable, capacity)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE scheme_num = VALUES(scheme_num), bookable = VALUES(bookable), capacity = VALUES(capacity)",
    [$spotId, $hallId, 102, 11, 'T11', 1, 0, 6]
);

reservations_sync_legacy_meta($db, $spotId, $hallId);

$meta = new \App\Classes\MetaRepository($db);
$allowedKey = 'reservations_allowed_scheme_nums_hall_' . $hallId;
$capsKey = 'reservations_table_caps_hall_' . $hallId;
$vals = $meta->getMany([$allowedKey, $capsKey]);

$allowed = array_key_exists($allowedKey, $vals) ? json_decode((string)$vals[$allowedKey], true) : null;
$caps = array_key_exists($capsKey, $vals) ? json_decode((string)$vals[$capsKey], true) : null;

if (!is_array($allowed) || !in_array(10, $allowed, true) || in_array(11, $allowed, true)) {
    fwrite(STDERR, "FAIL: allowed meta mismatch\n");
    exit(1);
}
if (!is_array($caps) || (int)($caps['10'] ?? -1) !== 4 || (int)($caps['11'] ?? -1) !== 6) {
    fwrite(STDERR, "FAIL: caps meta mismatch\n");
    exit(1);
}

fwrite(STDOUT, "OK\n");
exit(0);


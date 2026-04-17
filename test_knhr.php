<?php
require_once __DIR__ . '/src/classes/Database.php';
require_once __DIR__ . '/src/classes/PosterAPI.php';
require_once __DIR__ . '/src/classes/PosterReservationHelper.php';

if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) continue;
        [$name, $value] = explode('=', $line, 2);
        $_ENV[$name] = trim($value);
    }
}

$dbHost = $_ENV['DB_HOST'] ?? 'localhost';
if ($dbHost === 'localhost') {
    $dbHost = '127.0.0.1'; // Avoid unix socket error
}

$db = new \App\Classes\Database(
    $dbHost,
    $_ENV['DB_NAME'] ?? 'veranda_my',
    $_ENV['DB_USER'] ?? 'veranda_my',
    $_ENV['DB_PASS'] ?? '',
    (string)($_ENV['DB_TABLE_SUFFIX'] ?? '')
);

$resTable = $db->t('reservations');
$row = $db->query("SELECT * FROM {$resTable} WHERE qr_code = 'KNHR6N9H' LIMIT 1")->fetch();

if (!$row) {
    die("Reservation KNHR6N9H not found\n");
}

echo "Reservation Data from DB:\n";
print_r($row);

$id = $row['id'];
$token = $_ENV['POSTER_API_TOKEN'] ?? '';
$spotId = $_ENV['POSTER_SPOT_ID'] ?? '1';

echo "\n--- Pushing to Poster ---\n";

// Reset pushed state to allow testing
$db->query("UPDATE {$resTable} SET is_poster_pushed = 0 WHERE id = ?", [$id]);

// Call push
$result = \App\Classes\PosterReservationHelper::pushToPoster($db, $token, $id, $spotId, 'test_user');

echo "\nResult:\n";
print_r($result);

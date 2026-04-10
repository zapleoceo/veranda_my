<?php
require __DIR__ . '/src/classes/Database.php';
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            [$name, $value] = explode('=', $line, 2);
            $_ENV[trim($name)] = trim($value);
        }
    }
}
$db = new \App\Classes\Database(
    $_ENV['DB_HOST'] ?? 'localhost',
    $_ENV['DB_NAME'] ?? 'veranda_my',
    $_ENV['DB_USER'] ?? 'veranda_my',
    $_ENV['DB_PASS'] ?? '',
    (string)($_ENV['DB_TABLE_SUFFIX'] ?? '')
);
$resTable = $db->t('reservations');
try {
    $stmt = $db->query("SELECT * FROM {$resTable} ORDER BY id DESC LIMIT 5");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) echo "Table is empty.\n";
    else print_r($rows);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

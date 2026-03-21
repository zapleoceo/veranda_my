<?php

date_default_timezone_set('Asia/Ho_Chi_Minh');

require_once __DIR__ . '/../src/classes/Database.php';

if (file_exists(__DIR__ . '/../.env')) {
    $lines = file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $t = trim($line);
        if ($t === '' || $t[0] === '#') continue;
        if (strpos($t, '=') === false) continue;
        [$name, $value] = explode('=', $line, 2);
        $_ENV[$name] = trim($value);
    }
}

$dbHost = $_ENV['DB_HOST'] ?? 'localhost';
$dbName = $_ENV['DB_NAME'] ?? 'veranda_my';
$dbUser = $_ENV['DB_USER'] ?? 'veranda_my';
$dbPass = $_ENV['DB_PASS'] ?? '';

$db = new \App\Classes\Database($dbHost, $dbName, $dbUser, $dbPass, '');

$tables = $db->query("SHOW TABLES")->fetchAll(\PDO::FETCH_NUM);
$names = [];
foreach ($tables as $r) {
    if (!empty($r[0])) $names[] = (string)$r[0];
}

$pairs = [];
foreach ($names as $t) {
    if (substr($t, -2) !== '_2') continue;
    $base = substr($t, 0, -2);
    if ($base === '') continue;
    $pairs[$base] = $t;
}

if (empty($pairs)) {
    echo json_encode(['ok' => false, 'error' => 'no *_2 tables found'], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(2);
}

$legacySuffix = '_legacy_' . date('Ymd_His');
$ops = [];
foreach ($pairs as $base => $t2) {
    if (in_array($base, $names, true)) {
        $ops[] = "`{$base}` TO `{$base}{$legacySuffix}`";
    }
    $ops[] = "`{$t2}` TO `{$base}`";
}

$sql = "RENAME TABLE " . implode(', ', $ops);
$db->query($sql);

echo json_encode([
    'ok' => true,
    'legacy_suffix' => $legacySuffix,
    'renamed' => count($ops),
    'bases' => array_keys($pairs),
], JSON_UNESCAPED_UNICODE) . PHP_EOL;


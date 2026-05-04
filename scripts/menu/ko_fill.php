<?php

require_once __DIR__ . '/../../src/classes/Database.php';

$loadEnv = function (string $path): void {
    if (!is_file($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) return;
    foreach ($lines as $line) {
        $t = trim((string)$line);
        if ($t === '' || $t[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        [$name, $value] = explode('=', $line, 2);
        $name = trim((string)$name);
        if ($name === '') continue;
        $_ENV[$name] = trim(trim((string)$value), "\"'");
    }
};

$argv = isset($argv) && is_array($argv) ? $argv : [];
$mode = isset($argv[1]) ? (string)$argv[1] : 'dump';

$limit = 0;
foreach ($argv as $a) {
    if (preg_match('/^--limit=(\d+)$/', (string)$a, $m)) {
        $limit = (int)$m[1];
    }
}
if ($limit < 0) $limit = 0;

$loadEnv(__DIR__ . '/../../.env');

$spotTzName = trim((string)($_ENV['POSTER_SPOT_TIMEZONE'] ?? ''));
if ($spotTzName === '' || !in_array($spotTzName, timezone_identifiers_list(), true)) {
    $spotTzName = 'Asia/Ho_Chi_Minh';
}
$apiTzName = trim((string)($_ENV['POSTER_API_TIMEZONE'] ?? ''));
if ($apiTzName === '' || !in_array($apiTzName, timezone_identifiers_list(), true)) {
    $apiTzName = $spotTzName;
}
date_default_timezone_set($apiTzName);

$dbHost = $_ENV['DB_HOST'] ?? 'localhost';
$dbName = $_ENV['DB_NAME'] ?? 'veranda_my';
$dbUser = $_ENV['DB_USER'] ?? 'veranda_my';
$dbPass = $_ENV['DB_PASS'] ?? '';
$tableSuffix = (string)($_ENV['DB_TABLE_SUFFIX'] ?? '');

$db = new \App\Classes\Database($dbHost, $dbName, $dbUser, $dbPass, $tableSuffix);

$mi = $db->t('menu_items');
$mit = $db->t('menu_item_tr');
$pmi = $db->t('poster_menu_items');

if ($mode === 'dump') {
    $where = "p.is_active=1
        AND COALESCE(NULLIF(TRIM(ru.title), ''), '') <> ''
        AND (ko.item_id IS NULL OR COALESCE(NULLIF(TRIM(ko.title), ''), '') = '')";

    $countSql = "
        SELECT COUNT(1) c
        FROM {$mi} mi
        JOIN {$pmi} p ON p.id = mi.poster_item_id
        LEFT JOIN {$mit} ru ON ru.item_id = mi.id AND ru.lang = 'ru'
        LEFT JOIN {$mit} ko ON ko.item_id = mi.id AND ko.lang = 'ko'
        WHERE {$where}
    ";
    $total = (int)$db->query($countSql)->fetchColumn();

    $limitSql = $limit > 0 ? (" LIMIT " . (int)$limit) : "";
    $rowsSql = "
        SELECT
            mi.id item_id,
            p.poster_id,
            ru.title ru_title,
            ko.title ko_title
        FROM {$mi} mi
        JOIN {$pmi} p ON p.id = mi.poster_item_id
        LEFT JOIN {$mit} ru ON ru.item_id = mi.id AND ru.lang = 'ru'
        LEFT JOIN {$mit} ko ON ko.item_id = mi.id AND ko.lang = 'ko'
        WHERE {$where}
        ORDER BY p.poster_id ASC
        {$limitSql}
    ";
    $rows = $db->query($rowsSql)->fetchAll();
    if (!is_array($rows)) $rows = [];

    echo json_encode(['total' => $total, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($mode === 'apply') {
    $raw = file_get_contents('php://stdin');
    if (!is_string($raw) || trim($raw) === '') {
        fwrite(STDERR, "Expected JSON array on STDIN\n");
        exit(1);
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        fwrite(STDERR, "Bad JSON\n");
        exit(1);
    }

    $updated = 0;
    $skipped = 0;
    foreach ($decoded as $row) {
        if (!is_array($row)) {
            $skipped++;
            continue;
        }
        $itemId = (int)($row['item_id'] ?? 0);
        $koTitle = trim((string)($row['ko_title'] ?? ''));
        if ($itemId <= 0 || $koTitle === '') {
            $skipped++;
            continue;
        }

        $db->query(
            "INSERT INTO {$mit} (item_id, lang, title, description)
             VALUES (?, 'ko', ?, NULL)
             ON DUPLICATE KEY UPDATE title = VALUES(title)",
            [$itemId, $koTitle]
        );
        $updated++;
    }

    echo json_encode(['ok' => true, 'updated' => $updated, 'skipped' => $skipped], JSON_UNESCAPED_UNICODE);
    exit;
}

fwrite(STDERR, "Unknown mode: {$mode}\n");
exit(1);

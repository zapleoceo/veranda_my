<?php
require_once __DIR__ . '/../../src/classes/Database.php';
require_once __DIR__ . '/../../src/classes/PosterAPI.php';

if (file_exists(__DIR__ . '/../../.env')) {
    $lines = file(__DIR__ . '/../../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        [$name, $value] = explode('=', $line, 2);
        $_ENV[$name] = trim($value);
    }
}

$spotTzName = trim((string)($_ENV['POSTER_SPOT_TIMEZONE'] ?? ''));
if ($spotTzName === '' || !in_array($spotTzName, timezone_identifiers_list(), true)) {
    $spotTzName = 'Asia/Ho_Chi_Minh';
}
$apiTzName = trim((string)($_ENV['POSTER_API_TIMEZONE'] ?? ''));
if ($apiTzName === '' || !in_array($apiTzName, timezone_identifiers_list(), true)) {
    $apiTzName = $spotTzName;
}
date_default_timezone_set($apiTzName);
$spotTz = new DateTimeZone($spotTzName);

$dbHost = $_ENV['DB_HOST'] ?? 'localhost';
$dbName = $_ENV['DB_NAME'] ?? 'veranda_my';
$dbUser = $_ENV['DB_USER'] ?? 'veranda_my';
$dbPass = $_ENV['DB_PASS'] ?? '';
$token = $_ENV['POSTER_API_TOKEN'] ?? '';
$tableSuffix = (string)($_ENV['DB_TABLE_SUFFIX'] ?? '');

$start = date('Y-m-01');
$end = date('Y-m-d');

try {
    if ($token === '') {
        throw new Exception('POSTER_API_TOKEN is empty');
    }
    $db = new \App\Classes\Database($dbHost, $dbName, $dbUser, $dbPass, $tableSuffix);
    $ks = $db->t('kitchen_stats');
    $meta = $db->t('system_meta');
    $api = new \App\Classes\PosterAPI($token);

    $db->query(
        "UPDATE {$ks}
         SET transaction_closed_at = NULL,
             pay_type = NULL,
             close_reason = NULL
         WHERE transaction_date BETWEEN ? AND ?
           AND (status > 1 OR (transaction_closed_at IS NOT NULL AND transaction_closed_at > '2000-01-01 00:00:00'))",
        [$start, $end]
    );

    $txIds = $db->query(
        "SELECT DISTINCT transaction_id
         FROM {$ks}
         WHERE transaction_date BETWEEN ? AND ?
           AND status > 1
         ORDER BY transaction_id ASC",
        [$start, $end]
    )->fetchAll();

    $parseClosedAt = function (array $tx) use ($spotTz): ?string {
        if (!empty($tx['date_close_date']) && $tx['date_close_date'] !== '0000-00-00 00:00:00') {
            $ts = strtotime((string)$tx['date_close_date']);
            if ($ts !== false && $ts > 0 && (int)date('Y', $ts) >= 2000) {
                return date('Y-m-d H:i:s', $ts);
            }
        }
        if (!empty($tx['date_close'])) {
            $v = (int)$tx['date_close'];
            if ($v > 10000000000) {
                $v = (int)round($v / 1000);
            }
            if ($v > 0) {
                $dt = (new DateTimeImmutable('@' . $v))->setTimezone($spotTz);
                if ((int)$dt->format('Y') >= 2000) {
                    return $dt->format('Y-m-d H:i:s');
                }
            }
        }
        return null;
    };

    $total = count($txIds);
    $updatedTx = 0;
    $errors = 0;
    $i = 0;
    foreach ($txIds as $row) {
        $i++;
        $txId = (int)($row['transaction_id'] ?? 0);
        if ($txId <= 0) continue;
        try {
            $res = $api->request('dash.getTransaction', ['transaction_id' => $txId]);
            $tx = $res[0] ?? $res;
            $status = (int)($tx['status'] ?? 2);
            if ($status <= 1) {
                continue;
            }
            $payType = isset($tx['pay_type']) ? (int)$tx['pay_type'] : null;
            $closeReason = isset($tx['reason']) && $tx['reason'] !== '' ? (int)$tx['reason'] : null;
            $closedAt = $parseClosedAt(is_array($tx) ? $tx : []);

            $db->query(
                "UPDATE {$ks}
                 SET status = ?, pay_type = ?, close_reason = ?, transaction_closed_at = ?
                 WHERE transaction_id = ?
                   AND transaction_date BETWEEN ? AND ?",
                [$status, $payType, $closeReason, $closedAt, $txId, $start, $end]
            );
            $updatedTx++;
        } catch (Exception $e) {
            $errors++;
        }

        if ($i % 50 === 0) {
            echo "[" . date('Y-m-d H:i:s') . "] tx {$i}/{$total}, updated={$updatedTx}, errors={$errors}\n";
        }
        usleep(120000);
    }

    $db->query(
        "INSERT INTO {$meta} (meta_key, meta_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value), updated_at=CURRENT_TIMESTAMP",
        ['poster_close_refresh_month_at', date('Y-m-d H:i:s')]
    );
    $db->query(
        "INSERT INTO {$meta} (meta_key, meta_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value), updated_at=CURRENT_TIMESTAMP",
        ['poster_close_refresh_month_info', "range={$start}..{$end}, tx={$updatedTx}, errors={$errors}"]
    );

    echo "[" . date('Y-m-d H:i:s') . "] Done. range={$start}..{$end}, tx={$updatedTx}, errors={$errors}\n";
} catch (Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

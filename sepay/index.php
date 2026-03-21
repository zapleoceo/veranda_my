<?php
date_default_timezone_set('Asia/Ho_Chi_Minh');

require_once __DIR__ . '/../src/classes/Database.php';

header('Content-Type: application/json; charset=utf-8');

if (file_exists(__DIR__ . '/../.env')) {
    $lines = file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        [$name, $value] = explode('=', $line, 2);
        $_ENV[$name] = trim($value);
    }
}

$dbHost = $_ENV['DB_HOST'] ?? 'localhost';
$dbName = $_ENV['DB_NAME'] ?? 'veranda_my';
$dbUser = $_ENV['DB_USER'] ?? 'veranda_my';
$dbPass = $_ENV['DB_PASS'] ?? '';
$tableSuffix = (string)($_ENV['DB_TABLE_SUFFIX'] ?? '');

try {
    $db = new \App\Classes\Database($dbHost, $dbName, $dbUser, $dbPass, $tableSuffix);
    $db->createPaydayTables();

    $raw = file_get_contents('php://input') ?: '';
    $meta = $db->t('system_meta');
    $nowStr = (new DateTime('now', new DateTimeZone('Asia/Ho_Chi_Minh')))->format('Y-m-d H:i:s');
    $remoteIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $methodNow = (string)($_SERVER['REQUEST_METHOD'] ?? '');
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $ua = mb_substr($ua, 0, 200, 'UTF-8');

    try {
        $db->query("INSERT INTO {$meta} (meta_key, meta_value) VALUES ('sepay_webhook_hits_total', '1')
                    ON DUPLICATE KEY UPDATE meta_value = meta_value + 1");
        $dayKey = 'sepay_webhook_hits_' . date('Ymd');
        $db->query(
            "INSERT INTO {$meta} (meta_key, meta_value) VALUES (?, '1')
             ON DUPLICATE KEY UPDATE meta_value = meta_value + 1",
            [$dayKey]
        );
        $db->query(
            "INSERT INTO {$meta} (meta_key, meta_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
            ['sepay_webhook_last_at', $nowStr]
        );
        $db->query(
            "INSERT INTO {$meta} (meta_key, meta_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
            ['sepay_webhook_last_ip', $remoteIp]
        );
        $db->query(
            "INSERT INTO {$meta} (meta_key, meta_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
            ['sepay_webhook_last_ua', $ua]
        );
        $db->query(
            "INSERT INTO {$meta} (meta_key, meta_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
            ['sepay_webhook_last_bytes', (string)strlen($raw)]
        );
        $db->query(
            "INSERT INTO {$meta} (meta_key, meta_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
            ['sepay_webhook_last_method', $methodNow]
        );
    } catch (\Throwable $e) {
    }

    if ($methodNow !== 'POST') {
        try {
            $db->query("INSERT INTO {$meta} (meta_key, meta_value) VALUES ('sepay_webhook_nonpost_hits_total', '1')
                        ON DUPLICATE KEY UPDATE meta_value = meta_value + 1");
            $db->query(
                "INSERT INTO {$meta} (meta_key, meta_value) VALUES (?, '1')
                 ON DUPLICATE KEY UPDATE meta_value = meta_value + 1",
                ['sepay_webhook_nonpost_hits_' . date('Ymd')]
            );
        } catch (\Throwable $e) {
        }
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        try {
            $db->query(
                "INSERT INTO {$meta} (meta_key, meta_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
                ['sepay_webhook_last_ok', '0']
            );
            $db->query(
                "INSERT INTO {$meta} (meta_key, meta_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
                ['sepay_webhook_last_error', 'Invalid JSON']
            );
        } catch (\Throwable $e) {
        }
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $sepayId = (int)($payload['id'] ?? $payload['sepay_id'] ?? 0);
    if ($sepayId <= 0) {
        try {
            $db->query(
                "INSERT INTO {$meta} (meta_key, meta_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
                ['sepay_webhook_last_ok', '0']
            );
            $db->query(
                "INSERT INTO {$meta} (meta_key, meta_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
                ['sepay_webhook_last_error', 'Missing id']
            );
        } catch (\Throwable $e) {
        }
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing id'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $st = $db->t('sepay_transactions');

    $gateway = trim((string)($payload['gateway'] ?? $payload['bank'] ?? ''));
    if ($gateway === '') $gateway = 'Unknown';

    $accountNumber = trim((string)($payload['account_number'] ?? $payload['accountNumber'] ?? $payload['account'] ?? ''));
    if ($accountNumber === '') $accountNumber = 'Unknown';

    $code = $payload['code'] ?? $payload['transaction_code'] ?? null;
    $code = $code !== null ? trim((string)$code) : null;
    if ($code === '') $code = null;

    $content = trim((string)($payload['content'] ?? $payload['description_short'] ?? ''));
    $referenceCode = trim((string)($payload['reference_code'] ?? $payload['ref'] ?? $payload['referenceCode'] ?? ''));
    $description = trim((string)($payload['description'] ?? $payload['sms'] ?? $payload['message'] ?? $content));

    $transferType = strtolower(trim((string)($payload['transfer_type'] ?? $payload['transferType'] ?? $payload['type'] ?? 'in')));
    if ($transferType !== 'in' && $transferType !== 'out') $transferType = 'in';

    $transferAmount = (int)($payload['transfer_amount'] ?? $payload['amount'] ?? 0);
    $accumulated = (int)($payload['accumulated'] ?? $payload['balance'] ?? 0);

    $subAccount = $payload['sub_account'] ?? $payload['subAccount'] ?? $payload['va'] ?? null;
    $subAccount = $subAccount !== null ? trim((string)$subAccount) : null;
    if ($subAccount === '') $subAccount = null;

    $txDateRaw = $payload['transaction_date'] ?? $payload['transactionDate'] ?? $payload['time'] ?? $payload['date'] ?? null;
    $txDate = null;
    if (is_numeric($txDateRaw)) {
        $n = (int)$txDateRaw;
        if ($n > 2000000000000) {
            $n = (int)round($n / 1000);
        }
        if ($n > 0) {
            $dt = new DateTime('@' . $n);
            $dt->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'));
            $txDate = $dt->format('Y-m-d H:i:s');
        }
    } elseif (is_string($txDateRaw) && trim($txDateRaw) !== '') {
        $ts = strtotime($txDateRaw);
        if ($ts !== false && $ts > 0) {
            $txDate = date('Y-m-d H:i:s', $ts);
        }
    }
    if ($txDate === null) {
        $txDate = (new DateTime('now', new DateTimeZone('Asia/Ho_Chi_Minh')))->format('Y-m-d H:i:s');
    }

    $method = null;
    $hay = strtolower($content . ' ' . (string)$subAccount);
    if (strpos($hay, 'bybit') !== false) {
        $method = 'Bybit';
    } elseif (strpos($hay, 'vietnam company') !== false) {
        $method = 'Vietnam Company';
    } else {
        $method = 'Card';
    }

    $db->query(
        "INSERT INTO {$st}
            (sepay_id, gateway, transaction_date, account_number, code, content, transfer_type, transfer_amount, accumulated, sub_account, reference_code, description, payment_method)
         VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [
            $sepayId,
            $gateway,
            $txDate,
            $accountNumber,
            $code,
            $content,
            $transferType,
            $transferAmount,
            $accumulated,
            $subAccount,
            $referenceCode !== '' ? $referenceCode : '-',
            $description !== '' ? $description : $content,
            $method
        ]
    );

    try {
        $db->query(
            "INSERT INTO {$meta} (meta_key, meta_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
            ['sepay_webhook_last_ok', '1']
        );
        $db->query(
            "INSERT INTO {$meta} (meta_key, meta_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
            ['sepay_webhook_last_error', '']
        );
        $db->query(
            "INSERT INTO {$meta} (meta_key, meta_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
            ['sepay_webhook_last_sepay_id', (string)$sepayId]
        );
    } catch (\Throwable $e) {
    }

    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
} catch (\PDOException $e) {
    $dup = false;
    if (isset($e->errorInfo) && is_array($e->errorInfo)) {
        $dup = ((int)($e->errorInfo[1] ?? 0) === 1062);
    }
    if ($dup) {
        try {
            $db->query(
                "INSERT INTO {$meta} (meta_key, meta_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
                ['sepay_webhook_last_ok', '1']
            );
            $db->query(
                "INSERT INTO {$meta} (meta_key, meta_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
                ['sepay_webhook_last_error', '']
            );
            $db->query(
                "INSERT INTO {$meta} (meta_key, meta_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
                ['sepay_webhook_last_sepay_id', (string)$sepayId]
            );
        } catch (\Throwable $e2) {
        }
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    } else {
        try {
            $db->query(
                "INSERT INTO {$meta} (meta_key, meta_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
                ['sepay_webhook_last_ok', '0']
            );
            $db->query(
                "INSERT INTO {$meta} (meta_key, meta_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
                ['sepay_webhook_last_error', mb_substr($e->getMessage(), 0, 220, 'UTF-8')]
            );
        } catch (\Throwable $e2) {
        }
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
} catch (\Throwable $e) {
    try {
        $db->query(
            "INSERT INTO {$meta} (meta_key, meta_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
            ['sepay_webhook_last_ok', '0']
        );
        $db->query(
            "INSERT INTO {$meta} (meta_key, meta_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
            ['sepay_webhook_last_error', mb_substr($e->getMessage(), 0, 220, 'UTF-8')]
        );
    } catch (\Throwable $e2) {
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

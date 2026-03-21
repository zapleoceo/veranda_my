<?php
date_default_timezone_set('Asia/Ho_Chi_Minh');

require_once __DIR__ . '/../src/classes/Database.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

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
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $sepayId = (int)($payload['id'] ?? $payload['sepay_id'] ?? 0);
    if ($sepayId <= 0) {
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

    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
} catch (\PDOException $e) {
    $dup = false;
    if (isset($e->errorInfo) && is_array($e->errorInfo)) {
        $dup = ((int)($e->errorInfo[1] ?? 0) === 1062);
    }
    if ($dup) {
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

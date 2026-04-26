<?php
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}
$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON'], JSON_UNESCAPED_UNICODE);
    exit;
}
$targetDate = trim((string)($payload['target_date'] ?? ''));
if ($targetDate === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing target_date'], JSON_UNESCAPED_UNICODE);
    exit;
}

$ab = $db->t('payday_actual_balances');
$db->query("INSERT INTO {$ab} (target_date, bal_andrey, bal_vietnam, bal_cash, bal_total) VALUES (?, ?, ?, ?, ?)", [
    $targetDate,
    $payload['bal_andrey'] ?? null,
    $payload['bal_vietnam'] ?? null,
    $payload['bal_cash'] ?? null,
    $payload['bal_total'] ?? null,
]);
echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
exit;

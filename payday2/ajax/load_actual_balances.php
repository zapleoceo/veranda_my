<?php
header('Content-Type: application/json; charset=utf-8');
$date = trim((string)($_GET['date'] ?? ''));
if ($date === '') {
    echo json_encode(['ok' => false, 'error' => 'Missing date']);
    exit;
}

try {
    $dt = new DateTime($date, new DateTimeZone('Asia/Ho_Chi_Minh'));
} catch (\Exception $e) {
    $dt = new DateTime($date);
}
$startStr = $dt->format('Y-m-d 00:00:00');
$dt->modify('+1 day');
$dt->setTime(2, 59, 59);
$endStr = $dt->format('Y-m-d H:i:s');

$ab = $db->t('payday_actual_balances');
$stmt = $db->query("SELECT * FROM {$ab} WHERE created_at >= ? AND created_at <= ? ORDER BY created_at DESC LIMIT 1", [
    $startStr,
    $endStr
]);
$row = $stmt->fetch(\PDO::FETCH_ASSOC);
echo json_encode(['ok' => true, 'data' => $row ?: null], JSON_UNESCAPED_UNICODE);
exit;

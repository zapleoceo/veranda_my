with open('payday/index.php', 'r', encoding='utf-8') as f:
    content = f.read()

old_code = """    $dTo = trim((string)($payload['dateTo'] ?? ''));
    if (!in_array($kind, ['vietnam', 'tips'], true) || ($transferId <= 0 && $txId <= 0) || $dTo === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $startTs = strtotime($dFrom . ' 00:00:00');
    $endTs = strtotime($dTo . ' 23:59:59');"""

new_code = """    $dTo = trim((string)($payload['dateTo'] ?? ''));
    $dFrom = trim((string)($payload['dateFrom'] ?? $dTo));
    if (!in_array($kind, ['vietnam', 'tips'], true) || ($transferId <= 0 && $txId <= 0) || $dTo === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $startTs = strtotime($dFrom . ' 00:00:00');
    $endTs = strtotime($dTo . ' 23:59:59');"""

if old_code in content:
    content = content.replace(old_code, new_code)
    print("Fixed old_code.")
else:
    print("old_code not found.")

with open('payday/index.php', 'w', encoding='utf-8') as f:
    f.write(content)

<?php
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}
try {
    if (!payday2_csrf_valid()) throw new \Exception('CSRF token mismatch');
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    if (!is_array($data)) throw new \Exception('Invalid JSON');

    $type = (int)($data['type'] ?? 0); // 1=income, 2=expense, 3=transfer
    $amount = (float)($data['amount'] ?? 0);
    $dateStr = trim((string)($data['date'] ?? date('Y-m-d')));
    $accountFrom = (int)($data['account_from'] ?? 0);
    $accountTo = (int)($data['account_to'] ?? 0);
    $categoryId = (int)($data['category_id'] ?? 0);

    $api = new \App\Classes\PosterAPI((string)$token);
    $txs = $api->request('finance.getTransactions', [
        'dateFrom' => date('Ymd', strtotime($dateStr)),
        'dateTo' => date('Ymd', strtotime($dateStr)),
        'timezone' => 'client'
    ]);

    $found = null;
    if (is_array($txs)) {
        // Reverse so we check the most recent first
        $txs = array_reverse($txs);
        foreach ($txs as $t) {
            $tType = (int)($t['type'] ?? -1); // 1=income, 0=expense, 2=transfer
            $expectedType = $type === 1 ? 1 : ($type === 2 ? 0 : 2);
            if ($tType !== $expectedType) continue;

            $tAmountFrom = abs((float)($t['amount_from'] ?? 0));
            $tAmountTo = abs((float)($t['amount_to'] ?? 0));
            $tSum = abs((float)($t['sum'] ?? 0));
            
            $matchAmount = false;
            if ($expectedType === 1 && ($tAmountTo == $amount || $tSum == $amount)) $matchAmount = true;
            elseif ($expectedType === 0 && ($tAmountFrom == $amount || $tSum == $amount)) $matchAmount = true;
            elseif ($expectedType === 2 && ($tAmountFrom == $amount || $tAmountTo == $amount || $tSum == $amount)) $matchAmount = true;
            
            if (!$matchAmount) continue;

            $tAccFrom = (int)($t['account_from'] ?? $t['account_from_id'] ?? 0);
            $tAccTo = (int)($t['account_id'] ?? $t['account_to_id'] ?? 0);

            if ($expectedType === 1 && $tAccTo !== $accountTo) continue;
            if ($expectedType === 0 && $tAccFrom !== $accountFrom) continue;
            if ($expectedType === 2 && ($tAccFrom !== $accountFrom || $tAccTo !== $accountTo)) continue;

            $tCat = (int)($t['category_id'] ?? 0);
            if ($categoryId > 0 && $tCat !== $categoryId) continue;

            $found = $t;
            break;
        }
    }

    echo json_encode(['ok' => true, 'found' => $found], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
exit;

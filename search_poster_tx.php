<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/classes/PosterAPI.php';

$api = new \App\Classes\PosterAPI((string)$token);

try {
    $txs = $api->request('finance.getTransactions', [
        'dateFrom' => date('Ymd', strtotime('-1 day')),
        'dateTo' => date('Ymd', strtotime('+1 day')),
        'timezone' => 'client'
    ]);
    
    if (!is_array($txs)) {
        echo "No transactions array returned\n";
        exit;
    }
    
    $found = false;
    foreach ($txs as $t) {
        $amountFrom = (float)($t['amount_from'] ?? 0);
        $amountTo = (float)($t['amount_to'] ?? 0);
        $sum = (float)($t['sum'] ?? 0);
        
        if (abs($amountFrom) == 1 || abs($amountTo) == 1 || abs($sum) == 1 || abs($amountFrom) == 100 || abs($sum) == 100 || abs($sum) == 10000 || abs($amountFrom) == 10000) {
            echo "ID: " . ($t['transaction_id'] ?? 'null') . "\n";
            echo "Type: " . ($t['type'] ?? 'null') . "\n";
            echo "Amount_from: " . ($t['amount_from'] ?? 'null') . "\n";
            echo "Amount_to: " . ($t['amount_to'] ?? 'null') . "\n";
            echo "Sum: " . ($t['sum'] ?? 'null') . "\n";
            echo "Account_from: " . ($t['account_from'] ?? $t['account_from_id'] ?? 'null') . "\n";
            echo "Account_to: " . ($t['account_id'] ?? $t['account_to_id'] ?? 'null') . "\n";
            echo "Category: " . ($t['category_id'] ?? 'null') . "\n";
            echo "Comment: " . ($t['comment'] ?? 'null') . "\n";
            echo "Date: " . ($t['date'] ?? 'null') . "\n";
            echo "------------------------\n";
            $found = true;
        }
    }
    if (!$found) {
        echo "No matching transaction found.\n";
    }
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

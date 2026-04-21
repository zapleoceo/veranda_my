<?php
require_once __DIR__ . '/auth_check.php';

$api = new \App\Classes\PosterAPI($token);
$shifts = $api->request('finance.getCashShifts', ['dateFrom' => date('Ymd', strtotime('-15 days')), 'dateTo' => date('Ymd')]);

$found_txs = [];
foreach ($shifts as $shift) {
    if (!empty($shift['shift_id'])) {
        $txs = $api->request('finance.getCashShiftTransactions', ['shift_id' => $shift['shift_id']]);
        if (is_array($txs)) {
            foreach ($txs as $tx) {
                if (!empty($tx['category_id']) || !empty($tx['reason_id']) || !empty($tx['type_id'])) {
                    $found_txs[] = $tx;
                } else if ($tx['type'] == 2 || $tx['type'] == 3) {
                    // income or expense, usually has a category
                    $found_txs[] = $tx;
                }
            }
        }
    }
    if (count($found_txs) > 5) break;
}
echo json_encode($found_txs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

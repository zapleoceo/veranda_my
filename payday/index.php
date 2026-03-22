<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../src/classes/PosterAPI.php';

veranda_require('payday');
date_default_timezone_set('Asia/Ho_Chi_Minh');

$db->createPaydayTables();

$message = '';
$error = '';

$dateFrom = trim((string)($_GET['dateFrom'] ?? ($_POST['dateFrom'] ?? '')));
$dateTo = trim((string)($_GET['dateTo'] ?? ($_POST['dateTo'] ?? '')));
$dateSingle = trim((string)($_GET['date'] ?? ($_POST['date'] ?? '')));

if ($dateFrom === '' && $dateTo === '' && $dateSingle !== '') {
    $dateFrom = $dateSingle;
    $dateTo = $dateSingle;
}
if ($dateFrom === '' && $dateTo !== '') $dateFrom = $dateTo;
if ($dateTo === '' && $dateFrom !== '') $dateTo = $dateFrom;

if ($dateFrom === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = date('Y-m-d');
if ($dateTo === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) $dateTo = $dateFrom;

if ($dateFrom > $dateTo) {
    $tmp = $dateFrom;
    $dateFrom = $dateTo;
    $dateTo = $tmp;
}

$date = $dateTo;
$periodFrom = $dateFrom . ' 00:00:00';
$periodTo = $dateTo . ' 23:59:59';

$moneyToInt = function ($v): int {
    if (is_int($v)) return $v;
    if (is_float($v)) return (int)round($v);
    if (is_string($v)) {
        $t = trim($v);
        if ($t === '') return 0;
        $t = str_replace(',', '.', $t);
        if (is_numeric($t)) return (int)round((float)$t);
        return 0;
    }
    if (is_numeric($v)) return (int)round((float)$v);
    return 0;
};

$posterCentsToVnd = function (int $cents): int {
    if ($cents === 0) return 0;
    if ($cents % 100 === 0) return (int)($cents / 100);
    return (int)round($cents / 100);
};

$parsePosterDateTime = function ($tx): ?string {
    $ts = null;
    if (is_array($tx)) {
        if (!empty($tx['date_close']) && is_numeric($tx['date_close'])) {
            $n = (int)$tx['date_close'];
            if ($n > 20000000000) $n = (int)round($n / 1000);
            if ($n > 0) $ts = $n;
        }
        if ($ts === null && !empty($tx['date_close']) && is_string($tx['date_close'])) {
            $t = strtotime($tx['date_close']);
            if ($t !== false && $t > 0) $ts = $t;
        }
        if ($ts === null && !empty($tx['date_close_date']) && is_string($tx['date_close_date'])) {
            $t = strtotime($tx['date_close_date']);
            if ($t !== false && $t > 0) $ts = $t;
        }
        if ($ts === null && !empty($tx['dateClose']) && is_string($tx['dateClose'])) {
            $t = strtotime($tx['dateClose']);
            if ($t !== false && $t > 0) $ts = $t;
        }
    }
    if ($ts === null) return null;
    if ((int)date('Y', $ts) < 2000) return null;
    return date('Y-m-d H:i:s', $ts);
};

$sepayApiToken = trim((string)($_ENV['SEPAY_API_TOKEN'] ?? $_ENV['SEPAY_USER_API_TOKEN'] ?? ''));
$sepayAccountNumber = trim((string)($_ENV['SEPAY_ACCOUNT_NUMBER'] ?? ''));

$sepayFetchTransactions = function (string $dateFrom, string $dateTo, string $token, string $accountNumber): array {
    $from = $dateFrom . ' 00:00:00';
    $to = $dateTo . ' 23:59:59';
    $params = [
        'transaction_date_min' => $from,
        'transaction_date_max' => $to,
        'limit' => 5000,
    ];
    if ($accountNumber !== '') {
        $params['account_number'] = $accountNumber;
    }
    $qs = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    $url = 'https://my.sepay.vn/userapi/transactions/list?' . $qs;

    $ch = curl_init($url);
    if ($ch === false) {
        throw new \Exception('SePay API: curl_init failed');
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
    ]);

    $body = curl_exec($ch);
    $err = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        throw new \Exception('SePay API: ' . ($err !== '' ? $err : 'request failed'));
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        throw new \Exception('SePay API: invalid JSON (http=' . $http . ')');
    }
    if ($http < 200 || $http > 299) {
        $e = $decoded['error'] ?? null;
        $msg = is_string($e) ? $e : ('http=' . $http);
        throw new \Exception('SePay API: ' . $msg);
    }

    $txs = $decoded['transactions'] ?? null;
    if (!is_array($txs)) $txs = [];
    return $txs;
};

$getEmployeesById = function (\App\Classes\PosterAPI $api): array {
    $out = [];
    try {
        $employees = $api->request('access.getEmployees');
        if (!is_array($employees)) return [];
        foreach ($employees as $e) {
            $id = (int)($e['user_id'] ?? 0);
            $name = trim((string)($e['name'] ?? ''));
            if ($id > 0 && $name !== '') $out[$id] = $name;
        }
    } catch (\Throwable $e) {
        return [];
    }
    return $out;
};

$st = $db->t('sepay_transactions');
$pc = $db->t('poster_checks');
$ppm = $db->t('poster_payment_methods');
$pt = $db->t('poster_transactions');
$pl = $db->t('check_payment_links');

$action = (string)($_POST['action'] ?? '');

try {
    $api = new \App\Classes\PosterAPI((string)$token);
    $normalizePosterTx = function ($v): ?array {
        if (!is_array($v)) return null;
        if (isset($v[0]) && is_array($v[0])) return $v[0];
        return $v;
    };

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'load_poster_checks') {
        $ymdFrom = str_replace('-', '', $dateFrom);
        $ymdTo = str_replace('-', '', $dateTo);
        $employeesById = null;
        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        $minCloseAt = null;
        $maxCloseAt = null;
        $datesSeen = [];

        $methods = [];
        try {
            $m1 = $api->request('settings.getPaymentMethods', ['money_type' => 2, 'payment_type' => 2]);
            if (is_array($m1)) $methods = array_merge($methods, $m1);
        } catch (\Throwable $e) {
        }
        try {
            $m2 = $api->request('settings.getPaymentMethods', ['money_type' => 2, 'payment_type' => 7]);
            if (is_array($m2)) $methods = array_merge($methods, $m2);
        } catch (\Throwable $e) {
        }

        $methodTitleById = [];
        try {
            $db->query("DELETE FROM {$ppm}");
        } catch (\Throwable $e) {
        }
        foreach ($methods as $m) {
            if (!is_array($m)) continue;
            $id = (int)($m['payment_method_id'] ?? $m['paymentMethodId'] ?? 0);
            $title = trim((string)($m['title'] ?? ''));
            if ($id <= 0 || $title === '') continue;
            $methodTitleById[$id] = $title;
            try {
                $db->query(
                    "INSERT INTO {$ppm} (payment_method_id, title, color, money_type, payment_type, is_active)
                     VALUES (?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        title = VALUES(title),
                        color = VALUES(color),
                        money_type = VALUES(money_type),
                        payment_type = VALUES(payment_type),
                        is_active = VALUES(is_active)",
                    [
                        $id,
                        $title,
                        ($m['color'] ?? null) !== null ? (string)$m['color'] : null,
                        (int)($m['money_type'] ?? $m['moneyType'] ?? 0),
                        (int)($m['payment_type'] ?? $m['paymentType'] ?? 0),
                        (int)($m['is_active'] ?? $m['isActive'] ?? 1),
                    ]
                );
            } catch (\Throwable $e) {
            }
        }

        $txs = [];
        try {
            $txs = $api->request('dash.getTransactions', [
                'dateFrom' => $ymdFrom,
                'dateTo' => $ymdTo,
                'status' => 2,
                'include_products' => 0,
                'include_history' => 0
            ]);
        } catch (\Throwable $e) {
            $txs = [];
        }
        if (!is_array($txs)) $txs = [];

        try { $db->query("DELETE FROM {$pt} WHERE day_date BETWEEN ? AND ?", [$dateFrom, $dateTo]); } catch (\Throwable $e) {}

        foreach ($txs as $tx) {
            if (!is_array($tx)) continue;
            $txId = (int)($tx['transaction_id'] ?? $tx['id'] ?? 0);
            if ($txId <= 0) continue;

            $payType = isset($tx['pay_type']) ? (int)$tx['pay_type'] : (int)($tx['payType'] ?? 0);
            if ($payType !== 2 && $payType !== 3) {
                $skipped++;
                continue;
            }

            $closeAt = $parsePosterDateTime($tx);
            if ($closeAt === null) {
                $skipped++;
                continue;
            }

            $dayDate = substr($closeAt, 0, 10);
            $datesSeen[$dayDate] = ($datesSeen[$dayDate] ?? 0) + 1;
            if ($minCloseAt === null || $closeAt < $minCloseAt) $minCloseAt = $closeAt;
            if ($maxCloseAt === null || $closeAt > $maxCloseAt) $maxCloseAt = $closeAt;

            $employeeId = (int)($tx['employee_id'] ?? $tx['user_id'] ?? $tx['waiter_id'] ?? 0);
            $waiterName = trim((string)($tx['waiter_name'] ?? $tx['waiterName'] ?? $tx['name'] ?? ''));
            if ($waiterName === '' && $employeeId > 0) {
                if ($employeesById === null) {
                    $employeesById = $getEmployeesById($api);
                }
                $waiterName = (string)($employeesById[$employeeId] ?? '');
            }

            $sum = $moneyToInt($tx['sum'] ?? 0);
            $payedSum = $moneyToInt($tx['payed_sum'] ?? $tx['payedSum'] ?? 0);
            $payedCash = $moneyToInt($tx['payed_cash'] ?? $tx['payedCash'] ?? 0);
            $payedCard = $moneyToInt($tx['payed_card'] ?? $tx['payedCard'] ?? 0);
            $payedCert = $moneyToInt($tx['payed_cert'] ?? $tx['payedCert'] ?? 0);
            $payedBonus = $moneyToInt($tx['payed_bonus'] ?? $tx['payedBonus'] ?? 0);
            $payedThirdParty = $moneyToInt($tx['payed_third_party'] ?? $tx['payedThirdParty'] ?? 0);
            $reason = isset($tx['reason']) ? (int)$tx['reason'] : null;
            $serviceTip = $moneyToInt($tx['tip_sum'] ?? $tx['tipSum'] ?? 0);
            $tipsCard = $moneyToInt($tx['tips_card'] ?? $tx['tipsCard'] ?? 0);
            $tipsCash = $moneyToInt($tx['tips_cash'] ?? $tx['tipsCash'] ?? 0);
            $tipSum = $serviceTip + $tipsCard + $tipsCash;

            if (($payedCard + $payedThirdParty + $tipSum) <= 0) {
                $skipped++;
                continue;
            }

            $discount = (float)($tx['discount'] ?? 0);
            $tableId = isset($tx['table_id']) ? (int)$tx['table_id'] : (isset($tx['tableId']) ? (int)$tx['tableId'] : null);
            $spotId = isset($tx['spot_id']) ? (int)$tx['spot_id'] : (isset($tx['spotId']) ? (int)$tx['spotId'] : null);
            $receiptNumber = (int)($tx['receipt_number'] ?? $tx['receiptNumber'] ?? $tx['receipt'] ?? $tx['check_number'] ?? $tx['checkNumber'] ?? 0);
            if ($receiptNumber <= 0) $receiptNumber = $txId;

            try {
                $db->query(
                    "INSERT INTO {$pt}
                        (transaction_id, day_date, date_close, pay_type, sum, payed_card, payed_third_party, tip_sum, spot_id, table_id, waiter_name)
                     VALUES
                        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        day_date = VALUES(day_date),
                        date_close = VALUES(date_close),
                        pay_type = VALUES(pay_type),
                        sum = VALUES(sum),
                        payed_card = VALUES(payed_card),
                        payed_third_party = VALUES(payed_third_party),
                        tip_sum = VALUES(tip_sum),
                        spot_id = VALUES(spot_id),
                        table_id = VALUES(table_id),
                        waiter_name = VALUES(waiter_name)",
                    [
                        $txId, $dayDate, $closeAt, $payType, $sum, $payedCard, $payedThirdParty, $tipSum, $spotId, $tableId,
                        $waiterName !== '' ? $waiterName : null,
                    ]
                );
            } catch (\Throwable $e) {
            }

            $detail = null;
            try {
                $detail = $normalizePosterTx($api->request('dash.getTransaction', [
                    'transaction_id' => $txId,
                    'include_history' => 0,
                    'include_products' => 0,
                    'include_delivery' => 0,
                ]));
            } catch (\Throwable $e) {
                $detail = null;
            }

            $pmId = 0;
            if (is_array($detail)) {
                $pmId = (int)($detail['payment_method_id'] ?? $detail['paymentMethodId'] ?? 0);
            }
            if ($pmId <= 0) {
                $pmId = (int)($tx['payment_method_id'] ?? $tx['paymentMethodId'] ?? 0);
            }

            if ($pmId > 0) {
                try {
                    $db->query("UPDATE {$pt} SET payment_method_id = ? WHERE transaction_id = ? LIMIT 1", [$pmId, $txId]);
                } catch (\Throwable $e) {
                }
            }
            $exists = (int)$db->query("SELECT 1 FROM {$pc} WHERE transaction_id = ? LIMIT 1", [$txId])->fetchColumn();
            if ($exists === 1) {
                $db->query(
                    "UPDATE {$pc}
                     SET receipt_number = ?, table_id = ?, spot_id = ?, sum = ?, payed_sum = ?, payed_cash = ?, payed_card = ?, payed_cert = ?, payed_bonus = ?, payed_third_party = ?,
                         pay_type = ?, reason = ?, tip_sum = ?, discount = ?, date_close = ?, poster_payment_method_id = ?, waiter_name = ?, day_date = ?
                     WHERE transaction_id = ?
                     LIMIT 1",
                    [
                        $receiptNumber > 0 ? $receiptNumber : null,
                        $tableId, $spotId, $sum, $payedSum, $payedCash, $payedCard, $payedCert, $payedBonus, $payedThirdParty,
                        $payType, $reason, $tipSum, $discount, $closeAt,
                        $pmId > 0 ? $pmId : null,
                        $waiterName !== '' ? $waiterName : null, $dayDate,
                        $txId
                    ]
                );
                $updated++;
            } else {
                $db->query(
                    "INSERT INTO {$pc}
                        (transaction_id, receipt_number, table_id, spot_id, sum, payed_sum, payed_cash, payed_card, payed_cert, payed_bonus, payed_third_party, pay_type, reason, tip_sum, discount, date_close, poster_payment_method_id, waiter_name, day_date)
                     VALUES
                        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [
                        $txId,
                        $receiptNumber > 0 ? $receiptNumber : null,
                        $tableId, $spotId, $sum, $payedSum, $payedCash, $payedCard, $payedCert, $payedBonus, $payedThirdParty,
                        $payType, $reason, $tipSum, $discount, $closeAt,
                        $pmId > 0 ? $pmId : null,
                        $waiterName !== '' ? $waiterName : null, $dayDate
                    ]
                );
                $inserted++;
            }
        }

        $message = 'Poster синк: ' . json_encode([
            'inserted' => $inserted,
            'updated' => $updated,
            'skipped' => $skipped,
            'min_close_at' => $minCloseAt,
            'max_close_at' => $maxCloseAt,
            'payment_methods' => count($methodTitleById),
        ], JSON_UNESCAPED_UNICODE);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'clear_day') {
        try {
            $db->query('START TRANSACTION');
            $db->query(
                "DELETE l FROM {$pl} l
                 JOIN {$pc} p ON p.transaction_id = l.poster_transaction_id
                 WHERE p.day_date BETWEEN ? AND ?",
                [$dateFrom, $dateTo]
            );
            $db->query("DELETE FROM {$pc} WHERE day_date BETWEEN ? AND ?", [$dateFrom, $dateTo]);
            $db->query("DELETE FROM {$pt} WHERE day_date BETWEEN ? AND ?", [$dateFrom, $dateTo]);
            $db->query("DELETE FROM {$st} WHERE transaction_date BETWEEN ? AND ?", [$periodFrom, $periodTo]);
            $db->query('COMMIT');
            $message = ($dateFrom === $dateTo ? ('День очищен: ' . $dateFrom) : ('Период очищен: ' . $dateFrom . ' — ' . $dateTo));
        } catch (\Throwable $e) {
            try { $db->query('ROLLBACK'); } catch (\Throwable $e2) {}
            throw $e;
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'reload_sepay_api') {
        if ($sepayApiToken === '') {
            throw new \Exception('Не задан SEPAY_API_TOKEN в .env');
        }

        $txs = $sepayFetchTransactions($dateFrom, $dateTo, $sepayApiToken, $sepayAccountNumber);
        $db->query("DELETE FROM {$st} WHERE transaction_date BETWEEN ? AND ?", [$periodFrom, $periodTo]);

        $inserted = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($txs as $tx) {
            if (!is_array($tx)) continue;
            $sepayId = (int)($tx['id'] ?? 0);
            if ($sepayId <= 0) {
                $skipped++;
                continue;
            }

            $gateway = trim((string)($tx['bank_brand_name'] ?? $tx['gateway'] ?? ''));
            if ($gateway === '') $gateway = 'Unknown';

            $accountNumber = trim((string)($tx['account_number'] ?? $tx['accountNumber'] ?? ''));
            if ($accountNumber === '') $accountNumber = 'Unknown';

            $transactionDate = trim((string)($tx['transaction_date'] ?? $tx['transactionDate'] ?? ''));
            $ts = strtotime($transactionDate);
            if ($ts === false || $ts <= 0) {
                $skipped++;
                continue;
            }
            $transactionDate = date('Y-m-d H:i:s', $ts);

            $code = $tx['code'] ?? null;
            $code = $code !== null ? trim((string)$code) : null;
            if ($code === '') $code = null;

            $content = trim((string)($tx['transaction_content'] ?? $tx['content'] ?? ''));
            $reference = trim((string)($tx['reference_number'] ?? $tx['referenceCode'] ?? $tx['reference_code'] ?? ''));

            $sub = $tx['sub_account'] ?? $tx['subAccount'] ?? null;
            $sub = $sub !== null ? trim((string)$sub) : null;
            if ($sub === '') $sub = null;

            $accum = $moneyToInt($tx['accumulated'] ?? 0);

            $amountIn = (float)($tx['amount_in'] ?? 0);
            $amountOut = (float)($tx['amount_out'] ?? 0);
            $transferType = 'in';
            $transferAmount = 0;
            if ($amountOut > 0.0001 && $amountIn <= 0.0001) {
                $transferType = 'out';
                $transferAmount = (int)round($amountOut);
            } else {
                $transferType = 'in';
                $transferAmount = (int)round($amountIn);
            }
            if ($transferAmount <= 0 && isset($tx['transferAmount'])) {
                $transferAmount = $moneyToInt($tx['transferAmount']);
                $transferType = strtolower(trim((string)($tx['transferType'] ?? 'in')));
                if ($transferType !== 'in' && $transferType !== 'out') $transferType = 'in';
            }

            $method = null;
            $hay = strtolower($content . ' ' . (string)$sub);
            if (strpos($hay, 'bybit') !== false) {
                $method = 'Bybit';
            } elseif (strpos($hay, 'vietnam company') !== false) {
                $method = 'Vietnam Company';
            } else {
                $method = 'Card';
            }

            $rawTx = json_encode($tx, JSON_UNESCAPED_UNICODE);
            if (!is_string($rawTx)) $rawTx = null;

            $affected = (int)$db->query(
                "INSERT INTO {$st}
                    (sepay_id, gateway, transaction_date, account_number, code, content, transfer_type, transfer_amount, accumulated, sub_account, reference_code, description, payment_method, raw_request_body)
                 VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    gateway = VALUES(gateway),
                    transaction_date = VALUES(transaction_date),
                    account_number = VALUES(account_number),
                    code = VALUES(code),
                    content = VALUES(content),
                    transfer_type = VALUES(transfer_type),
                    transfer_amount = VALUES(transfer_amount),
                    accumulated = VALUES(accumulated),
                    sub_account = VALUES(sub_account),
                    reference_code = VALUES(reference_code),
                    description = VALUES(description),
                    payment_method = VALUES(payment_method),
                    raw_request_body = VALUES(raw_request_body)",
                [
                    $sepayId,
                    $gateway,
                    $transactionDate,
                    $accountNumber,
                    $code,
                    $content !== '' ? $content : '-',
                    $transferType,
                    $transferAmount,
                    $accum,
                    $sub,
                    $reference !== '' ? $reference : '-',
                    $content !== '' ? $content : '-',
                    $method,
                    $rawTx
                ]
            )->rowCount();

            if ($affected === 1) $inserted++;
            elseif ($affected >= 2) $updated++;
        }

        $label = $dateFrom === $dateTo ? $dateFrom : ($dateFrom . ' — ' . $dateTo);
        $message = 'SePay загружен по API за ' . $label . ': ' . json_encode(['inserted' => $inserted, 'updated' => $updated, 'skipped' => $skipped, 'api_rows' => count($txs)], JSON_UNESCAPED_UNICODE);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create_transfer') {
        $kind = (string)($_POST['kind'] ?? '');
        if (!in_array($kind, ['vietnam', 'tips'], true)) {
            throw new \Exception('Bad request');
        }
        $amount = 0;
        if ($kind === 'vietnam') {
            $amount = (int)$db->query(
                "SELECT COALESCE(SUM(payed_card + payed_third_party), 0)
                 FROM {$pc}
                 WHERE day_date BETWEEN ? AND ?
                   AND pay_type IN (2,3)
                   AND (payed_card + payed_third_party) > 0
                   AND poster_payment_method_id = 11",
                [$dateFrom, $dateTo]
            )->fetchColumn();
        } else {
            $amount = (int)$db->query(
                "SELECT COALESCE(SUM(tip_sum), 0)
                 FROM {$pc}
                 WHERE day_date BETWEEN ? AND ?
                   AND pay_type IN (2,3)
                   AND (payed_card + payed_third_party) > 0
                   AND tip_sum > 0",
                [$dateFrom, $dateTo]
            )->fetchColumn();
        }
        if ($amount <= 0) {
            throw new \Exception('Сумма для перевода = 0.');
        }

        $targetDate = $dateTo . ' 23:55:00';
        $startTs = strtotime($dateTo . ' 00:00:00');
        $endTs = strtotime($dateTo . ' 23:59:59');

        $accountTo = $kind === 'vietnam' ? 9 : 8;
        $comment = $kind === 'vietnam'
            ? 'Перевод чеков вьетнаской компании'
            : 'Перевод типсов';

        $txs = $api->request('finance.getTransactions', [
            'dateFrom' => str_replace('-', '', $dateTo),
            'dateTo' => str_replace('-', '', $dateTo),
        ]);
        if (!is_array($txs)) $txs = [];

        $dup = false;
        foreach ($txs as $row) {
            if (!is_array($row)) continue;
            $type = (int)($row['type'] ?? 0);
            if ($type !== 2) continue;
            $sum = (int)($row['amount_from'] ?? $row['amountFrom'] ?? $row['sum'] ?? $row['amount'] ?? 0);
            if ($sum !== $amount) continue;
            $toId = (int)($row['account_to_id'] ?? $row['accountTo'] ?? 0);
            if ($toId !== $accountTo) continue;
            $cmt = strtolower((string)($row['comment'] ?? $row['description'] ?? ''));
            if ($cmt !== '' && strpos($cmt, strtolower($comment)) === false) continue;
            $dRaw = $row['date'] ?? $row['created_at'] ?? $row['time'] ?? null;
            $ts = null;
            if (is_numeric($dRaw)) {
                $n = (int)$dRaw;
                if ($n > 2000000000000) $n = (int)round($n / 1000);
                if ($n > 0) $ts = $n;
            } elseif (is_string($dRaw) && trim($dRaw) !== '') {
                $t = strtotime($dRaw);
                if ($t !== false && $t > 0) $ts = $t;
            }
            if ($ts !== null && $startTs !== false && $endTs !== false && $ts >= $startTs && $ts <= $endTs) {
                $dup = true;
                break;
            }
        }
        if ($dup) {
            throw new \Exception('Перевод за этот день уже создан.');
        }

        $api->request('finance.createTransactions', [
            'type' => 2,
            'user_id' => 4,
            'account_from' => 1,
            'account_to' => $accountTo,
            'amount_from' => $amount,
            'amount_to' => $amount,
            'date' => $targetDate,
            'comment' => $comment,
            'account_id' => 1,
            'account_to_id' => $accountTo,
            'sum' => $amount,
        ], 'POST');

        $message = 'Перевод создан.';
    }
} catch (\Throwable $e) {
    if ($error === '') $error = $e->getMessage();
}

if (($_GET['ajax'] ?? '') === 'manual_link') {
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $payload = json_decode(file_get_contents('php://input') ?: '[]', true);
    if (!is_array($payload)) $payload = [];
    $posterIds = $payload['poster_transaction_ids'] ?? $payload['poster_ids'] ?? null;
    $sepayIds = $payload['sepay_ids'] ?? null;
    $posterId = (int)($payload['poster_transaction_id'] ?? 0);
    $sepayId = (int)($payload['sepay_id'] ?? 0);

    if (is_array($posterIds)) {
        $posterIds = array_values(array_unique(array_filter(array_map(fn($v) => (int)$v, $posterIds), fn($v) => $v > 0)));
    } elseif ($posterId > 0) {
        $posterIds = [$posterId];
    } else {
        $posterIds = [];
    }

    if (is_array($sepayIds)) {
        $sepayIds = array_values(array_unique(array_filter(array_map(fn($v) => (int)$v, $sepayIds), fn($v) => $v > 0)));
    } elseif ($sepayId > 0) {
        $sepayIds = [$sepayId];
    } else {
        $sepayIds = [];
    }

    if (count($sepayIds) === 0 || count($posterIds) === 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (count($sepayIds) > 1 && count($posterIds) > 1) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Нельзя: выбери 1 платеж и много чеков или 1 чек и много платежей.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        if (count($sepayIds) === 1) {
            $sid = (int)$sepayIds[0];
            $placeholders = implode(',', array_fill(0, count($posterIds), '?'));
            $params = array_merge(array_map(fn($v) => (int)$v, $posterIds), [$sid]);
            $other = (int)$db->query(
                "SELECT 1
                 FROM {$pl}
                 WHERE poster_transaction_id IN ({$placeholders})
                   AND sepay_id <> ?
                 LIMIT 1",
                $params
            )->fetchColumn();
            if ($other === 1) {
                throw new \Exception('Чек уже привязан к другому платежу (получится много-ко-много).');
            }
        } elseif (count($posterIds) === 1) {
            $pid = (int)$posterIds[0];
            $placeholders = implode(',', array_fill(0, count($sepayIds), '?'));
            $params = array_merge(array_map(fn($v) => (int)$v, $sepayIds), [$pid]);
            $other = (int)$db->query(
                "SELECT 1
                 FROM {$pl}
                 WHERE sepay_id IN ({$placeholders})
                   AND poster_transaction_id <> ?
                 LIMIT 1",
                $params
            )->fetchColumn();
            if ($other === 1) {
                throw new \Exception('Платеж уже привязан к другому чеку (получится много-ко-много).');
            }
        }

        $inserted = 0;
        foreach ($posterIds as $pid) {
            foreach ($sepayIds as $sid) {
                $affected = $db->query(
                    "INSERT INTO {$pl} (poster_transaction_id, sepay_id, link_type, is_manual)
                     VALUES (?, ?, 'manual', 1)
                     ON DUPLICATE KEY UPDATE link_type = 'manual', is_manual = 1",
                    [(int)$pid, (int)$sid]
                )->rowCount();
                if ($affected > 0) $inserted++;
            }
        }
        echo json_encode(['ok' => true, 'created' => true, 'pairs' => $inserted], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (($_GET['ajax'] ?? '') === 'clear_links') {
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $db->query(
            "DELETE l FROM {$pl} l
             JOIN {$pc} p ON p.transaction_id = l.poster_transaction_id
             WHERE p.day_date = ?",
            [$date]
        );
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (($_GET['ajax'] ?? '') === 'auto_link') {
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $db->query(
            "DELETE l FROM {$pl} l
             JOIN {$pc} p ON p.transaction_id = l.poster_transaction_id
             WHERE p.day_date BETWEEN ? AND ?",
            [$dateFrom, $dateTo]
        );

        $checks = $db->query(
            "SELECT transaction_id, date_close, payed_card, payed_third_party, tip_sum, poster_payment_method_id
             FROM {$pc}
             WHERE day_date BETWEEN ? AND ?
               AND pay_type IN (2,3)
               AND (payed_card + payed_third_party) > 0
             ORDER BY date_close ASC",
            [$dateFrom, $dateTo]
        )->fetchAll();

        $sepay = $db->query(
            "SELECT sepay_id, transaction_date, transfer_amount
             FROM {$st}
             WHERE transaction_date BETWEEN ? AND ?
               AND transfer_type = 'in'
               AND (payment_method IS NULL OR payment_method IN ('Card','Bybit'))
             ORDER BY transaction_date ASC",
            [$periodFrom, $periodTo]
        )->fetchAll();

        $linkedSepay = [];
        $linkedPoster = [];

        $sepayByAmount = [];
        foreach ($sepay as $s) {
            $sid = (int)($s['sepay_id'] ?? 0);
            if ($sid <= 0) continue;
            if (!empty($linkedSepay[$sid])) continue;
            $amt = (int)($s['transfer_amount'] ?? 0);
            $sepayByAmount[$amt][] = $s;
        }

        foreach ($checks as $c) {
            $pid = (int)($c['transaction_id'] ?? 0);
            if ($pid <= 0) continue;
            if (!empty($linkedPoster[$pid])) continue;
            $pmId = (int)($c['poster_payment_method_id'] ?? 0);
            if ($pmId === 11) continue;

            $payedCardVnd = $posterCentsToVnd((int)(($c['payed_card'] ?? 0) + ($c['payed_third_party'] ?? 0)));
            $tipVnd = $posterCentsToVnd((int)($c['tip_sum'] ?? 0));
            $totalVnd = $payedCardVnd + $tipVnd;
            if ($totalVnd <= 0) continue;
            $amounts = [$totalVnd];
            $closeTs = strtotime((string)$c['date_close']);
            if ($closeTs === false || $closeTs <= 0) continue;

            $best = null;
            $bestDiff = null;
            foreach ($amounts as $amt) {
                foreach (($sepayByAmount[$amt] ?? []) as $s) {
                    $sid = (int)$s['sepay_id'];
                    if (!empty($linkedSepay[$sid])) continue;
                    $stTs = strtotime((string)$s['transaction_date']);
                    if ($stTs === false || $stTs <= 0) continue;
                    $diff = abs($stTs - $closeTs);
                    if ($diff > 600) continue;
                    if ($best === null || $diff < $bestDiff) {
                        $best = $s;
                        $bestDiff = $diff;
                    }
                }
            }
            if ($best !== null) {
                $sid = (int)$best['sepay_id'];
                $db->query(
                    "INSERT IGNORE INTO {$pl} (poster_transaction_id, sepay_id, link_type, is_manual)
                     VALUES (?, ?, 'auto_green', 0)",
                    [$pid, $sid]
                );
                $linkedPoster[$pid] = true;
                $linkedSepay[$sid] = true;
            }
        }

        $linkedGreenPoster = [];
        $rowsGreen = $db->query(
            "SELECT l.poster_transaction_id, l.sepay_id
             FROM {$pl} l
             JOIN {$pc} p ON p.transaction_id = l.poster_transaction_id
             WHERE p.day_date BETWEEN ? AND ?
               AND l.link_type = 'auto_green'",
            [$dateFrom, $dateTo]
        )->fetchAll();
        foreach ($rowsGreen as $r) {
            $linkedGreenPoster[(int)$r['poster_transaction_id']] = (int)$r['sepay_id'];
        }

        for ($i = 1; $i < count($checks) - 1; $i++) {
            $pid = (int)($checks[$i]['transaction_id'] ?? 0);
            if ($pid <= 0) continue;
            if (!empty($linkedPoster[$pid])) continue;
            $pmId = (int)($checks[$i]['poster_payment_method_id'] ?? 0);
            if ($pmId === 11) continue;

            $prevPid = (int)($checks[$i - 1]['transaction_id'] ?? 0);
            $nextPid = (int)($checks[$i + 1]['transaction_id'] ?? 0);
            if ($prevPid <= 0 || $nextPid <= 0) continue;
            if (empty($linkedGreenPoster[$prevPid]) || empty($linkedGreenPoster[$nextPid])) continue;

            $payedCardVnd = $posterCentsToVnd((int)(($checks[$i]['payed_card'] ?? 0) + ($checks[$i]['payed_third_party'] ?? 0)));
            $tipVnd = $posterCentsToVnd((int)($checks[$i]['tip_sum'] ?? 0));
            $totalVnd = $payedCardVnd + $tipVnd;
            if ($totalVnd <= 0) continue;
            $amounts = [$totalVnd];

            $best = null;
            $bestDiff = null;
            $closeTs = strtotime((string)$checks[$i]['date_close']);
            if ($closeTs === false || $closeTs <= 0) continue;

            foreach ($amounts as $amt) {
                foreach (($sepayByAmount[$amt] ?? []) as $s) {
                    $sid = (int)$s['sepay_id'];
                    if (!empty($linkedSepay[$sid])) continue;
                    $stTs = strtotime((string)$s['transaction_date']);
                    if ($stTs === false || $stTs <= 0) continue;
                    $diff = abs($stTs - $closeTs);
                    if ($best === null || $diff < $bestDiff) {
                        $best = $s;
                        $bestDiff = $diff;
                    }
                }
            }
            if ($best !== null) {
                $sid = (int)$best['sepay_id'];
                $db->query(
                    "INSERT IGNORE INTO {$pl} (poster_transaction_id, sepay_id, link_type, is_manual)
                     VALUES (?, ?, 'auto_green', 0)",
                    [$pid, $sid]
                );
                $linkedPoster[$pid] = true;
                $linkedSepay[$sid] = true;
            }
        }

        foreach ($checks as $c) {
            $pid = (int)($c['transaction_id'] ?? 0);
            if ($pid <= 0) continue;
            if (!empty($linkedPoster[$pid])) continue;
            $pmId = (int)($c['poster_payment_method_id'] ?? 0);
            if ($pmId === 11) continue;

            $payedCardVnd = $posterCentsToVnd((int)(($c['payed_card'] ?? 0) + ($c['payed_third_party'] ?? 0)));
            $tipVnd = $posterCentsToVnd((int)($c['tip_sum'] ?? 0));
            $totalVnd = $payedCardVnd + $tipVnd;
            if ($totalVnd <= 0) continue;
            $amounts = [$totalVnd];
            $closeTs = strtotime((string)$c['date_close']);
            if ($closeTs === false || $closeTs <= 0) continue;

            $best = null;
            $bestDiff = null;
            foreach ($amounts as $amt) {
                foreach (($sepayByAmount[$amt] ?? []) as $s) {
                    $sid = (int)$s['sepay_id'];
                    if (!empty($linkedSepay[$sid])) continue;
                    $stTs = strtotime((string)$s['transaction_date']);
                    if ($stTs === false || $stTs <= 0) continue;
                    $diff = abs($stTs - $closeTs);
                    if ($best === null || $diff < $bestDiff) {
                        $best = $s;
                        $bestDiff = $diff;
                    }
                }
            }
            if ($best !== null) {
                $sid = (int)$best['sepay_id'];
                $db->query(
                    "INSERT IGNORE INTO {$pl} (poster_transaction_id, sepay_id, link_type, is_manual)
                     VALUES (?, ?, 'auto_yellow', 0)",
                    [$pid, $sid]
                );
                $linkedPoster[$pid] = true;
                $linkedSepay[$sid] = true;
            }
        }
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (($_GET['ajax'] ?? '') === 'unlink') {
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $payload = json_decode(file_get_contents('php://input') ?: '[]', true);
    if (!is_array($payload)) $payload = [];
    $posterId = (int)($payload['poster_transaction_id'] ?? 0);
    $sepayId = (int)($payload['sepay_id'] ?? 0);
    if ($posterId <= 0 || $sepayId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $db->query(
            "DELETE FROM {$pl} WHERE poster_transaction_id = ? AND sepay_id = ? LIMIT 1",
            [$posterId, $sepayId]
        );
        echo json_encode(['ok' => true, 'deleted' => true], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (($_GET['ajax'] ?? '') === 'links') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $rows = $db->query(
            "SELECT l.poster_transaction_id, l.sepay_id, l.link_type, l.is_manual
             FROM {$pl} l
             JOIN {$pc} p ON p.transaction_id = l.poster_transaction_id
             WHERE p.day_date = ?",
            [$date]
        )->fetchAll();
        echo json_encode(['ok' => true, 'links' => $rows], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$sepayRows = $db->query(
    "SELECT sepay_id, transaction_date, transfer_amount, payment_method, content, reference_code
     FROM {$st}
     WHERE transaction_date BETWEEN ? AND ?
       AND transfer_type = 'in'
       AND (payment_method IS NULL OR payment_method IN ('Card','Bybit'))
     ORDER BY transaction_date ASC",
    [$periodFrom, $periodTo]
)->fetchAll();

$posterRows = $db->query(
    "SELECT p.transaction_id, p.receipt_number, p.date_close, p.payed_card, p.payed_third_party, p.tip_sum,
            pm.title AS payment_method_display,
            p.waiter_name, p.table_id, p.poster_payment_method_id
     FROM {$pc} p
     LEFT JOIN {$ppm} pm ON pm.payment_method_id = p.poster_payment_method_id
     WHERE p.day_date BETWEEN ? AND ?
       AND pay_type IN (2,3)
       AND (payed_card + payed_third_party) > 0
     ORDER BY date_close ASC",
    [$dateFrom, $dateTo]
)->fetchAll();

$sepayTotalVnd = 0;
$posterTotalVnd = 0;
$posterBybitVnd = 0;
$posterVietVnd = 0;
try {
    $sepayTotalVnd = (int)$db->query(
        "SELECT COALESCE(SUM(transfer_amount), 0) FROM {$st}
         WHERE transaction_date BETWEEN ? AND ?
           AND transfer_type = 'in'
           AND (payment_method IS NULL OR payment_method IN ('Card','Bybit'))",
        [$periodFrom, $periodTo]
    )->fetchColumn();
} catch (\Throwable $e) {
    $sepayTotalVnd = 0;
}
try {
    $posterTotalCents = (int)$db->query(
        "SELECT COALESCE(SUM(p.payed_card + p.payed_third_party + p.tip_sum), 0)
         FROM {$pc} p
         LEFT JOIN {$ppm} pm ON pm.payment_method_id = p.poster_payment_method_id
         WHERE p.day_date BETWEEN ? AND ?
           AND p.pay_type IN (2,3)
           AND (p.payed_card + p.payed_third_party) > 0
           AND (pm.title IS NULL OR LOWER(pm.title) <> 'vietnam company')",
        [$dateFrom, $dateTo]
    )->fetchColumn();
    $posterTotalVnd = $posterCentsToVnd($posterTotalCents);
} catch (\Throwable $e) {
    $posterTotalVnd = 0;
}

try {
    $bybitCents = (int)$db->query(
        "SELECT COALESCE(SUM(payed_card + payed_third_party + tip_sum), 0)
         FROM {$pc}
         WHERE day_date BETWEEN ? AND ?
           AND pay_type IN (2,3)
           AND (payed_card + payed_third_party) > 0
           AND poster_payment_method_id = 12",
        [$dateFrom, $dateTo]
    )->fetchColumn();
    $posterBybitVnd = $posterCentsToVnd($bybitCents);
} catch (\Throwable $e) {
    $posterBybitVnd = 0;
}

try {
    $vietCents = (int)$db->query(
        "SELECT COALESCE(SUM(payed_card + payed_third_party + tip_sum), 0)
         FROM {$pc}
         WHERE day_date BETWEEN ? AND ?
           AND pay_type IN (2,3)
           AND (payed_card + payed_third_party) > 0
           AND poster_payment_method_id = 11",
        [$dateFrom, $dateTo]
    )->fetchColumn();
    $posterVietVnd = $posterCentsToVnd($vietCents);
} catch (\Throwable $e) {
    $posterVietVnd = 0;
}

$links = $db->query(
    "SELECT l.poster_transaction_id, l.sepay_id, l.link_type, l.is_manual
     FROM {$pl} l
     JOIN {$pc} p ON p.transaction_id = l.poster_transaction_id
     WHERE p.day_date BETWEEN ? AND ?",
    [$dateFrom, $dateTo]
)->fetchAll();

$linkByPoster = [];
$linkBySepay = [];
foreach ($links as $l) {
    $pid = (int)($l['poster_transaction_id'] ?? 0);
    $sid = (int)($l['sepay_id'] ?? 0);
    if ($pid <= 0 || $sid <= 0) continue;
    $t = (string)($l['link_type'] ?? '');
    $m = !empty($l['is_manual']);
    if (!isset($linkByPoster[$pid])) $linkByPoster[$pid] = [];
    $linkByPoster[$pid][] = ['sepay_id' => $sid, 'link_type' => $t, 'is_manual' => $m];
    if (!isset($linkBySepay[$sid])) $linkBySepay[$sid] = [];
    $linkBySepay[$sid][] = ['poster_transaction_id' => $pid, 'link_type' => $t, 'is_manual' => $m];
}

$financeRows = [];
$financeDisplay = [
    'vietnam' => null,
    'tips' => null,
];

$metaTable = $db->t('system_meta');
$sepayWebhookMeta = [
    'last_at' => '',
    'last_ip' => '',
    'last_ok' => '',
    'last_error' => '',
    'last_sepay_id' => '',
    'last_method' => '',
    'last_body_sha256' => '',
    'last_body_truncated' => '',
    'last_body' => '',
    'hits_total' => '',
    'hits_day' => '',
];
try {
    $dayKey = 'sepay_webhook_hits_' . date('Ymd', strtotime($date));
    $keys = [
        'sepay_webhook_last_at',
        'sepay_webhook_last_ip',
        'sepay_webhook_last_ok',
        'sepay_webhook_last_error',
        'sepay_webhook_last_sepay_id',
        'sepay_webhook_last_method',
        'sepay_webhook_last_body_sha256',
        'sepay_webhook_last_body_truncated',
        'sepay_webhook_last_body',
        'sepay_webhook_hits_total',
        $dayKey,
    ];
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $rows = $db->query("SELECT meta_key, meta_value FROM {$metaTable} WHERE meta_key IN ({$placeholders})", $keys)->fetchAll();
    $map = [];
    foreach ($rows as $r) {
        $k = (string)($r['meta_key'] ?? '');
        $v = (string)($r['meta_value'] ?? '');
        if ($k !== '') $map[$k] = $v;
    }
    $sepayWebhookMeta['last_at'] = (string)($map['sepay_webhook_last_at'] ?? '');
    $sepayWebhookMeta['last_ip'] = (string)($map['sepay_webhook_last_ip'] ?? '');
    $sepayWebhookMeta['last_ok'] = (string)($map['sepay_webhook_last_ok'] ?? '');
    $sepayWebhookMeta['last_error'] = (string)($map['sepay_webhook_last_error'] ?? '');
    $sepayWebhookMeta['last_sepay_id'] = (string)($map['sepay_webhook_last_sepay_id'] ?? '');
    $sepayWebhookMeta['last_method'] = (string)($map['sepay_webhook_last_method'] ?? '');
    $sepayWebhookMeta['last_body_sha256'] = (string)($map['sepay_webhook_last_body_sha256'] ?? '');
    $sepayWebhookMeta['last_body_truncated'] = (string)($map['sepay_webhook_last_body_truncated'] ?? '');
    $sepayWebhookMeta['last_body'] = (string)($map['sepay_webhook_last_body'] ?? '');
    $sepayWebhookMeta['hits_total'] = (string)($map['sepay_webhook_hits_total'] ?? '');
    $sepayWebhookMeta['hits_day'] = (string)($map[$dayKey] ?? '');
} catch (\Throwable $e) {
}

$sepayTxCount = 0;
try {
    $sepayTxCount = (int)$db->query(
        "SELECT COUNT(*) AS c FROM {$st}
         WHERE transaction_date BETWEEN ? AND ?
           AND transfer_type = 'in'
           AND (payment_method IS NULL OR payment_method IN ('Card','Bybit'))",
        [$periodFrom, $periodTo]
    )->fetchColumn();
} catch (\Throwable $e) {
    $sepayTxCount = 0;
}

$posterTxCount = 0;
$posterTxCountError = '';
try {
    $posterTxCount = (int)$db->query(
        "SELECT COUNT(*) AS c FROM {$pc}
         WHERE day_date BETWEEN ? AND ?
           AND pay_type IN (2,3)
           AND (payed_card + payed_third_party) > 0",
        [$dateFrom, $dateTo]
    )->fetchColumn();
} catch (\Throwable $e) {
    $posterTxCount = 0;
    $posterTxCountError = $e->getMessage();
}
$financeVietnamCents = null;
$financeTipsCents = null;
try {
    $financeVietnamCents = (int)$db->query(
        "SELECT COALESCE(SUM(payed_card + payed_third_party), 0)
         FROM {$pc}
         WHERE day_date BETWEEN ? AND ?
           AND pay_type IN (2,3)
           AND (payed_card + payed_third_party) > 0
           AND poster_payment_method_id = 11",
        [$dateFrom, $dateTo]
    )->fetchColumn();
} catch (\Throwable $e) {
    $financeVietnamCents = null;
}
try {
    $financeTipsCents = (int)$db->query(
        "SELECT COALESCE(SUM(tip_sum), 0)
         FROM {$pc}
         WHERE day_date BETWEEN ? AND ?
           AND pay_type IN (2,3)
           AND (payed_card + payed_third_party) > 0
           AND tip_sum > 0",
        [$dateFrom, $dateTo]
    )->fetchColumn();
} catch (\Throwable $e) {
    $financeTipsCents = null;
}

$fmtVnd = function (int $v): string {
    return number_format($v, 0, '.', ',') . ' ₫';
};
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="/links/favicon.svg">
    <title>Payday</title>
    <link rel="stylesheet" href="/assets/app.css">
    <style>
        body { font-family: Arial, sans-serif; background: #f5f6fa; margin: 0; padding: 0; }
        .container { width: 100%; max-width: 1800px; margin: 0 auto; padding: 12px; box-sizing: border-box; }
        .top-nav { display:flex; justify-content: space-between; align-items:center; gap: 16px; flex-wrap: wrap; margin-bottom: 12px; }
        .nav-left { display:flex; gap: 14px; align-items:center; flex-wrap: wrap; }
        .nav-title { font-weight: 800; color: #2c3e50; }
        .toolbar { display:flex; gap: 10px; flex-wrap: wrap; align-items: center; }
        .toolbar-line { flex-wrap: nowrap; overflow-x: auto; }
        .toolbar-line form { margin: 0; }
        .btn { position: relative; overflow: hidden; }
        .btn.loading::after { content: ''; position: absolute; left: 10px; right: 10px; bottom: 6px; height: 3px; border-radius: 999px; background: rgba(17, 24, 39, 0.12); }
        .btn.loading::before { content: ''; position: absolute; left: 10px; bottom: 6px; height: 3px; width: 22px; border-radius: 999px; background: currentColor; opacity: 0.75; animation: btnbar 900ms ease-in-out infinite alternate; }
        @keyframes btnbar { from { transform: translateX(0); } to { transform: translateX(22px); } }
        .btn { padding: 10px 14px; border-radius: 10px; border: 1px solid #d0d5dd; background: #fff; font-weight: 800; cursor: pointer; }
        .btn.primary { background: #1a73e8; border-color: #1a73e8; color: #fff; }
        .btn:disabled { opacity: 0.6; cursor: default; }
        .card { background: #fff; border: 1px solid #e0e0e0; border-radius: 14px; padding: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .grid { display:grid; grid-template-columns: 1fr 120px 1fr; gap: 12px; align-items:start; }
        @media (max-width: 1050px) { .grid { grid-template-columns: 1fr; } }
        #tablesRoot { position: relative; overflow: hidden; }
        #lineLayer { position:absolute; inset:0; pointer-events:none; overflow:hidden; z-index: 2; grid-column: 1 / -1; grid-row: 1 / -1; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px; border-bottom: 1px solid #e0e0e0; vertical-align: top; }
        th { background: #f8f9fa; color: #65676b; font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em; }
        tr.row-green { background: rgba(46, 125, 50, 0.08); }
        tr.row-yellow { background: rgba(255, 193, 7, 0.16); }
        tr.row-red { background: rgba(211, 47, 47, 0.08); }
        tr.row-blue { background: rgba(26, 115, 232, 0.10); }
        tr.row-gray { background: rgba(107, 114, 128, 0.10); }
        tr.row-selected { outline: 2px solid #1a73e8; outline-offset: -2px; }
        .muted { color: #777; font-size: 12px; }
        .sum { font-weight: 900; white-space: nowrap; }
        .nowrap { white-space: nowrap; }
        .anchor { display:inline-block; width: 10px; height: 10px; border-radius: 50%; background: #9aa4b2; vertical-align: middle; }
        tr.row-green .anchor { background: #2e7d32; }
        tr.row-yellow .anchor { background: #f6c026; }
        tr.row-blue .anchor { background: #1a73e8; }
        tr.row-red .anchor { background: #d32f2f; }
        tr.row-gray .anchor { background: #6b7280; }
        .actions { display:flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-top: 10px; }
        .divider { height: 1px; background: #e0e0e0; margin: 12px 0; }
        .finance-row { display:flex; align-items:center; justify-content: space-between; gap: 12px; padding: 10px; border: 1px solid #e5e7eb; border-radius: 12px; }
        .finance-row + .finance-row { margin-top: 10px; }
        .finance-left { display:flex; flex-direction: column; gap: 4px; }
        .badge { display:inline-flex; align-items:center; gap: 6px; padding: 4px 10px; border-radius: 999px; font-weight: 800; font-size: 12px; border: 1px solid #e5e7eb; background: #fff; }
        .link-x { position: absolute; z-index: 20; width: 16px; height: 16px; border-radius: 999px; border: 1px solid #d0d5dd; background: #fff; color: #111827; display:flex; align-items:center; justify-content:center; font-weight: 900; font-size: 12px; line-height: 1; cursor: pointer; padding: 0; }
        .link-x:hover { background: #f3f4f6; }
        .cell-anchor { display:flex; align-items:center; gap: 8px; }
        .cell-anchor input[type="checkbox"] { width: 16px; height: 16px; }
        .mid-col { display:flex; flex-direction: column; align-items:center; justify-content:flex-start; gap: 10px; padding-top: 16px; }
        .mid-btn { width: 44px; height: 44px; border-radius: 14px; border: 1px solid #d0d5dd; background: #fff; font-weight: 900; cursor: pointer; display:flex; align-items:center; justify-content:center; position: relative; overflow: hidden; }
        .mid-btn.primary { background: #1a73e8; border-color: #1a73e8; color: #fff; }
        .mid-btn.active { background: #111827; border-color: #111827; color: #fff; }
        .mid-btn:disabled { opacity: 0.5; cursor: default; }
        .mid-check { display:flex; gap: 8px; align-items:center; font-weight: 800; font-size: 12px; color: #374151; user-select: none; }
    </style>
</head>
<body>
<div class="container">
    <div class="top-nav">
        <div class="nav-left">
            <div class="nav-title">Payday</div>
        </div>
        <?php require __DIR__ . '/../partials/user_menu.php'; ?>
    </div>

    <?php if ($error !== ''): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="card">
        <div class="toolbar toolbar-line" style="margin-bottom: 10px;">
            <form method="GET">
                <input type="date" name="dateFrom" value="<?= htmlspecialchars($dateFrom) ?>" class="btn" style="padding: 8px 10px;">
                <input type="date" name="dateTo" value="<?= htmlspecialchars($dateTo) ?>" class="btn" style="padding: 8px 10px;">
                <button class="btn" type="submit">Открыть</button>
            </form>

            <form method="POST" id="posterSyncForm">
                <input type="hidden" name="action" value="load_poster_checks">
                <input type="hidden" name="dateFrom" value="<?= htmlspecialchars($dateFrom) ?>">
                <input type="hidden" name="dateTo" value="<?= htmlspecialchars($dateTo) ?>">
                <button class="btn primary" id="posterSyncBtn" type="submit">Загрузить чеки из Poster</button>
            </form>

            <form method="POST" id="sepaySyncForm">
                <input type="hidden" name="action" value="reload_sepay_api">
                <input type="hidden" name="dateFrom" value="<?= htmlspecialchars($dateFrom) ?>">
                <input type="hidden" name="dateTo" value="<?= htmlspecialchars($dateTo) ?>">
                <button class="btn primary" id="sepaySyncBtn" type="submit">Обновить платежи</button>
            </form>

            <form method="POST" id="clearDayForm">
                <input type="hidden" name="action" value="clear_day">
                <input type="hidden" name="dateFrom" value="<?= htmlspecialchars($dateFrom) ?>">
                <input type="hidden" name="dateTo" value="<?= htmlspecialchars($dateTo) ?>">
                <button class="btn" id="clearDayBtn" type="submit" onclick="return confirm('Очистить все данные за выбранный день (Poster, SePay, связи)?')">Очистить день</button>
            </form>
        </div>

        <div class="divider"></div>

        <div class="grid" id="tablesRoot">
            <div id="lineLayer"></div>
            <div class="card" style="padding: 0;">
                <div style="padding: 12px 12px 6px;">
                    <div style="font-weight:900;">SePay</div>
                    <div class="muted">Приходы за день</div>
                </div>
                <div id="sepayScroll" style="max-height: 56vh; overflow:auto;">
                    <table id="sepayTable">
                        <thead>
                            <tr>
                                <th></th>
                                <th class="nowrap sortable" data-sort-key="ts">Время</th>
                                <th class="sortable" data-sort-key="content">Content</th>
                                <th class="nowrap sortable" data-sort-key="sum">Сумма</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($sepayRows as $r): ?>
                            <?php
                                $sid = (int)$r['sepay_id'];
                                $linkList = $linkBySepay[$sid] ?? [];
                                $cls = 'row-red';
                                if ($linkList) {
                                    $hasManual = false;
                                    $hasYellow = false;
                                    foreach ($linkList as $l) {
                                        if (!empty($l['is_manual'])) $hasManual = true;
                                        if (($l['link_type'] ?? '') === 'auto_yellow') $hasYellow = true;
                                    }
                                    if ($hasManual) $cls = 'row-gray';
                                    else $cls = $hasYellow ? 'row-yellow' : 'row-green';
                                }
                                $pm = (string)($r['payment_method'] ?? '');
                            ?>
                            <?php $tsRow = strtotime($r['transaction_date']) ?: 0; ?>
                            <tr class="<?= $cls ?>" data-sepay-id="<?= $sid ?>" data-ts="<?= (int)$tsRow ?>" data-sum="<?= (int)$r['transfer_amount'] ?>" data-content="<?= htmlspecialchars(mb_strtolower((string)($r['content'] ?? ''), 'UTF-8')) ?>">
                                <td><div class="cell-anchor"><span class="anchor" id="sepay-<?= $sid ?>"></span><input type="checkbox" class="sepay-cb" data-id="<?= $sid ?>"></div></td>
                                <td class="nowrap"><?= date('H:i:s', strtotime($r['transaction_date'])) ?></td>
                                <td><?= htmlspecialchars((string)($r['content'] ?? '')) ?></td>
                                <td class="sum"><?= htmlspecialchars($fmtVnd((int)$r['transfer_amount'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="muted" style="padding: 10px 12px; font-weight: 900;">
                    Итого: <span id="sepayTotal"><?= htmlspecialchars($fmtVnd((int)$sepayTotalVnd)) ?></span>
                    • связанные: <span id="sepayLinked">—</span>
                    • несвязанные: <span id="sepayUnlinked">—</span>
                </div>
            </div>

            <div class="mid-col">
                <button class="mid-btn primary" id="linkMakeBtn" type="button" title="Связать выбранные">🎯</button>
                <button class="mid-btn" id="hideLinkedBtn" type="button" title="Скрыть связанные">👁</button>
                <button class="mid-btn" id="linkAutoBtn" type="button" title="Автосвязи за день">🧩</button>
                <button class="mid-btn" id="linkClearBtn" type="button" title="Разорвать связи">⛓️‍💥</button>
                <div class="muted" style="text-align:center; font-weight:900; line-height: 1.35;">
                    <div>Платежи: <span id="selSepaySum">0 ₫</span></div>
                    <div>Чеки: <span id="selPosterSum">0 ₫</span></div>
                </div>
            </div>

            <div class="card" style="padding: 0;">
                <div style="padding: 12px 12px 6px;">
                    <div style="font-weight:900;">Poster</div>
                    <div class="muted">Безнал / смешанная (за выбранный день)</div>
                </div>
                <div id="posterScroll" style="max-height: 56vh; overflow:auto;">
                    <table id="posterTable">
                        <thead>
                            <tr>
                                <th></th>
                                <th class="nowrap sortable" data-sort-key="num">№</th>
                                <th class="nowrap sortable" data-sort-key="ts">Время</th>
                                <th class="nowrap sortable" data-sort-key="card">Card</th>
                                <th class="nowrap sortable" data-sort-key="tips">Tips</th>
                                <th class="nowrap sortable" data-sort-key="total">Card+Tips</th>
                                <th class="sortable" data-sort-key="method">Метод</th>
                                <th class="sortable" data-sort-key="waiter">Официант</th>
                                <th class="nowrap sortable" data-sort-key="table">Стол</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($posterRows as $r): ?>
                            <?php
                                $pid = (int)$r['transaction_id'];
                                $receiptNumber = (int)($r['receipt_number'] ?? 0);
                                if ($receiptNumber <= 0) $receiptNumber = $pid;
                                $linkList = $linkByPoster[$pid] ?? [];
                                $cls = 'row-red';
                                if ($linkList) {
                                    $hasManual = false;
                                    $hasYellow = false;
                                    foreach ($linkList as $l) {
                                        if (!empty($l['is_manual'])) $hasManual = true;
                                        if (($l['link_type'] ?? '') === 'auto_yellow') $hasYellow = true;
                                    }
                                    if ($hasManual) $cls = 'row-gray';
                                    else $cls = $hasYellow ? 'row-yellow' : 'row-green';
                                }
                                $pm = (string)($r['payment_method_display'] ?? '');
                                $isVietnam = stripos($pm, 'vietnam') !== false;
                                if ($isVietnam) {
                                    $cls = 'row-blue';
                                }
                                $cardCents = (int)($r['payed_card'] ?? 0) + (int)($r['payed_third_party'] ?? 0);
                                $tipCents = (int)$r['tip_sum'];
                                $cardVnd = $posterCentsToVnd($cardCents);
                                $tipVnd = $posterCentsToVnd($tipCents);
                                $tsRow = strtotime($r['date_close']) ?: 0;
                            ?>
                            <tr class="<?= $cls ?>" data-poster-id="<?= $pid ?>" data-vietnam="<?= $isVietnam ? '1' : '0' ?>" data-num="<?= (int)$receiptNumber ?>" data-ts="<?= (int)$tsRow ?>" data-card="<?= (int)$cardVnd ?>" data-tips="<?= (int)$tipVnd ?>" data-total="<?= (int)($cardVnd + $tipVnd) ?>" data-method="<?= htmlspecialchars(mb_strtolower($pm, 'UTF-8')) ?>" data-waiter="<?= htmlspecialchars(mb_strtolower((string)($r['waiter_name'] ?? ''), 'UTF-8')) ?>" data-table="<?= (int)($r['table_id'] ?? 0) ?>">
                                <td><div class="cell-anchor"><span class="anchor" id="poster-<?= $pid ?>"></span><input type="checkbox" class="poster-cb" data-id="<?= $pid ?>"></div></td>
                                <td class="nowrap"><?= htmlspecialchars((string)$receiptNumber) ?></td>
                                <td class="nowrap"><?= date('H:i:s', strtotime($r['date_close'])) ?></td>
                                <td class="sum"><?= htmlspecialchars($fmtVnd($cardVnd)) ?></td>
                                <td class="sum"><?= htmlspecialchars($fmtVnd($tipVnd)) ?></td>
                                <td class="sum"><?= htmlspecialchars($fmtVnd($cardVnd + $tipVnd)) ?></td>
                                <td class="nowrap"><?= htmlspecialchars($pm !== '' ? $pm : '—') ?></td>
                                <td><?= htmlspecialchars((string)($r['waiter_name'] ?? '')) ?></td>
                                <td class="nowrap"><?= htmlspecialchars((string)($r['table_id'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="muted" style="padding: 10px 12px; font-weight: 900;">
                    Итого: <span id="posterTotal"><?= htmlspecialchars($fmtVnd((int)$posterTotalVnd)) ?></span>
                    • связанные: <span id="posterLinked">—</span>
                    • несвязанные: <span id="posterUnlinked">—</span>
                    • Bybit: <span><?= htmlspecialchars($fmtVnd((int)$posterBybitVnd)) ?></span>
                    • VietComp: <span><?= htmlspecialchars($fmtVnd((int)$posterVietVnd)) ?></span>
                </div>
            </div>
        </div>

        <div class="actions">
            <div class="muted">Связь: отметь чекбоксы в обеих таблицах → ⛓</div>
        </div>

        <div class="divider"></div>

        <div class="card" style="background:#fbfbfd;">
            <div style="font-weight: 900; margin-bottom: 10px;">Финансовые транзакции</div>

            <?php
            $vietnamCents = $financeVietnamCents;
            $tipsCents = $financeTipsCents;
            $vietnamVnd = $vietnamCents !== null ? $posterCentsToVnd((int)$vietnamCents) : null;
            $tipsVnd = $tipsCents !== null ? $posterCentsToVnd((int)$tipsCents) : null;
            $vietnamDisabled = $vietnamCents === null || (int)$vietnamCents <= 0;
            $tipsDisabled = $tipsCents === null || (int)$tipsCents <= 0;
            $vietnamDisabledReason = $vietnamCents === null
                ? 'Нет данных за период: нажми «Загрузить чеки из Poster».'
                : 'Сумма = 0: нет чеков Vietnam Company (payment_method_id=11) за период.';
            $tipsDisabledReason = $tipsCents === null
                ? 'Нет данных за период: нажми «Загрузить чеки из Poster».'
                : 'Сумма = 0: нет tip_sum за период.';
            ?>

            <div class="finance-row">
                <div class="finance-left">
                    <div style="font-weight:900;">Vietnam Company — Card payments</div>
                    <div class="muted"><?= $vietnamVnd !== null ? htmlspecialchars($fmtVnd($vietnamVnd)) : '—' ?></div>
                </div>
                <form method="POST" style="display:flex; flex-direction:column; align-items:flex-end; gap: 4px;">
                    <input type="hidden" name="action" value="create_transfer">
                    <input type="hidden" name="kind" value="vietnam">
                    <input type="hidden" name="dateFrom" value="<?= htmlspecialchars($dateFrom) ?>">
                    <input type="hidden" name="dateTo" value="<?= htmlspecialchars($dateTo) ?>">
                    <button class="btn" type="submit" <?= $vietnamDisabled ? 'disabled' : '' ?>>Создать перевод</button>
                    <?php if ($vietnamDisabled): ?><div class="muted"><?= htmlspecialchars($vietnamDisabledReason) ?></div><?php endif; ?>
                </form>
            </div>

            <div class="finance-row">
                <div class="finance-left">
                    <div style="font-weight:900;">Card tips per shift</div>
                    <div class="muted"><?= $tipsVnd !== null ? htmlspecialchars($fmtVnd($tipsVnd)) : '—' ?></div>
                </div>
                <form method="POST" style="display:flex; flex-direction:column; align-items:flex-end; gap: 4px;">
                    <input type="hidden" name="action" value="create_transfer">
                    <input type="hidden" name="kind" value="tips">
                    <input type="hidden" name="dateFrom" value="<?= htmlspecialchars($dateFrom) ?>">
                    <input type="hidden" name="dateTo" value="<?= htmlspecialchars($dateTo) ?>">
                    <button class="btn" type="submit" <?= $tipsDisabled ? 'disabled' : '' ?>>Создать перевод</button>
                    <?php if ($tipsDisabled): ?><div class="muted"><?= htmlspecialchars($tipsDisabledReason) ?></div><?php endif; ?>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
(() => {
    let links = <?= json_encode(array_values(array_map(function ($l) {
        return [
            'poster_transaction_id' => (int)$l['poster_transaction_id'],
            'sepay_id' => (int)$l['sepay_id'],
            'link_type' => (string)$l['link_type'],
            'is_manual' => !empty($l['is_manual']),
        ];
    }, $links)), JSON_UNESCAPED_UNICODE) ?>;

    const setFormLoading = (formId, btnId) => {
        const form = document.getElementById(formId);
        const btn = document.getElementById(btnId);
        if (!form || !btn) return;
        form.addEventListener('submit', () => {
            btn.classList.add('loading');
            btn.disabled = true;
        });
    };
    setFormLoading('posterSyncForm', 'posterSyncBtn');
    setFormLoading('sepaySyncForm', 'sepaySyncBtn');
    setFormLoading('clearDayForm', 'clearDayBtn');

    const widgets = new Map();
    const svgState = { svg: null, defs: null, group: null, markers: new Map() };

    const colorFor = (t, isManual) => {
        if (isManual || t === 'manual') return '#6b7280';
        if (t === 'auto_green') return '#2e7d32';
        if (t === 'auto_yellow') return '#f6c026';
        return '#9aa4b2';
    };

    const endPlugFor = (t, isManual) => {
        if (isManual || t === 'manual') return 'hand';
        return 'arrow1';
    };

    const clearLines = () => {
        if (svgState.group) {
            while (svgState.group.firstChild) svgState.group.removeChild(svgState.group.firstChild);
        }
    };

    const ensureSvg = () => {
        if (!lineLayer || !tablesRoot) return;
        if (svgState.svg) return;
        const ns = 'http://www.w3.org/2000/svg';
        const svg = document.createElementNS(ns, 'svg');
        svg.setAttribute('width', '100%');
        svg.setAttribute('height', '100%');
        svg.style.display = 'block';
        const defs = document.createElementNS(ns, 'defs');
        const g = document.createElementNS(ns, 'g');
        svg.appendChild(defs);
        svg.appendChild(g);
        lineLayer.appendChild(svg);
        svgState.svg = svg;
        svgState.defs = defs;
        svgState.group = g;
    };

    const ensureMarker = (color) => {
        ensureSvg();
        if (!svgState.defs) return null;
        const key = String(color || '');
        if (svgState.markers.has(key)) return svgState.markers.get(key);
        const ns = 'http://www.w3.org/2000/svg';
        const id = 'm' + (svgState.markers.size + 1);
        const marker = document.createElementNS(ns, 'marker');
        marker.setAttribute('id', id);
        marker.setAttribute('viewBox', '0 0 10 10');
        marker.setAttribute('refX', '10');
        marker.setAttribute('refY', '5');
        marker.setAttribute('markerWidth', '6');
        marker.setAttribute('markerHeight', '6');
        marker.setAttribute('orient', 'auto');
        const path = document.createElementNS(ns, 'path');
        path.setAttribute('d', 'M 0 0 L 10 5 L 0 10 z');
        path.setAttribute('fill', key || '#9aa4b2');
        marker.appendChild(path);
        svgState.defs.appendChild(marker);
        svgState.markers.set(key, id);
        return id;
    };

    const getAnchorPoint = (el, side, rootRect) => {
        const r = el.getBoundingClientRect();
        const x = (side === 'right' ? (r.left + r.width) : r.left) - rootRect.left;
        const y = (r.top + r.height / 2) - rootRect.top;
        return { x, y };
    };

    const isInside = (pt, w, h) => pt.x >= 0 && pt.y >= 0 && pt.x <= w && pt.y <= h;

    const syncButtons = () => {
        if (!tablesRoot) return;
        const keep = new Set();
        links.forEach((l) => {
            const key = String(l.sepay_id) + ':' + String(l.poster_transaction_id);
            keep.add(key);
            if (widgets.has(key)) return;
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'link-x';
            btn.textContent = '×';
            btn.title = 'Удалить связь';
            btn.style.display = 'none';
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                unlink(Number(l.sepay_id || 0), Number(l.poster_transaction_id || 0)).catch((err) => {
                    alert(err && err.message ? err.message : 'Ошибка');
                });
            });
            tablesRoot.appendChild(btn);
            widgets.set(key, btn);
        });
        Array.from(widgets.entries()).forEach(([key, btn]) => {
            if (keep.has(key)) return;
            try { btn.remove(); } catch (_) {}
            widgets.delete(key);
        });
    };

    const fmtVnd = (v) => {
        try {
            return new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(Number(v) || 0) + ' ₫';
        } catch (_) {
            return String(v) + ' ₫';
        }
    };

    const buildLinkState = () => {
        const sepay = new Map();
        const poster = new Map();
        links.forEach((l) => {
            const sid = Number(l.sepay_id || 0);
            const pid = Number(l.poster_transaction_id || 0);
            if (!sid || !pid) return;

            const s = sepay.get(sid) || { hasAny: false, hasManual: false, hasYellow: false };
            s.hasAny = true;
            if (l.is_manual) s.hasManual = true;
            if (l.link_type === 'auto_yellow') s.hasYellow = true;
            sepay.set(sid, s);

            const p = poster.get(pid) || { hasAny: false, hasManual: false, hasYellow: false };
            p.hasAny = true;
            if (l.is_manual) p.hasManual = true;
            if (l.link_type === 'auto_yellow') p.hasYellow = true;
            poster.set(pid, p);
        });
        return { sepay, poster };
    };

    const applyRowClasses = () => {
        const state = buildLinkState();

        const sepayRows = Array.from(document.querySelectorAll('#sepayTable tbody tr[data-sepay-id]'));
        sepayRows.forEach((tr) => {
            const sid = Number(tr.getAttribute('data-sepay-id') || 0);
            const s = state.sepay.get(sid);
            tr.classList.remove('row-red', 'row-green', 'row-yellow', 'row-gray');
            if (!s || !s.hasAny) {
                tr.classList.add('row-red');
            } else if (s.hasManual) {
                tr.classList.add('row-gray');
            } else if (s.hasYellow) {
                tr.classList.add('row-yellow');
            } else {
                tr.classList.add('row-green');
            }
        });

        const posterRows = Array.from(document.querySelectorAll('#posterTable tbody tr[data-poster-id]'));
        posterRows.forEach((tr) => {
            const isVietnam = String(tr.getAttribute('data-vietnam') || '0') === '1';
            tr.classList.remove('row-red', 'row-green', 'row-yellow', 'row-gray', 'row-blue');
            if (isVietnam) {
                tr.classList.add('row-blue');
                return;
            }
            const pid = Number(tr.getAttribute('data-poster-id') || 0);
            const p = state.poster.get(pid);
            if (!p || !p.hasAny) {
                tr.classList.add('row-red');
            } else if (p.hasManual) {
                tr.classList.add('row-gray');
            } else if (p.hasYellow) {
                tr.classList.add('row-yellow');
            } else {
                tr.classList.add('row-green');
            }
        });
    };

    const updateStats = () => {
        const state = buildLinkState();

        let sepayTotal = 0;
        let sepayLinked = 0;
        let sepayUnlinked = 0;
        document.querySelectorAll('#sepayTable tbody tr[data-sepay-id]').forEach((tr) => {
            const sid = Number(tr.getAttribute('data-sepay-id') || 0);
            const sum = Number(tr.getAttribute('data-sum') || 0) || 0;
            sepayTotal += sum;
            if (state.sepay.has(sid)) sepayLinked += sum;
            else sepayUnlinked += sum;
        });

        let posterTotal = 0;
        let posterLinked = 0;
        let posterUnlinked = 0;
        document.querySelectorAll('#posterTable tbody tr[data-poster-id]').forEach((tr) => {
            const isVietnam = String(tr.getAttribute('data-vietnam') || '0') === '1';
            if (isVietnam) return;
            const pid = Number(tr.getAttribute('data-poster-id') || 0);
            const sum = Number(tr.getAttribute('data-total') || 0) || 0;
            posterTotal += sum;
            if (state.poster.has(pid)) posterLinked += sum;
            else posterUnlinked += sum;
        });

        const setText = (id, v) => {
            const el = document.getElementById(id);
            if (!el) return;
            el.textContent = fmtVnd(v);
        };
        setText('sepayTotal', sepayTotal);
        setText('sepayLinked', sepayLinked);
        setText('sepayUnlinked', sepayUnlinked);
        setText('posterTotal', posterTotal);
        setText('posterLinked', posterLinked);
        setText('posterUnlinked', posterUnlinked);
    };

    const refreshLinks = () => {
        const url = <?= json_encode('?' . http_build_query(['dateFrom' => $dateFrom, 'dateTo' => $dateTo, 'ajax' => 'links'])) ?>;
        return fetch(url)
            .then((r) => r.json())
            .then((j) => {
                if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
                const rows = Array.isArray(j.links) ? j.links : [];
                links = rows.map((l) => ({
                    poster_transaction_id: Number(l.poster_transaction_id || 0),
                    sepay_id: Number(l.sepay_id || 0),
                    link_type: String(l.link_type || ''),
                    is_manual: !!l.is_manual,
                }));
                drawLines();
                applyRowClasses();
                updateStats();
                applyHideLinked();
                setTimeout(() => { positionLines(); positionWidgets(); }, 0);
                setTimeout(() => { positionLines(); positionWidgets(); }, 200);
            });
    };

    const unlink = (sepayId, posterId) => {
        const url = <?= json_encode('?' . http_build_query(['dateFrom' => $dateFrom, 'dateTo' => $dateTo, 'ajax' => 'unlink'])) ?>;
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ sepay_id: sepayId, poster_transaction_id: posterId }),
        })
            .then((r) => r.json())
            .then((j) => {
                if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
                return refreshLinks();
            });
    };

    const drawLines = () => {
        ensureSvg();
        clearLines();
        syncButtons();
        if (!tablesRoot || !svgState.svg || !svgState.group) return;
        const rootRect = tablesRoot.getBoundingClientRect();
        const w = Math.max(1, Math.round(rootRect.width));
        const h = Math.max(1, Math.round(rootRect.height));
        svgState.svg.setAttribute('viewBox', `0 0 ${w} ${h}`);
        widgets.forEach((btn) => { btn.style.display = 'none'; });

        const sepayCount = {};
        const posterCount = {};
        links.forEach((l) => {
            const sid = Number(l.sepay_id || 0);
            const pid = Number(l.poster_transaction_id || 0);
            if (sid) sepayCount[sid] = (sepayCount[sid] || 0) + 1;
            if (pid) posterCount[pid] = (posterCount[pid] || 0) + 1;
        });

        links.forEach((l) => {
            const s = document.getElementById('sepay-' + l.sepay_id);
            const p = document.getElementById('poster-' + l.poster_transaction_id);
            if (!s || !p) return;
            if (!s.getClientRects().length || !p.getClientRects().length) return;
            const isMany = (sepayCount[l.sepay_id] || 0) > 1 || (posterCount[l.poster_transaction_id] || 0) > 1;
            const isMainGreen = !isMany && !l.is_manual && l.link_type === 'auto_green';
            const size = 2;
            const color = colorFor(l.link_type, l.is_manual);

            const a = getAnchorPoint(s, 'right', rootRect);
            const b = getAnchorPoint(p, 'left', rootRect);
            if (!isInside(a, w, h) || !isInside(b, w, h)) return;

            const dx = b.x - a.x;
            const cdx = Math.min(120, Math.max(40, Math.abs(dx) * 0.35));
            const c1x = a.x + cdx;
            const c1y = a.y;
            const c2x = b.x - cdx;
            const c2y = b.y;
            const d = `M ${a.x} ${a.y} C ${c1x} ${c1y}, ${c2x} ${c2y}, ${b.x} ${b.y}`;

            const ns = 'http://www.w3.org/2000/svg';
            const outline = document.createElementNS(ns, 'path');
            outline.setAttribute('d', d);
            outline.setAttribute('fill', 'none');
            outline.setAttribute('stroke', 'rgba(255,255,255,0.65)');
            outline.setAttribute('stroke-width', String(size + 2));
            outline.setAttribute('stroke-linecap', 'round');
            outline.setAttribute('stroke-linejoin', 'round');
            svgState.group.appendChild(outline);

            const path = document.createElementNS(ns, 'path');
            path.setAttribute('d', d);
            path.setAttribute('fill', 'none');
            path.setAttribute('stroke', color);
            path.setAttribute('stroke-width', String(size));
            path.setAttribute('stroke-linecap', 'round');
            path.setAttribute('stroke-linejoin', 'round');
            svgState.group.appendChild(path);

            const key = String(l.sepay_id) + ':' + String(l.poster_transaction_id);
            const btn = widgets.get(key);
            if (btn) {
                const mx = (a.x + b.x) / 2;
                const my = (a.y + b.y) / 2;
                const localX = Math.max(8, Math.min(w - 8, mx));
                const localY = Math.max(8, Math.min(h - 8, my));
                btn.style.left = Math.round(localX - 8) + 'px';
                btn.style.top = Math.round(localY - 8) + 'px';
                btn.style.display = 'flex';
            }
        });
    };

    const positionLines = () => {
        drawLines();
    };

    const positionWidgets = () => {
        return;
    };

    const tablesRoot = document.getElementById('tablesRoot');
    const lineLayer = document.getElementById('lineLayer');
    const sepayScroll = document.getElementById('sepayScroll');
    const posterScroll = document.getElementById('posterScroll');
    if (tablesRoot) {
        tablesRoot.addEventListener('scroll', () => { positionLines(); positionWidgets(); }, { passive: true, capture: true });
    }
    if (sepayScroll) {
        sepayScroll.addEventListener('scroll', () => { positionLines(); positionWidgets(); }, { passive: true });
    }
    if (posterScroll) {
        posterScroll.addEventListener('scroll', () => { positionLines(); positionWidgets(); }, { passive: true });
    }
    window.addEventListener('resize', () => { positionLines(); positionWidgets(); }, { passive: true });
    window.addEventListener('load', () => {
        drawLines();
        applyRowClasses();
        updateStats();
        applyHideLinked();
        setTimeout(() => { positionLines(); positionWidgets(); }, 200);
        setTimeout(() => { positionLines(); positionWidgets(); }, 800);
    });
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            drawLines();
            applyRowClasses();
            updateStats();
            applyHideLinked();
            setTimeout(() => { positionLines(); positionWidgets(); }, 200);
        });
    } else {
        drawLines();
        applyRowClasses();
        updateStats();
        applyHideLinked();
        setTimeout(() => { positionLines(); positionWidgets(); }, 200);
    }

    const sepayTable = document.getElementById('sepayTable');
    const posterTable = document.getElementById('posterTable');
    if (!sepayTable || !posterTable) return;

    const selectedSepay = new Set();
    const selectedPoster = new Set();

    const linkMakeBtn = document.getElementById('linkMakeBtn');
    const hideLinkedBtn = document.getElementById('hideLinkedBtn');
    const linkAutoBtn = document.getElementById('linkAutoBtn');
    const linkClearBtn = document.getElementById('linkClearBtn');
    const selSepaySumEl = document.getElementById('selSepaySum');
    const selPosterSumEl = document.getElementById('selPosterSum');

    let hideLinked = false;

    const updateSelectionSums = () => {
        let sSum = 0;
        selectedSepay.forEach((id) => {
            const tr = document.querySelector(`#sepayTable tbody tr[data-sepay-id="${Number(id)}"]`);
            if (!tr) return;
            sSum += Number(tr.getAttribute('data-sum') || 0) || 0;
        });
        let pSum = 0;
        selectedPoster.forEach((id) => {
            const tr = document.querySelector(`#posterTable tbody tr[data-poster-id="${Number(id)}"]`);
            if (!tr) return;
            pSum += Number(tr.getAttribute('data-total') || 0) || 0;
        });
        if (selSepaySumEl) selSepaySumEl.textContent = fmtVnd(sSum);
        if (selPosterSumEl) selPosterSumEl.textContent = fmtVnd(pSum);
    };

    const updateLinkButtonState = () => {
        if (!linkMakeBtn) return;
        const ok = (selectedSepay.size > 0 && selectedPoster.size > 0 && !(selectedSepay.size > 1 && selectedPoster.size > 1));
        linkMakeBtn.disabled = !ok;
        updateSelectionSums();
    };

    const updateHideButtonState = () => {
        if (!hideLinkedBtn) return;
        hideLinkedBtn.classList.toggle('active', hideLinked);
    };

    const clearCheckboxes = () => {
        document.querySelectorAll('input.sepay-cb, input.poster-cb').forEach((cb) => {
            cb.checked = false;
        });
        selectedSepay.clear();
        selectedPoster.clear();
        updateLinkButtonState();
    };

    const setupSort = (table) => {
        const state = { key: null, dir: 'asc' };
        const ths = Array.from(table.querySelectorAll('th.sortable[data-sort-key]'));
        ths.forEach((th) => {
            th.style.cursor = 'pointer';
            th.addEventListener('click', () => {
                const key = (th.getAttribute('data-sort-key') || '').trim();
                if (!key) return;
                state.dir = (state.key === key && state.dir === 'asc') ? 'desc' : 'asc';
                state.key = key;

                const tbody = table.tBodies && table.tBodies[0] ? table.tBodies[0] : null;
                if (!tbody) return;
                const rows = Array.from(tbody.querySelectorAll('tr'));
                rows.sort((a, b) => {
                    const av = (a.dataset && a.dataset[key]) ? a.dataset[key] : '';
                    const bv = (b.dataset && b.dataset[key]) ? b.dataset[key] : '';
                    const na = Number(av);
                    const nb = Number(bv);
                    let cmp = 0;
                    if (av !== '' && bv !== '' && !Number.isNaN(na) && !Number.isNaN(nb)) {
                        cmp = na - nb;
                    } else {
                        cmp = String(av).localeCompare(String(bv), 'ru', { numeric: true, sensitivity: 'base' });
                    }
                    return state.dir === 'asc' ? cmp : -cmp;
                });
                rows.forEach((r) => tbody.appendChild(r));
                positionLines();
                positionWidgets();
            });
        });
    };

    setupSort(sepayTable);
    setupSort(posterTable);

    const sendManualLinks = (sepayIds, posterIds) => {
        const url = <?= json_encode('?' . http_build_query(['dateFrom' => $dateFrom, 'dateTo' => $dateTo, 'ajax' => 'manual_link'])) ?>;
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ sepay_ids: sepayIds, poster_transaction_ids: posterIds }),
        })
        .then((r) => r.json())
        .then((j) => {
            if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
            return refreshLinks();
        });
    };

    const sendAutoLinks = () => {
        const url = <?= json_encode('?' . http_build_query(['dateFrom' => $dateFrom, 'dateTo' => $dateTo, 'ajax' => 'auto_link'])) ?>;
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({}),
        })
        .then((r) => r.json())
        .then((j) => {
            if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
            return refreshLinks();
        });
    };

    const sendClearLinks = () => {
        const url = <?= json_encode('?' . http_build_query(['dateFrom' => $dateFrom, 'dateTo' => $dateTo, 'ajax' => 'clear_links'])) ?>;
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({}),
        })
        .then((r) => r.json())
        .then((j) => {
            if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
            return refreshLinks();
        });
    };

    const applyHideLinked = () => {
        const state = buildLinkState();
        document.querySelectorAll('#sepayTable tbody tr[data-sepay-id]').forEach((tr) => {
            const sid = Number(tr.getAttribute('data-sepay-id') || 0);
            const linked = state.sepay.has(sid);
            const hidden = hideLinked && linked;
            tr.style.display = hidden ? 'none' : '';
            if (hidden) {
                const cb = tr.querySelector('input.sepay-cb');
                if (cb) cb.checked = false;
                selectedSepay.delete(sid);
            }
        });
        document.querySelectorAll('#posterTable tbody tr[data-poster-id]').forEach((tr) => {
            const pid = Number(tr.getAttribute('data-poster-id') || 0);
            const linked = state.poster.has(pid);
            const hidden = hideLinked && linked;
            tr.style.display = hidden ? 'none' : '';
            if (hidden) {
                const cb = tr.querySelector('input.poster-cb');
                if (cb) cb.checked = false;
                selectedPoster.delete(pid);
            }
        });
        updateLinkButtonState();
    };

    document.querySelectorAll('input.sepay-cb').forEach((cb) => {
        cb.addEventListener('change', () => {
            const id = Number(cb.getAttribute('data-id') || 0);
            if (!id) return;
            if (cb.checked) selectedSepay.add(id);
            else selectedSepay.delete(id);
            updateLinkButtonState();
        });
    });
    document.querySelectorAll('input.poster-cb').forEach((cb) => {
        cb.addEventListener('change', () => {
            const id = Number(cb.getAttribute('data-id') || 0);
            if (!id) return;
            if (cb.checked) selectedPoster.add(id);
            else selectedPoster.delete(id);
            updateLinkButtonState();
        });
    });

    if (linkMakeBtn) {
        linkMakeBtn.addEventListener('click', () => {
            const sepayIds = Array.from(selectedSepay.values()).map((v) => Number(v)).filter((v) => v > 0);
            const posterIds = Array.from(selectedPoster.values()).map((v) => Number(v)).filter((v) => v > 0);
            if (!sepayIds.length || !posterIds.length) return;
            if (sepayIds.length > 1 && posterIds.length > 1) {
                alert('Нельзя: выбери 1 платеж и много чеков или 1 чек и много платежей.');
                return;
            }
            sendManualLinks(sepayIds, posterIds)
                .then(() => clearCheckboxes())
                .catch((e) => alert(e && e.message ? e.message : 'Ошибка'));
        });
    }
    if (hideLinkedBtn) {
        hideLinkedBtn.addEventListener('click', () => {
            hideLinked = !hideLinked;
            updateHideButtonState();
            applyHideLinked();
            drawLines();
            setTimeout(() => { positionLines(); positionWidgets(); }, 0);
        });
        updateHideButtonState();
    }
    if (linkAutoBtn) {
        linkAutoBtn.addEventListener('click', () => {
            sendAutoLinks()
                .then(() => clearCheckboxes())
                .catch((e) => alert(e && e.message ? e.message : 'Ошибка'));
        });
    }
    if (linkClearBtn) {
        linkClearBtn.addEventListener('click', () => {
            if (!confirm('Удалить все связи за день?')) return;
            sendClearLinks()
                .then(() => clearCheckboxes())
                .catch((e) => alert(e && e.message ? e.message : 'Ошибка'));
        });
    }
    updateLinkButtonState();

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') clearCheckboxes();
    });
})();
</script>
</body>
</html>

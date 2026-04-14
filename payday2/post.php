<?php
$action = (string)($_POST['action'] ?? '');

try {
    $api = new \App\Classes\PosterAPI((string)$token);
    $normalizePosterTx = function ($v): ?array {
        if (!is_array($v)) return null;
        if (isset($v[0]) && is_array($v[0])) return $v[0];
        return $v;
    };

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'load_poster_checks') {
        $isAjax = isset($_GET['ajax']) || (isset($_POST['ajax']) && $_POST['ajax'] === '1');
        if ($isAjax) {
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-cache');
            while (ob_get_level()) ob_end_clean();
        }
        $sendProgress = function ($pct, $step) use ($isAjax) {
            if ($isAjax) {
                echo json_encode(['pct' => $pct, 'step' => $step], JSON_UNESCAPED_UNICODE) . "\n";
                flush();
            }
        };

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
        $sendProgress(5, 'Poster API: Запрос методов оплаты (1/2)...');
        try {
            $m1 = $api->request('settings.getPaymentMethods', ['money_type' => 2, 'payment_type' => 2]);
            if (is_array($m1)) $methods = array_merge($methods, $m1);
        } catch (\Throwable $e) {
        }
        $sendProgress(10, 'Poster API: Запрос методов оплаты (2/2)...');
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
        $sendProgress(20, 'Poster API: Загрузка транзакций...');
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

        $totalTxs = count($txs);
        $sendProgress(40, 'Обработка ' . $totalTxs . ' чеков...');
        foreach ($txs as $i => $tx) {
            if ($totalTxs > 0 && $i % max(1, (int)($totalTxs / 20)) === 0) {
                $sendProgress(40 + (int)(60 * $i / $totalTxs), "Чеки: {$i} из {$totalTxs}");
            }
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
                         , was_deleted = 0, deleted_at = NULL
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
        
        if ($isAjax) {
            echo json_encode(['ok' => true, 'pct' => 100, 'step' => 'Готово', 'message' => $message], JSON_UNESCAPED_UNICODE) . "\n";
            exit;
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'load_poster_accounts') {
        $rows = $api->request('finance.getAccounts', []);
        if (!is_array($rows)) $rows = [];
        try {
            $db->query("DELETE FROM {$pa}");
        } catch (\Throwable $e) {
        }
        $upserted = 0;
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $accountId = (int)($r['account_id'] ?? $r['accountId'] ?? 0);
            $name = trim((string)($r['name'] ?? ''));
            if ($accountId <= 0 || $name === '') continue;
            $upserted += (int)$db->query(
                "INSERT INTO {$pa} (account_id, name, type, currency_id, currency_symbol, currency_code_iso, currency_code, balance, balance_start, percent_acquiring)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    name = VALUES(name),
                    type = VALUES(type),
                    currency_id = VALUES(currency_id),
                    currency_symbol = VALUES(currency_symbol),
                    currency_code_iso = VALUES(currency_code_iso),
                    currency_code = VALUES(currency_code),
                    balance = VALUES(balance),
                    balance_start = VALUES(balance_start),
                    percent_acquiring = VALUES(percent_acquiring)",
                [
                    $accountId,
                    $name,
                    (int)($r['type'] ?? 0),
                    isset($r['currency_id']) ? (int)$r['currency_id'] : null,
                    isset($r['currency_symbol']) ? (string)$r['currency_symbol'] : null,
                    isset($r['currency_code_iso']) ? (string)$r['currency_code_iso'] : null,
                    isset($r['currency_code']) ? (string)$r['currency_code'] : null,
                    $moneyToInt($r['balance'] ?? 0),
                    isset($r['balance_start']) ? $moneyToInt($r['balance_start']) : null,
                    isset($r['percent_acquiring']) ? (float)$r['percent_acquiring'] : null,
                ]
            )->rowCount();
        }
        $message = 'Баланс Poster обновлён: ' . $upserted;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'clear_day') {
        try {
            $db->query('START TRANSACTION');
            $db->query("UPDATE {$pc} SET was_deleted = 1, deleted_at = NOW() WHERE day_date BETWEEN ? AND ?", [$dateFrom, $dateTo]);
            $db->query("UPDATE {$st} SET was_deleted = 1, deleted_at = NOW() WHERE transaction_date BETWEEN ? AND ?", [$periodFrom, $periodTo]);
            $db->query('COMMIT');
            $message = ($dateFrom === $dateTo ? ('День очищен: ' . $dateFrom) : ('Период очищен: ' . $dateFrom . ' — ' . $dateTo));
        } catch (\Throwable $e) {
            try { $db->query('ROLLBACK'); } catch (\Throwable $e2) {}
            throw $e;
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'reload_sepay_api') {
        $isAjax = isset($_GET['ajax']) || (isset($_POST['ajax']) && $_POST['ajax'] === '1');
        if ($isAjax) {
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-cache');
            while (ob_get_level()) ob_end_clean();
        }
        $sendProgress = function ($pct, $step) use ($isAjax) {
            if ($isAjax) {
                echo json_encode(['pct' => $pct, 'step' => $step], JSON_UNESCAPED_UNICODE) . "\n";
                flush();
            }
        };

        if ($sepayApiToken === '') {
            throw new \Exception('Не задан SEPAY_API_TOKEN в .env');
        }

        $sendProgress(10, 'SePay: Загрузка транзакций...');
        $txs = $sepayFetchTransactions($dateFrom, $dateTo, $sepayApiToken, $sepayAccountNumber);

        $inserted = 0;
        $updated = 0;
        $skipped = 0;

        $totalTxs = count($txs);
        $sendProgress(30, 'Обработка ' . $totalTxs . ' платежей...');
        foreach ($txs as $i => $tx) {
            if ($totalTxs > 0 && $i % max(1, (int)($totalTxs / 20)) === 0) {
                $sendProgress(30 + (int)(70 * $i / $totalTxs), "Платежи: {$i} из {$totalTxs}");
            }
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
                    raw_request_body = VALUES(raw_request_body),
                    was_deleted = 0,
                    deleted_at = NULL",
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
        
        if ($isAjax) {
            echo json_encode(['ok' => true, 'pct' => 100, 'step' => 'Готово', 'message' => $message], JSON_UNESCAPED_UNICODE) . "\n";
            exit;
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create_transfer') {
        $kind = (string)($_POST['kind'] ?? '');
        if (!in_array($kind, ['vietnam', 'tips'], true)) {
            throw new \Exception('Bad request');
        }
        $amountCents = 0;
        if ($kind === 'vietnam') {
            $amountCents = (int)$db->query(
                "SELECT COALESCE(SUM(payed_card + payed_third_party + tip_sum), 0)
                 FROM {$pc}
                 WHERE day_date BETWEEN ? AND ?
                   AND pay_type IN (2,3)
                   AND (payed_card + payed_third_party) > 0
                   AND poster_payment_method_id = 11",
                [$dateFrom, $dateTo]
            )->fetchColumn();
        } else {
            $amountCents = (int)$db->query(
                "SELECT COALESCE(SUM(p.tip_sum), 0)
                 FROM {$pc} p
                 JOIN (
                    SELECT DISTINCT l.poster_transaction_id
                    FROM {$pl} l
                    JOIN {$pc} p2 ON p2.transaction_id = l.poster_transaction_id
                    WHERE p2.day_date BETWEEN ? AND ?
                      AND COALESCE(p2.was_deleted, 0) = 0
                 ) x ON x.poster_transaction_id = p.transaction_id
                 WHERE p.day_date BETWEEN ? AND ?
                   AND COALESCE(p.was_deleted, 0) = 0
                   AND p.pay_type IN (2,3)
                   AND (p.payed_card + p.payed_third_party) > 0
                   AND p.tip_sum > 0
                   AND COALESCE(p.poster_payment_method_id, 0) <> 11",
                [$dateFrom, $dateTo, $dateFrom, $dateTo]
            )->fetchColumn();
        }
        if ($amountCents <= 0) {
            throw new \Exception('Сумма для перевода = 0.');
        }
        $amountVnd = (int)$posterCentsToVnd($amountCents);
        if ($amountVnd <= 0) {
            throw new \Exception('Сумма для перевода = 0.');
        }

        $targetDate = $dateTo . ' 23:55:00';
        $targetTs = strtotime($targetDate);
        $startTs = strtotime($dateTo . ' 00:00:00');
        $endTs = strtotime($dateTo . ' 23:59:59');

        $accountTo = $kind === 'vietnam' ? 9 : 8;
        $comment = $kind === 'vietnam'
            ? 'Перевод чеков вьетнаской компании'
            : 'Перевод типсов';

        $txs = [];
        try {
            $txs = $api->request('finance.getTransactions', [
                'dateFrom' => str_replace('-', '', $dateTo),
                'dateTo' => str_replace('-', '', $dateTo),
            
                'timezone' => 'client',
            ]);
        } catch (\Throwable $e) {
            $txs = [];
        }
        if (!is_array($txs) || count($txs) === 0) {
            try {
                $txs = $api->request('finance.getTransactions', [
                    'dateFrom' => date('dmY', $startTs !== false ? $startTs : time()),
                    'dateTo' => date('dmY', $endTs !== false ? $endTs : time()),
                
                'timezone' => 'client',
            ]);
            } catch (\Throwable $e) {
                $txs = [];
            }
        }
        if (!is_array($txs)) $txs = [];

        $dup = false;
        $expectedUserId = 4;
        foreach ($txs as $row) {
            if (!is_array($row)) continue;
            $tRaw = (string)($row['type'] ?? '');
            $isTransfer = ($tRaw === '2');
            $isIn = ($tRaw === '1' || strtoupper($tRaw) === 'I' || strtolower($tRaw) === 'in');
            $isOut = ($tRaw === '0' || strtoupper($tRaw) === 'O' || strtolower($tRaw) === 'out');
            if (!$isTransfer && !$isIn && !$isOut) continue;
            if ($isTransfer) {
                $toRaw = $row['account_to_id'] ?? $row['account_to'] ?? $row['accountToId'] ?? $row['accountTo'] ?? 0;
            } else {
                $toRaw = $row['recipient_id'] ?? $row['account_to_id'] ?? $row['account_to'] ?? 0;
            }
            if (is_array($toRaw)) $toRaw = $toRaw['account_id'] ?? $toRaw['id'] ?? 0;
            $toId = (int)$toRaw;
            if ($toId !== $accountTo) continue;

            $uRaw = $row['user_id'] ?? $row['userId'] ?? $row['user_id'] ?? $row['user'] ?? $row['employee_id'] ?? null;
            if (is_array($uRaw)) $uRaw = $uRaw['user_id'] ?? $uRaw['id'] ?? $uRaw['userId'] ?? null;
            $uId = (int)($uRaw ?? 0);
            if ($uId !== 0 && $uId !== $expectedUserId) continue;

            $dRaw = $row['date'] ?? $row['created_at'] ?? $row['createdAt'] ?? $row['time'] ?? $row['datetime'] ?? $row['date_time'] ?? $row['created'] ?? null;
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
                if ($targetTs !== false && abs($ts - $targetTs) > 60) continue;
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
            'amount_from' => $amountVnd,
            'amount_to' => $amountVnd,
            'date' => $targetDate,
            'comment' => $comment,
            'account_id' => 1,
            'account_to_id' => $accountTo,
            'sum' => $amountVnd,
        ], 'POST');

        $message = 'Перевод создан.';
    }
} catch (\Throwable $e) {
    if ($error === '') $error = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== '') {
    if (!isset($_SESSION)) {
        if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    }
    $_SESSION['payday_flash'] = [
        'message' => $message,
        'error' => $error,
        'at' => time(),
    ];
    header('Location: ?' . http_build_query(['dateFrom' => $dateFrom, 'dateTo' => $dateTo]));
    exit;
}

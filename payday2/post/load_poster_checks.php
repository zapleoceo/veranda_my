<?php
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

        try { $db->query("DELETE FROM {$pt} WHERE day_date BETWEEN ? AND ?", [$dateFrom, $dateTo]); } catch (\Throwable $e) { error_log('Payday2 Error: ' . $e->getMessage()); }

        $totalTxs = count($txs);
        $sendProgress(40, 'Обработка ' . $totalTxs . ' чеков...');
        
        $batchPtParams = [];
        $batchPtPlaceholders = [];
        $batchPcUpdates = [];
        $batchPcInsertsParams = [];
        $batchPcInsertsPlaceholders = [];
        
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

            $batchPtPlaceholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            array_push($batchPtParams, 
                $txId, $dayDate, $closeAt, $payType, $sum, $payedCard, $payedThirdParty, $tipSum, $spotId, $tableId,
                $waiterName !== '' ? $waiterName : null
            );

            // Fetch detail conditionally (can be slow, but it's part of existing logic)
            $detail = null;
            try {
                $detail = $normalizePosterTx($api->request('dash.getTransaction', [
                    'transaction_id' => $txId,
                    'include_history' => 0,
                    'include_products' => 0,
                    'include_delivery' => 0,
                ]));
            } catch (\Throwable $e) {
                error_log('Payday2 Error in ' . __FILE__ . ':' . __LINE__ . ' - ' . $e->getMessage());
                $detail = null;
            }

            $pmId = 0;
            if (is_array($detail)) {
                $pmId = (int)($detail['payment_method_id'] ?? $detail['paymentMethodId'] ?? 0);
            }
            if ($pmId <= 0) {
                $pmId = (int)($tx['payment_method_id'] ?? $tx['paymentMethodId'] ?? 0);
            }

            $exists = (int)$db->query("SELECT 1 FROM {$pc} WHERE transaction_id = ? LIMIT 1", [$txId])->fetchColumn();
            if ($exists === 1) {
                $batchPcUpdates[] = [
                    $receiptNumber > 0 ? $receiptNumber : null,
                    $tableId, $spotId, $sum, $payedSum, $payedCash, $payedCard, $payedCert, $payedBonus, $payedThirdParty,
                    $payType, $reason, $tipSum, $discount, $closeAt,
                    $pmId > 0 ? $pmId : null,
                    $waiterName !== '' ? $waiterName : null, $dayDate,
                    $txId
                ];
                $updated++;
            } else {
                $batchPcInsertsPlaceholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                array_push($batchPcInsertsParams,
                    $txId,
                    $receiptNumber > 0 ? $receiptNumber : null,
                    $tableId, $spotId, $sum, $payedSum, $payedCash, $payedCard, $payedCert, $payedBonus, $payedThirdParty,
                    $payType, $reason, $tipSum, $discount, $closeAt,
                    $pmId > 0 ? $pmId : null,
                    $waiterName !== '' ? $waiterName : null, $dayDate
                );
                $inserted++;
            }
            
            // Execute batches if they get too large
            if (count($batchPtPlaceholders) >= 100) {
                try {
                    $ptSql = "INSERT INTO {$pt} (transaction_id, day_date, date_close, pay_type, sum, payed_card, payed_third_party, tip_sum, spot_id, table_id, waiter_name) VALUES " . implode(', ', $batchPtPlaceholders) . " ON DUPLICATE KEY UPDATE day_date = VALUES(day_date), date_close = VALUES(date_close), pay_type = VALUES(pay_type), sum = VALUES(sum), payed_card = VALUES(payed_card), payed_third_party = VALUES(payed_third_party), tip_sum = VALUES(tip_sum), spot_id = VALUES(spot_id), table_id = VALUES(table_id), waiter_name = VALUES(waiter_name)";
                    $db->query($ptSql, $batchPtParams);
                } catch (\Throwable $e) { error_log('Payday2 Error in ' . __FILE__ . ':' . __LINE__ . ' - ' . $e->getMessage()); }
                $batchPtPlaceholders = []; $batchPtParams = [];
            }
            if (count($batchPcInsertsPlaceholders) >= 100) {
                try {
                    $pcSql = "INSERT INTO {$pc} (transaction_id, receipt_number, table_id, spot_id, sum, payed_sum, payed_cash, payed_card, payed_cert, payed_bonus, payed_third_party, pay_type, reason, tip_sum, discount, date_close, poster_payment_method_id, waiter_name, day_date) VALUES " . implode(', ', $batchPcInsertsPlaceholders) . " ON DUPLICATE KEY UPDATE receipt_number = VALUES(receipt_number), table_id = VALUES(table_id), spot_id = VALUES(spot_id), sum = VALUES(sum), payed_sum = VALUES(payed_sum), payed_cash = VALUES(payed_cash), payed_card = VALUES(payed_card), payed_cert = VALUES(payed_cert), payed_bonus = VALUES(payed_bonus), payed_third_party = VALUES(payed_third_party), pay_type = VALUES(pay_type), reason = VALUES(reason), tip_sum = VALUES(tip_sum), discount = VALUES(discount), date_close = VALUES(date_close), poster_payment_method_id = VALUES(poster_payment_method_id), waiter_name = VALUES(waiter_name), day_date = VALUES(day_date), was_deleted = 0, deleted_at = NULL";
                    $db->query($pcSql, $batchPcInsertsParams);
                } catch (\Throwable $e) { error_log('Payday2 Error in ' . __FILE__ . ':' . __LINE__ . ' - ' . $e->getMessage()); }
                $batchPcInsertsPlaceholders = []; $batchPcInsertsParams = [];
            }
            if (count($batchPcUpdates) >= 100) {
                $stmt = $db->getPdo()->prepare("UPDATE {$pc} SET receipt_number = ?, table_id = ?, spot_id = ?, sum = ?, payed_sum = ?, payed_cash = ?, payed_card = ?, payed_cert = ?, payed_bonus = ?, payed_third_party = ?, pay_type = ?, reason = ?, tip_sum = ?, discount = ?, date_close = ?, poster_payment_method_id = ?, waiter_name = ?, day_date = ?, was_deleted = 0, deleted_at = NULL WHERE transaction_id = ?");
                foreach ($batchPcUpdates as $upd) {
                    try { $stmt->execute($upd); } catch (\Throwable $e) { error_log('Payday2 Error in ' . __FILE__ . ':' . __LINE__ . ' - ' . $e->getMessage()); }
                }
                $batchPcUpdates = [];
            }
        }
        
        // Finalize remaining batches
        if (count($batchPtPlaceholders) > 0) {
            try {
                $ptSql = "INSERT INTO {$pt} (transaction_id, day_date, date_close, pay_type, sum, payed_card, payed_third_party, tip_sum, spot_id, table_id, waiter_name) VALUES " . implode(', ', $batchPtPlaceholders) . " ON DUPLICATE KEY UPDATE day_date = VALUES(day_date), date_close = VALUES(date_close), pay_type = VALUES(pay_type), sum = VALUES(sum), payed_card = VALUES(payed_card), payed_third_party = VALUES(payed_third_party), tip_sum = VALUES(tip_sum), spot_id = VALUES(spot_id), table_id = VALUES(table_id), waiter_name = VALUES(waiter_name)";
                $db->query($ptSql, $batchPtParams);
            } catch (\Throwable $e) { error_log('Payday2 Error in ' . __FILE__ . ':' . __LINE__ . ' - ' . $e->getMessage()); }
        }
        if (count($batchPcInsertsPlaceholders) > 0) {
            try {
                $pcSql = "INSERT INTO {$pc} (transaction_id, receipt_number, table_id, spot_id, sum, payed_sum, payed_cash, payed_card, payed_cert, payed_bonus, payed_third_party, pay_type, reason, tip_sum, discount, date_close, poster_payment_method_id, waiter_name, day_date) VALUES " . implode(', ', $batchPcInsertsPlaceholders) . " ON DUPLICATE KEY UPDATE receipt_number = VALUES(receipt_number), table_id = VALUES(table_id), spot_id = VALUES(spot_id), sum = VALUES(sum), payed_sum = VALUES(payed_sum), payed_cash = VALUES(payed_cash), payed_card = VALUES(payed_card), payed_cert = VALUES(payed_cert), payed_bonus = VALUES(payed_bonus), payed_third_party = VALUES(payed_third_party), pay_type = VALUES(pay_type), reason = VALUES(reason), tip_sum = VALUES(tip_sum), discount = VALUES(discount), date_close = VALUES(date_close), poster_payment_method_id = VALUES(poster_payment_method_id), waiter_name = VALUES(waiter_name), day_date = VALUES(day_date), was_deleted = 0, deleted_at = NULL";
                $db->query($pcSql, $batchPcInsertsParams);
            } catch (\Throwable $e) { error_log('Payday2 Error in ' . __FILE__ . ':' . __LINE__ . ' - ' . $e->getMessage()); }
        }
        if (count($batchPcUpdates) > 0) {
            $stmt = $db->getPdo()->prepare("UPDATE {$pc} SET receipt_number = ?, table_id = ?, spot_id = ?, sum = ?, payed_sum = ?, payed_cash = ?, payed_card = ?, payed_cert = ?, payed_bonus = ?, payed_third_party = ?, pay_type = ?, reason = ?, tip_sum = ?, discount = ?, date_close = ?, poster_payment_method_id = ?, waiter_name = ?, day_date = ?, was_deleted = 0, deleted_at = NULL WHERE transaction_id = ?");
            foreach ($batchPcUpdates as $upd) {
                try { $stmt->execute($upd); } catch (\Throwable $e) { error_log('Payday2 Error in ' . __FILE__ . ':' . __LINE__ . ' - ' . $e->getMessage()); }
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

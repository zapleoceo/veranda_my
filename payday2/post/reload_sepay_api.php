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

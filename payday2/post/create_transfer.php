<?php
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
                   AND poster_payment_method_id = " . \App\Payday2\Config::METHOD_VIETNAM,
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
                   AND COALESCE(p.poster_payment_method_id, 0) <> " . \App\Payday2\Config::METHOD_VIETNAM,
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

        $accountTo = $kind === 'vietnam' ? \App\Payday2\Config::ACCOUNT_VIETNAM : \App\Payday2\Config::ACCOUNT_TIPS;
        $comment = $kind === 'vietnam'
            ? 'Перевод чеков вьетнаской компании'
            : 'Перевод типсов';
        $by = trim((string)($_SESSION['user_email'] ?? $_SESSION['user_name'] ?? ''));
        if ($by !== '') $comment .= ' by ' . $by;

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

<?php
require_once __DIR__ . '/../auth_check.php';

require_once __DIR__ . '/config.php';

veranda_require('payday');

if (($_GET['ajax'] ?? '') === 'poster_balances_telegram_screenshot') {
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $raw = file_get_contents('php://input');
    $payload = json_decode((string)$raw, true);
    $imgData = $payload['image'] ?? '';
    
    if (!$imgData || !preg_match('/^data:image\/(\w+);base64,/', $imgData, $type)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid image format'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $imgData = substr($imgData, strpos($imgData, ',') + 1);
    $imgData = base64_decode($imgData);
    if ($imgData === false) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'base64_decode failed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $tmpFile = tempnam(sys_get_temp_dir(), 'bal_');
    file_put_contents($tmpFile, $imgData);

    $tgToken = trim((string)($_ENV['TELEGRAM_BOT_TOKEN'] ?? $_ENV['TG_BOT_TOKEN'] ?? ''));
    $tgChatId = '-1003889942420'; // From https://t.me/c/3889942420/1736
    $threadId = '1736'; // From https://t.me/c/3889942420/1736

    file_put_contents(__DIR__ . '/telegram_debug.log', "Token length: " . strlen($tgToken) . " Chat ID: $tgChatId Thread: $threadId\n", FILE_APPEND);

    if ($tgToken === '' || $tgChatId === '') {
        @unlink($tmpFile);
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Telegram config is missing (Token or Chat ID empty)'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.telegram.org/bot{$tgToken}/sendPhoto");
    curl_setopt($ch, CURLOPT_POST, true);

    $postFields = [
        'chat_id' => $tgChatId,
        'photo' => new CURLFile($tmpFile, 'image/png', 'balance.png'),
        'caption' => "Итоговый баланс",
    ];
    if ($threadId !== '') {
        $postFields['message_thread_id'] = $threadId;
    }

    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $resp = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);
    @unlink($tmpFile);

    file_put_contents(__DIR__ . '/telegram_debug.log', "cURL Error: $curlErr\nResp: $resp\n", FILE_APPEND);
    
    if ($resp === false) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Telegram request failed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $tgData = json_decode($resp, true);
    if (!isset($tgData['ok']) || !$tgData['ok']) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Telegram API error: ' . ($tgData['description'] ?? 'Unknown')], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_GET['ajax'] ?? '') === 'create_transfer') {
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $raw = file_get_contents('php://input');
    $payload = json_decode((string)$raw, true);
    if (!is_array($payload)) $payload = [];
    $kind = (string)($payload['kind'] ?? '');
    $dFrom = trim((string)($payload['dateFrom'] ?? ''));
    $dTo = trim((string)($payload['dateTo'] ?? ''));
    if (!in_array($kind, ['vietnam', 'tips'], true) || $dFrom === '' || $dTo === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $api = new \App\Classes\PosterAPI((string)$token);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'API init error'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $amountCents = 0;
        if ($kind === 'vietnam') {
            $amountCents = (int)$db->query(
                "SELECT COALESCE(SUM(payed_card + payed_third_party + tip_sum), 0)
                 FROM {$pc}
                 WHERE day_date BETWEEN ? AND ?
                   AND pay_type IN (2,3)
                   AND (payed_card + payed_third_party) > 0
                   AND poster_payment_method_id = 11",
                [$dFrom, $dTo]
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
                [$dFrom, $dTo, $dFrom, $dTo]
            )->fetchColumn();
        }
        if ($amountCents <= 0) {
            throw new \Exception($kind === 'vietnam'
                ? 'Сумма = 0: нет чеков Vietnam Company (payment_method_id=11) за выбранный период.'
                : 'Сумма = 0: нет типсов по связанным чекам за выбранный период.'
            );
        }
        $amountVnd = (int)$posterCentsToVnd($amountCents);
        if ($amountVnd <= 0) {
            throw new \Exception('Сумма для перевода = 0.');
        }

        $targetDate = $dTo . ' 23:55:00';
        $startTs = strtotime($dTo . ' 00:00:00');
        $endTs = strtotime($dTo . ' 23:59:59');
        $windowStartTs = strtotime($dTo . ' 22:00:00');
        if ($startTs === false || $endTs === false || $windowStartTs === false) {
            throw new \Exception('Bad date');
        }

        $accountTo = $kind === 'vietnam' ? 9 : 8;
        $commentBase = $kind === 'vietnam'
            ? 'Перевод чеков вьетнаской компании'
            : 'Перевод типсов';
        $comment = $commentBase;
        $by = trim((string)($_SESSION['user_email'] ?? $_SESSION['user_name'] ?? ''));
        if ($by !== '') $comment .= ' by ' . $by;
        $expectedUserId = 4;

        $txs = [];
        try {
            $txs = $api->request('finance.getTransactions', [
                'dateFrom' => date('Ymd', $startTs),
                'dateTo' => date('Ymd', $endTs),
                'account_id' => $accountTo,
                'type' => 1,
                'timezone' => 'client',
            ]);
        } catch (\Throwable $e) {
            $txs = [];
        }
        if (!is_array($txs) || count($txs) === 0) {
            try {
                $txs = $api->request('finance.getTransactions', [
                    'dateFrom' => date('dmY', $startTs),
                    'dateTo' => date('dmY', $endTs),
                    'account_id' => $accountTo,
                    'type' => 1,
                    'timezone' => 'client',
                ]);
            } catch (\Throwable $e) {
                $txs = [];
            }
        }
        if (!is_array($txs)) $txs = [];

        $normMoney = function ($sumRaw): int {
            $sumF = 0.0;
            if (is_int($sumRaw) || is_float($sumRaw)) $sumF = (float)$sumRaw;
            else if (is_string($sumRaw)) $sumF = (float)str_replace(',', '.', str_replace(' ', '', trim($sumRaw)));
            $sumInt = (int)round($sumF);
            return ($sumInt > 200000000 && $sumInt % 100 === 0) ? (int)round($sumInt / 100) : $sumInt;
        };
        $normText = function (string $s): string {
            $t = trim($s);
            return mb_strtolower($t, 'UTF-8');
        };

        $employeesMapFinance = [];
        try {
            $employeesMapFinance = $getEmployeesById($api);
        } catch (\Throwable $e) {
        }

        $found = null;
        foreach ($txs as $row) {
            if (!is_array($row)) continue;
            $tRaw = (string)($row['type'] ?? '');
            $isTransfer = ($tRaw === '2');
            $isOut = ($tRaw === '0' || strtoupper($tRaw) === 'O' || strtolower($tRaw) === 'out');
            $isIn = ($tRaw === '1' || strtoupper($tRaw) === 'I' || strtolower($tRaw) === 'in');
            if (!$isTransfer && !$isOut && !$isIn) continue;

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
            if ($ts === null) continue;
            if ($ts < $startTs || $ts > $endTs) continue;

            $accFromId = 0;
            $accToId = 0;
            if ($isTransfer) {
                $fromRaw = $row['account_from'] ?? $row['account_from_id'] ?? $row['account_id'] ?? 0;
                if (is_array($fromRaw)) $fromRaw = $fromRaw['account_id'] ?? $fromRaw['id'] ?? 0;
                $accFromId = (int)$fromRaw;

                $toRaw = $row['account_to'] ?? $row['account_to_id'] ?? $row['recipient_id'] ?? 0;
                if (is_array($toRaw)) $toRaw = $toRaw['account_id'] ?? $toRaw['id'] ?? 0;
                $accToId = (int)$toRaw;
            } elseif ($isOut) {
                $fromRaw = $row['account_id'] ?? $row['accountId'] ?? $row['account_from_id'] ?? $row['account_from'] ?? $row['accountFromId'] ?? $row['accountFrom'] ?? 0;
                if (is_array($fromRaw)) $fromRaw = $fromRaw['account_id'] ?? $fromRaw['id'] ?? 0;
                $accFromId = (int)$fromRaw;

                $toRaw = $row['recipient_id'] ?? $row['account_to_id'] ?? $row['account_to'] ?? 0;
                if (is_array($toRaw)) $toRaw = $toRaw['account_id'] ?? $toRaw['id'] ?? 0;
                $accToId = (int)$toRaw;
            } else {
                $fromRaw = $row['account_from'] ?? $row['account_from_id'] ?? 0;
                if (is_array($fromRaw)) $fromRaw = $fromRaw['account_id'] ?? $fromRaw['id'] ?? 0;
                $accFromId = (int)$fromRaw;

                $toRaw = $row['account_id'] ?? $row['account_to_id'] ?? $row['account_to'] ?? 0;
                if (is_array($toRaw)) $toRaw = $toRaw['account_id'] ?? $toRaw['id'] ?? 0;
                $accToId = (int)$toRaw;
            }

            $sumRaw = $row['amount_from'] ?? $row['amountFrom'] ?? $row['amount_to'] ?? $row['amountTo'] ?? $row['sum'] ?? $row['amount'] ?? 0;
            $sumMaybe = $normMoney($sumRaw);
            if (abs($sumMaybe) !== $amountVnd) continue;

            $cmt = (string)($row['comment'] ?? $row['description'] ?? $row['comment_text'] ?? '');
            $cmtNorm = $normText($cmt);
            // Any type=1 transaction on the target account with the exact amount is a match
            $isMatch = true;
            if (!$isMatch) continue;

            $uRaw = $row['user_id'] ?? $row['userId'] ?? $row['user'] ?? $row['employee_id'] ?? null;
            if (is_array($uRaw)) $uRaw = $uRaw['user_id'] ?? $uRaw['id'] ?? $uRaw['userId'] ?? null;
            $uId = (int)($uRaw ?? 0);
            $userName = '';
            if ($uId > 0 && isset($employeesMapFinance[$uId])) {
                $userName = $employeesMapFinance[$uId];
            } else {
                $uObj = $row['user'] ?? $row['employee'] ?? null;
                if (is_array($uObj)) {
                    $userName = (string)($uObj['name'] ?? $uObj['user_name'] ?? $uObj['username'] ?? $uObj['title'] ?? '');
                    $userName = trim($userName);
                }
            }
            if ($userName === '' && $uId > 0) $userName = '#' . $uId;

            $found = [
                'ts' => $ts,
                'sum' => abs($sumMaybe),
                'comment' => $cmt !== '' ? $cmt : $comment,
                'user' => $userName,
            ];
            break;
        }

        if ($found !== null) {
            echo json_encode([
                'ok' => true,
                'already' => true,
                'date' => date('d.m.Y', (int)$found['ts']),
                'time' => date('H:i:s', (int)$found['ts']),
                'sum' => (int)$found['sum'],
                'user' => (string)($found['user'] ?? ''),
                'comment' => (string)$found['comment'],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $api->request('finance.createTransactions', [
            'type' => 1,
            'user_id' => $expectedUserId,
            'account_id' => $accountTo,
            'amount' => $amountVnd,
            'date' => $targetDate,
            'comment' => $comment,
            'sum' => $amountVnd,
        ], 'POST');

        echo json_encode([
            'ok' => true,
            'already' => false,
            'date' => date('d.m.Y', strtotime($targetDate) ?: time()),
            'time' => '23:55:00',
            'sum' => (int)$amountVnd,
            'user' => isset($employeesMapFinance[$expectedUserId]) ? $employeesMapFinance[$expectedUserId] : ('#' . (string)$expectedUserId),
            'comment' => $comment,
        ], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (($_GET['ajax'] ?? '') === 'refresh_finance_transfers') {
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $raw = file_get_contents('php://input');
    $payload = json_decode((string)$raw, true);
    if (!is_array($payload)) $payload = [];
    $kind = (string)($payload['kind'] ?? '');
    $dFrom = trim((string)($payload['dateFrom'] ?? ''));
    $dTo = trim((string)($payload['dateTo'] ?? ''));
    $accountFrom = (int)($payload['accountFrom'] ?? 0);
    $accountTo = (int)($payload['accountTo'] ?? 0);
    if (!in_array($kind, ['vietnam', 'tips'], true) || $dFrom === '' || $dTo === '' || $accountFrom <= 0 || $accountTo <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $api = new \App\Classes\PosterAPI((string)$token);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'API init error'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $startTs = strtotime($dFrom . ' 00:00:00');
        $endTs = strtotime($dTo . ' 23:59:59');
        if ($startTs === false || $endTs === false) {
            throw new \Exception('Bad date');
        }

                $rows = [];
        $accTarget = $kind === 'vietnam' ? 9 : 8;

        try {
            $rows = $api->request('finance.getTransactions', [
                'dateFrom' => date('Ymd', $startTs),
                'dateTo' => date('Ymd', $endTs),
                'account_id' => $accTarget,
                'type' => 1,
                'timezone' => 'client',
            ]);
        } catch (\Throwable $e) {
            $rows = [];
        }
        if (!is_array($rows) || count($rows) === 0) {
            try {
                $rows = $api->request('finance.getTransactions', [
                    'dateFrom' => date('dmY', $startTs),
                    'dateTo' => date('dmY', $endTs),
                    'account_id' => $accTarget,
                    'type' => 1,
                    'timezone' => 'client',
                ]);
            } catch (\Throwable $e) {
                $rows = [];
            }
        }
        if (!is_array($rows)) $rows = [];

        $normMoney = function ($sumRaw): int {
            $sumF = 0.0;
            if (is_int($sumRaw) || is_float($sumRaw)) $sumF = (float)$sumRaw;
            else if (is_string($sumRaw)) $sumF = (float)str_replace(',', '.', str_replace(' ', '', trim($sumRaw)));
            $sumInt = (int)round($sumF);
            return ($sumInt > 200000000 && $sumInt % 100 === 0) ? (int)round($sumInt / 100) : $sumInt;
        };
        $normText = function (string $s): string {
            $t = trim($s);
            return mb_strtolower($t, 'UTF-8');
        };

        $employeesMapFinance = [];
        try {
            $employeesMapFinance = $getEmployeesById($api);
        } catch (\Throwable $e) {
        }

        $accountsMapFinance = [];
        try {
            $accountsMapFinance = $getAccountsById($api);
        } catch (\Throwable $e) {
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            
            $tRaw = (string)($row['type'] ?? '');
            $isTransfer = ($tRaw === '2');
            $isIn = ($tRaw === '1' || strtoupper($tRaw) === 'I' || strtolower($tRaw) === 'in');
            $isOut = ($tRaw === '0' || strtoupper($tRaw) === 'O' || strtolower($tRaw) === 'out');
            if (!$isTransfer && !$isIn && !$isOut) continue;

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
            if ($ts === null) continue;
            if ($ts < $startTs || $ts > $endTs) continue;

            $accFromId = 0;
            $accToId = 0;
            if ($isTransfer) {
                $fromRaw = $row['account_from'] ?? $row['account_from_id'] ?? $row['account_id'] ?? 0;
                if (is_array($fromRaw)) $fromRaw = $fromRaw['account_id'] ?? $fromRaw['id'] ?? 0;
                $accFromId = (int)$fromRaw;

                $toRaw = $row['account_to'] ?? $row['account_to_id'] ?? $row['recipient_id'] ?? 0;
                if (is_array($toRaw)) $toRaw = $toRaw['account_id'] ?? $toRaw['id'] ?? 0;
                $accToId = (int)$toRaw;
            } elseif ($isOut) {
                $fromRaw = $row['account_id'] ?? $row['accountId'] ?? $row['account_from_id'] ?? $row['account_from'] ?? $row['accountFromId'] ?? $row['accountFrom'] ?? 0;
                if (is_array($fromRaw)) $fromRaw = $fromRaw['account_id'] ?? $fromRaw['id'] ?? 0;
                $accFromId = (int)$fromRaw;

                $toRaw = $row['recipient_id'] ?? $row['account_to_id'] ?? $row['account_to'] ?? 0;
                if (is_array($toRaw)) $toRaw = $toRaw['account_id'] ?? $toRaw['id'] ?? 0;
                $accToId = (int)$toRaw;
            } else {
                $fromRaw = $row['account_from'] ?? $row['account_from_id'] ?? 0;
                if (is_array($fromRaw)) $fromRaw = $fromRaw['account_id'] ?? $fromRaw['id'] ?? 0;
                $accFromId = (int)$fromRaw;

                $toRaw = $row['account_id'] ?? $row['account_to_id'] ?? $row['account_to'] ?? 0;
                if (is_array($toRaw)) $toRaw = $toRaw['account_id'] ?? $toRaw['id'] ?? 0;
                $accToId = (int)$toRaw;
            }

            if ($accToId !== 8 && $accToId !== 9 && $accFromId !== 8 && $accFromId !== 9) continue;
            $accId = ($accToId === 8 || $accToId === 9) ? $accToId : $accFromId;

            $cmt = (string)($row['comment'] ?? $row['description'] ?? $row['comment_text'] ?? '');
            $cmtNorm = $normText($cmt);
            
            // We explicitly requested type=1 for the target account, so any returned transaction matches the kind
            $isVietnam = ($kind === 'vietnam' && $accId === 9);
            $isTips = ($kind === 'tips' && $accId === 8);
            
            if ($kind === 'vietnam' && !$isVietnam) continue;
            if ($kind === 'tips' && !$isTips) continue;
            $sumRaw = $row['amount'] ?? $row['amount_to'] ?? $row['amount_from'] ?? $row['sum'] ?? 0;
            $sumMinor = abs($normMoney($sumRaw));
            $sum = (int)$posterCentsToVnd($sumMinor);

            $uRaw = $row['user_id'] ?? $row['userId'] ?? $row['user'] ?? $row['employee_id'] ?? null;
            if (is_array($uRaw)) $uRaw = $uRaw['user_id'] ?? $uRaw['id'] ?? $uRaw['userId'] ?? null;
            $uId = (int)($uRaw ?? 0);
            $userName = '';
            if ($uId > 0 && isset($employeesMapFinance[$uId])) {
                $userName = $employeesMapFinance[$uId];
            } else {
                $uObj = $row['user'] ?? $row['employee'] ?? null;
                if (is_array($uObj)) {
                    $userName = (string)($uObj['name'] ?? $uObj['user_name'] ?? $uObj['username'] ?? $uObj['title'] ?? '');
                    $userName = trim($userName);
                }
            }
            if ($userName === '' && $uId > 0) $userName = '#' . $uId;

            $accName = isset($accountsMapFinance[$accId]) ? $accountsMapFinance[$accId] : ('#' . $accId);

            $txId = (int)($row['transaction_id'] ?? $row['id'] ?? 0);
            $out[] = [
                'transaction_id' => $txId,
                'transfer_id' => $txId,
                'ts' => (int)$ts,
                'sum' => (int)$sum,
                'comment' => $cmt,
                'user' => $userName,
                'account' => $accName,
                'type' => $tRaw,
            ];
        }

        usort($out, function ($a, $b) {
            return ((int)($b['ts'] ?? 0)) <=> ((int)($a['ts'] ?? 0));
        });

        echo json_encode(['ok' => true, 'rows' => $out], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (($_GET['ajax'] ?? '') === 'sepay_hide') {
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $raw = file_get_contents('php://input');
    $payload = json_decode((string)$raw, true);
    if (!is_array($payload)) $payload = [];
    $sepayId = (int)($payload['sepay_id'] ?? 0);
    $comment = trim((string)($payload['comment'] ?? ''));
    if ($sepayId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($comment === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Нужен комментарий'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (mb_strlen($comment, 'UTF-8') > 2000) {
        $comment = mb_substr($comment, 0, 2000, 'UTF-8');
    }
    $by = '';
    if (!isset($_SESSION)) {
        if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    }
    $by = trim((string)($_SESSION['user_email'] ?? $_SESSION['user_name'] ?? ''));
    try {
        $db->query("DELETE FROM {$pl} WHERE sepay_id = ?", [$sepayId]);
        $db->query(
            "INSERT INTO {$sh} (sepay_id, comment, created_by)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE comment = VALUES(comment), created_by = VALUES(created_by), updated_at = CURRENT_TIMESTAMP",
            [$sepayId, $comment, $by !== '' ? $by : null]
        );
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
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
                    "INSERT INTO {$pl} (poster_transaction_id, sepay_id, link_type)
                     VALUES (?, ?, 'manual')
                     ON DUPLICATE KEY UPDATE link_type = 'manual'",
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
             WHERE p.day_date BETWEEN ? AND ?",
            [$dateFrom, $dateTo]
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
            "SELECT s.sepay_id, s.transaction_date, s.transfer_amount
             FROM {$st} s
             WHERE s.transaction_date BETWEEN ? AND ?
               AND s.transfer_type = 'in'
               AND (s.payment_method IS NULL OR s.payment_method IN ('Card','Bybit'))
               AND NOT EXISTS (SELECT 1 FROM {$sh} h WHERE h.sepay_id = s.sepay_id)
             ORDER BY s.transaction_date ASC",
            [$periodFrom, $periodTo]
        )->fetchAll();

        $linkedSepay = [];
        $linkedPoster = [];
        try {
            $existingLinks = $db->query(
                "SELECT l.poster_transaction_id, l.sepay_id
                 FROM {$pl} l
                 JOIN {$pc} p ON p.transaction_id = l.poster_transaction_id
                 WHERE p.day_date BETWEEN ? AND ?",
                [$dateFrom, $dateTo]
            )->fetchAll();
            if (!is_array($existingLinks)) $existingLinks = [];
            foreach ($existingLinks as $l) {
                $pid = (int)($l['poster_transaction_id'] ?? 0);
                $sid = (int)($l['sepay_id'] ?? 0);
                if ($pid > 0) $linkedPoster[$pid] = true;
                if ($sid > 0) $linkedSepay[$sid] = true;
            }
        } catch (\Throwable $e) {
        }

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
                    "INSERT IGNORE INTO {$pl} (poster_transaction_id, sepay_id, link_type)
                     VALUES (?, ?, 'auto_green')",
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
                    "INSERT IGNORE INTO {$pl} (poster_transaction_id, sepay_id, link_type)
                     VALUES (?, ?, 'auto_green')",
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
                    "INSERT IGNORE INTO {$pl} (poster_transaction_id, sepay_id, link_type)
                     VALUES (?, ?, 'auto_yellow')",
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
            "SELECT l.poster_transaction_id, l.sepay_id, l.link_type,
                    CASE WHEN l.link_type = 'manual' THEN 1 ELSE 0 END AS is_manual
             FROM {$pl} l
             JOIN {$pc} p ON p.transaction_id = l.poster_transaction_id
             WHERE p.day_date BETWEEN ? AND ?",
            [$dateFrom, $dateTo]
        )->fetchAll();
        echo json_encode(['ok' => true, 'links' => $rows], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (($_GET['ajax'] ?? '') === 'mail_out') {
    header('Content-Type: application/json; charset=utf-8');
    $dFrom = trim((string)($_GET['dateFrom'] ?? ''));
    $dTo = trim((string)($_GET['dateTo'] ?? ''));
    $includeHidden = (int)($_GET['include_hidden'] ?? 0) === 1;
    if ($dTo === '') {
        echo json_encode(['ok' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!function_exists('decode_imap_text')) {
        function decode_imap_text($str) {
            if (!$str) return '';
            if (!function_exists('imap_mime_header_decode')) return $str;
            $result = '';
            $decode = @imap_mime_header_decode($str);
            if (is_array($decode)) {
                foreach ($decode as $obj) {
                    $text = isset($obj->text) ? $obj->text : '';
                    $charset = isset($obj->charset) ? $obj->charset : 'default';
                    if ($charset === 'default' || $charset === 'us-ascii' || $charset === 'utf-8') {
                        $result .= $text;
                    } else {
                        $result .= @mb_convert_encoding($text, 'UTF-8', $charset) ?: $text;
                    }
                }
            } else {
                $result = $str;
            }
            return $result;
        }
    }
    if (file_exists(__DIR__ . '/../.env')) {
        $lines = file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $t = trim($line);
            if ($t === '' || $t[0] === '#' || strpos($line, '=') === false) continue;
            [$name, $value] = explode('=', $line, 2);
            $_ENV[trim($name)] = trim(trim($value), '"\'');
        }
    }
    $mailUser = $_ENV['MAIL_USER'] ?? '';
    $mailPass = $_ENV['MAIL_PASS'] ?? '';
    if (!extension_loaded('imap') || $mailUser === '' || $mailPass === '') {
        echo json_encode(['ok' => false, 'error' => 'IMAP not available'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $inbox = @imap_open('{imap.gmail.com:993/imap/ssl}INBOX', $mailUser, $mailPass);
    if (!$inbox) {
        echo json_encode(['ok' => false, 'error' => 'IMAP open failed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $fromTs = strtotime($dFrom !== '' ? ($dFrom . ' 00:00:00') : ($dTo . ' 00:00:00'));
    $toTs = strtotime($dTo . ' 23:59:59');
    
    $searchQuery = 'FROM "bidvsmartbanking@bidv.com.vn" SINCE "' . date('d-M-Y', $fromTs) . '"';
    $emails = imap_search($inbox, $searchQuery) ?: [];
    rsort($emails);
    
    $hidden = [];
    try {
        $hRows = $db->query("SELECT mail_uid, comment FROM {$mh} WHERE date_to = ?", [$dTo])->fetchAll();
        foreach ($hRows as $hr) {
            $uid = (int)($hr['mail_uid'] ?? 0);
            if ($uid > 0) $hidden[$uid] = (string)($hr['comment'] ?? '');
        }
    } catch (\Throwable $e) { error_log('Payday2 Error: ' . $e->getMessage()); }
    $rows = [];
    foreach ($emails as $num) {
        $h = @imap_headerinfo($inbox, $num);
        if (!$h) continue;
        $fromAddr = isset($h->from[0]) ? ($h->from[0]->mailbox . '@' . $h->from[0]->host) : '';
        if (strcasecmp($fromAddr, 'bidvsmartbanking@bidv.com.vn') !== 0) continue;
        $tsHeader = isset($h->udate) ? (int)$h->udate : (isset($h->date) ? strtotime($h->date) : 0);
        if ($tsHeader <= 0) $tsHeader = 0;
        $struct = @imap_fetchstructure($inbox, $num);
        $body = '';
        $encoding = 0;
        if ($struct && isset($struct->parts) && count($struct->parts)) {
            for ($i = 0, $n = count($struct->parts); $i < $n; $i++) {
                $p = $struct->parts[$i];
                if (isset($p->subtype) && strtoupper($p->subtype) === 'HTML') {
                    $body = @imap_fetchbody($inbox, $num, $i + 1);
                    $encoding = $p->encoding ?? 0;
                    break;
                }
            }
            if (!$body) {
                $body = @imap_fetchbody($inbox, $num, 1);
                $encoding = $struct->parts[0]->encoding ?? 0;
            }
        } else {
            $body = @imap_body($inbox, $num);
            $encoding = $struct->encoding ?? 0;
        }
        if ($encoding == 3) $body = base64_decode($body);
        elseif ($encoding == 4) $body = quoted_printable_decode($body);
        $src = preg_replace('/\s+/u', ' ', (string)$body);
        $timeStr = '';
        $txTs = 0;
        if (preg_match('/\b(\d{2})\/(\d{2})\/(\d{4})\s+(\d{2}):(\d{2}):(\d{2})\b/u', $src, $m)) {
            $timeStr = $m[0];
            $d = (int)$m[1]; $mo = (int)$m[2]; $y = (int)$m[3]; $hh = (int)$m[4]; $mm = (int)$m[5]; $ss = (int)$m[6];
            $txTs = mktime($hh, $mm, $ss, $mo, $d, $y);
        }
        $amtStr = '';
        if (preg_match('/([\d.,]+)\s*VND\b/ui', $src, $m)) $amtStr = $m[1];
        $amountVnd = 0;
        if ($amtStr !== '') {
            $amountVnd = (int)str_replace([',','.'], ['', ''], $amtStr);
        }
        $useTs = $txTs > 0 ? $txTs : $tsHeader;
        
        // Если письмо старше начальной даты, прекращаем перебор (т.к. письма отсортированы по убыванию)
        if ($useTs > 0 && $useTs < $fromTs) break;
        // Если письмо новее конечной даты, просто пропускаем его
        if ($useTs > 0 && $useTs > $toTs) continue;
        
        $rows[] = [
            'mail_uid' => (int)@imap_uid($inbox, $num),
            'date' => $useTs > 0 ? date('Y-m-d H:i:s', $useTs) : '',
            'tx_time' => $timeStr,
            'amount' => $amountVnd,
            'content' => decode_imap_text($h->subject ?? ''),
        ];
    }
    @imap_close($inbox);
    $rows = array_values(array_filter($rows, function ($r) {
        $uid = (int)($r['mail_uid'] ?? 0);
        return $uid > 0;
    }));
    foreach ($rows as &$r) {
        $uid = (int)($r['mail_uid'] ?? 0);
        $r['is_hidden'] = !empty($hidden[$uid]) ? 1 : 0;
        $r['hidden_comment'] = isset($hidden[$uid]) ? (string)$hidden[$uid] : '';
    }
    unset($r);
    if (!$includeHidden) {
        $rows = array_values(array_filter($rows, function ($r) {
            return empty($r['is_hidden']);
        }));
    }
    echo json_encode(['ok' => true, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_GET['ajax'] ?? '') === 'mail_hide') {
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $raw = file_get_contents('php://input');
    $j = json_decode($raw ?: '[]', true);
    if (!is_array($j)) $j = [];
    $uid = (int)($j['mail_uid'] ?? 0);
    $dTo = trim((string)($j['dateTo'] ?? ''));
    $comment = trim((string)($j['comment'] ?? ''));
    if ($uid <= 0 || $dTo === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $by = '';
    if (!isset($_SESSION)) {
        if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    }
    $by = trim((string)($_SESSION['user_email'] ?? $_SESSION['user_name'] ?? ''));
    try {
        $db->query(
            "INSERT INTO {$mh} (mail_uid, date_to, comment, created_by)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE comment = VALUES(comment), created_by = VALUES(created_by)",
            [$uid, $dTo, ($comment !== '' ? $comment : null), ($by !== '' ? $by : null)]
        );
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (($_GET['ajax'] ?? '') === 'finance_out') {
    header('Content-Type: application/json; charset=utf-8');
    $dFrom = trim((string)($_GET['dateFrom'] ?? ''));
    $dTo = trim((string)($_GET['dateTo'] ?? ''));
    if ($dTo === '') {
        echo json_encode(['ok' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $fromDate = $dFrom !== '' ? $dFrom : $dTo;

        $apiOut = new \App\Classes\PosterAPI((string)$token);
        $rows = [];
        foreach ([1, 8] as $accType) {
            try {
                $r2 = $apiOut->request('finance.getTransactions', [
                    'dateFrom' => date('Ymd', strtotime($fromDate)),
                    'dateTo' => date('Ymd', strtotime($dTo)),
                    'account_type' => $accType,
                    'timezone' => 'client',
                ]);
                if (is_array($r2)) $rows = array_merge($rows, $r2);
            } catch (\Throwable $e) {
            }
        }

        $out = [];
        $seenTx = [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $txId = (int)($r['transaction_id'] ?? 0);
            if ($txId > 0) {
                if (!empty($seenTx[$txId])) continue;
                $seenTx[$txId] = true;
            }
            $dateStr = (string)($r['date'] ?? '');
            $out[] = [
                'transaction_id' => (int)($r['transaction_id'] ?? 0),
                'user_id' => (int)($r['user_id'] ?? 0),
                'category_id' => (int)($r['category_id'] ?? 0),
                'type' => (int)($r['type'] ?? 0),
                'amount' => (int)($r['amount'] ?? 0),
                'balance' => (int)($r['balance'] ?? 0),
                'date' => $dateStr,
                'comment' => (string)($r['comment'] ?? ''),
            ];
        }
        echo json_encode(['ok' => true, 'rows' => $out], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (($_GET['ajax'] ?? '') === 'poster_employees') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $apiEmp = new \App\Classes\PosterAPI((string)$token);
        $rows = $apiEmp->request('access.getEmployees', []);
        if (!is_array($rows)) $rows = [];
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $uid = (int)($r['user_id'] ?? 0);
            $name = (string)($r['name'] ?? '');
            if ($uid > 0 && $name !== '') $out[$uid] = $name;
        }
        echo json_encode(['ok' => true, 'employees' => $out], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (($_GET['ajax'] ?? '') === 'finance_categories') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $apiCat = new \App\Classes\PosterAPI((string)$token);
        $rows = $apiCat->request('finance.getCategories', []);
        if (!is_array($rows)) $rows = [];
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $cid = (int)($r['category_id'] ?? 0);
            $name = (string)($r['name'] ?? '');
            if ($cid > 0 && $name !== '') $out[$cid] = $name;
        }
        echo json_encode(['ok' => true, 'categories' => $out], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (($_GET['ajax'] ?? '') === 'kashshift') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $apiKS = new \App\Classes\PosterAPI((string)$token);
        $dFrom = str_replace('-', '', trim((string)($_GET['dateFrom'] ?? '')));
        $dTo = str_replace('-', '', trim((string)($_GET['dateTo'] ?? '')));
        if ($dFrom === '') $dFrom = date('Ymd');
        if ($dTo === '') $dTo = date('Ymd');
        $rows = $apiKS->request('finance.getCashShifts', ['dateFrom' => $dFrom, 'dateTo' => $dTo]);
        echo json_encode(['ok' => true, 'data' => is_array($rows) ? $rows : []], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (($_GET['ajax'] ?? '') === 'kashshift_detail') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $apiKSDetail = new \App\Classes\PosterAPI((string)$token);
        $shiftId = trim((string)($_GET['shiftId'] ?? ''));
        if ($shiftId === '') throw new \Exception('No shift ID provided');
        $data = $apiKSDetail->request('finance.getCashShiftTransactions', ['shift_id' => $shiftId]);
        echo json_encode(['ok' => true, 'data' => is_array($data) ? $data : []], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (($_GET['ajax'] ?? '') === 'supplies') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $apiSup = new \App\Classes\PosterAPI((string)$token);
        $dFrom = str_replace('-', '', trim((string)($_GET['dateFrom'] ?? '')));
        $dTo = str_replace('-', '', trim((string)($_GET['dateTo'] ?? '')));
        if ($dFrom === '') $dFrom = date('Ymd');
        if ($dTo === '') $dTo = date('Ymd');
        $supplies = $apiSup->request('storage.getSupplies', ['dateFrom' => $dFrom, 'dateTo' => $dTo]);
        $accounts = $apiSup->request('finance.getAccounts', []);
        
        if (!is_array($supplies)) $supplies = [];
        if (!is_array($accounts)) $accounts = [];
        
        echo json_encode(['ok' => true, 'supplies' => $supplies, 'accounts' => $accounts], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (($_GET['ajax'] ?? '') === 'out_links') {
    header('Content-Type: application/json; charset=utf-8');
    $dTo = trim((string)($_GET['dateTo'] ?? ''));
    if ($dTo === '') {
        echo json_encode(['ok' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $rows = $db->query(
            "SELECT mail_uid, finance_id, link_type, is_manual FROM {$ol} WHERE date_to = ?",
            [$dTo]
        )->fetchAll();
        if (!is_array($rows)) $rows = [];
        $links = [];
        foreach ($rows as $r) {
            $links[] = [
                'mail_uid' => (int)($r['mail_uid'] ?? 0),
                'finance_id' => (int)($r['finance_id'] ?? 0),
                'link_type' => (string)($r['link_type'] ?? ''),
                'is_manual' => ((int)($r['is_manual'] ?? 0) === 1),
            ];
        }
        echo json_encode(['ok' => true, 'links' => $links], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (($_GET['ajax'] ?? '') === 'out_clear_links') {
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $raw = file_get_contents('php://input');
    $j = json_decode($raw ?: '[]', true);
    if (!is_array($j)) $j = [];
    $dTo = trim((string)($j['dateTo'] ?? ''));
    if ($dTo === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $cnt = $db->query("DELETE FROM {$ol} WHERE date_to = ?", [$dTo])->rowCount();
        echo json_encode(['ok' => true, 'deleted' => (int)$cnt], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (($_GET['ajax'] ?? '') === 'out_manual_link') {
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $raw = file_get_contents('php://input');
    $j = json_decode($raw ?: '[]', true);
    if (!is_array($j)) $j = [];
    $dTo = trim((string)($j['dateTo'] ?? ''));
    $pairs = is_array($j['links'] ?? null) ? $j['links'] : [];
    if ($dTo === '' || !$pairs) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $by = '';
    if (!isset($_SESSION)) {
        if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    }
    $by = trim((string)($_SESSION['user_email'] ?? $_SESSION['user_name'] ?? ''));
    try {
        $normalized = [];
        $seenPair = [];
        foreach ($pairs as $p) {
            $uid = (int)($p['mail_uid'] ?? 0);
            $fid = (int)($p['finance_id'] ?? 0);
            if ($uid <= 0 || $fid <= 0) continue;
            $k = $uid . ':' . $fid;
            if (isset($seenPair[$k])) continue;
            $seenPair[$k] = true;
            $normalized[] = [$uid, $fid];
        }
        if (!$normalized) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        foreach ($normalized as $pair) {
            $uid = (int)$pair[0];
            $fid = (int)$pair[1];
            $db->query(
                "INSERT INTO {$ol} (date_to, mail_uid, finance_id, link_type, is_manual, created_by)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE link_type = VALUES(link_type), is_manual = VALUES(is_manual), created_by = VALUES(created_by)",
                [$dTo, $uid, $fid, 'manual', 1, ($by !== '' ? $by : null)]
            );
        }
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (($_GET['ajax'] ?? '') === 'out_auto_link') {
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $raw = file_get_contents('php://input');
    $j = json_decode($raw ?: '[]', true);
    if (!is_array($j)) $j = [];
    $dTo = trim((string)($j['dateTo'] ?? ''));
    $pairs = is_array($j['links'] ?? null) ? $j['links'] : [];
    if ($dTo === '' || !$pairs) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $by = '';
    if (!isset($_SESSION)) {
        if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    }
    $by = trim((string)($_SESSION['user_email'] ?? $_SESSION['user_name'] ?? ''));
    try {
        $normalized = [];
        $seenPair = [];
        foreach ($pairs as $p) {
            $uid = (int)($p['mail_uid'] ?? 0);
            $fid = (int)($p['finance_id'] ?? 0);
            if ($uid <= 0 || $fid <= 0) continue;
            $lt = (string)($p['link_type'] ?? 'auto_green');
            if ($lt !== 'auto_green' && $lt !== 'auto_yellow') $lt = 'auto_green';
            $k = $uid . ':' . $fid;
            if (isset($seenPair[$k])) continue;
            $seenPair[$k] = true;
            $normalized[] = [$uid, $fid, $lt];
        }
        if (!$normalized) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        foreach ($normalized as $pair) {
            $uid = (int)$pair[0];
            $fid = (int)$pair[1];
            $lt = (string)$pair[2];
            $db->query(
                "INSERT INTO {$ol} (date_to, mail_uid, finance_id, link_type, is_manual, created_by)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE link_type = VALUES(link_type), is_manual = VALUES(is_manual), created_by = VALUES(created_by)",
                [$dTo, $uid, $fid, $lt, 0, ($by !== '' ? $by : null)]
            );
        }
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (($_GET['ajax'] ?? '') === 'out_unlink') {
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $raw = file_get_contents('php://input');
    $j = json_decode($raw ?: '[]', true);
    if (!is_array($j)) $j = [];
    $dTo = trim((string)($j['dateTo'] ?? ''));
    $uid = (int)($j['mail_uid'] ?? 0);
    $fid = (int)($j['finance_id'] ?? 0);
    if ($dTo === '' || $uid <= 0 || $fid <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $db->query("DELETE FROM {$ol} WHERE date_to = ? AND mail_uid = ? AND finance_id = ?", [$dTo, $uid, $fid]);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
if (($_GET['ajax'] ?? '') === 'poster_accounts') {
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $api2 = new \App\Classes\PosterAPI((string)$token);
        $rows = $api2->request('finance.getAccounts', []);
        if (!is_array($rows)) $rows = [];

        try { $db->query("DELETE FROM {$pa}"); } catch (\Throwable $e) { error_log('Payday2 Error: ' . $e->getMessage()); }

        $accounts = [];
        $byId = [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $accountId = (int)($r['account_id'] ?? $r['accountId'] ?? 0);
            $name = trim((string)($r['name'] ?? ''));
            if ($accountId <= 0 || $name === '') continue;
            $balance = $moneyToInt($r['balance'] ?? 0);
            $db->query(
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
                    $balance,
                    isset($r['balance_start']) ? $moneyToInt($r['balance_start']) : null,
                    isset($r['percent_acquiring']) ? (float)$r['percent_acquiring'] : null,
                ]
            );
            $accounts[] = [
                'account_id' => $accountId,
                'name' => $name,
                'balance_cents' => $balance,
                'balance' => $fmtVndCents($balance),
            ];
            $byId[$accountId] = $balance;
        }

        $andrey = (int)($byId[1] ?? 0) + (int)($byId[8] ?? 0);
        $vietnam = (int)($byId[9] ?? 0);
        $cash = (int)($byId[2] ?? 0);
        $total = 0;
        foreach ($byId as $b) $total += (int)$b;

        echo json_encode([
            'ok' => true,
            'accounts' => $accounts,
            'balance_andrey' => $fmtVndCents($andrey),
            'balance_andrey_cents' => $andrey,
            'balance_vietnam' => $fmtVndCents($vietnam),
            'balance_vietnam_cents' => $vietnam,
            'balance_cash' => $fmtVndCents($cash),
            'balance_cash_cents' => $cash,
            'balance_total' => $fmtVndCents($total),
            'balance_total_cents' => $total,
        ], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (($_GET['ajax'] ?? '') === 'balance_sinc_plan') {
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $raw = file_get_contents('php://input');
        $j = json_decode($raw ?: '[]', true);
        if (!is_array($j)) $j = [];
        $diffCents = (int)($j['diff_cents'] ?? 0);
        if ($diffCents === 0) {
            throw new \Exception('Разница = 0');
        }

        $type = $diffCents > 0 ? 1 : 0;
        $amount = sprintf('%.2f', abs($diffCents) / 100);
        $accountId = 8;
        $accountName = '';
        try {
            $accountName = (string)$db->query("SELECT name FROM {$pa} WHERE account_id = ? LIMIT 1", [$accountId])->fetchColumn();
        } catch (\Throwable $e) {
            $accountName = '';
        }
        if (!isset($_SESSION)) {
            if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
        }
        $nonce = bin2hex(random_bytes(16));
        $_SESSION['payday_balance_sinc'] = [
            'nonce' => $nonce,
            'diff_cents' => $diffCents,
            'created_at' => time(),
        ];

        echo json_encode([
            'ok' => true,
            'nonce' => $nonce,
            'plan' => [
                'id' => 1,
                'type' => $type,
                'category' => 4,
                'user_id' => 4,
                'date' => date('Y-m-d H:i:s'),
                'comment' => 'Коррекция излишек - недостачи за счет чая',
                'account_name' => $accountName,
                'sum' => $amount,
                'account_to' => $type === 1 ? $accountId : null,
                'account_from' => $type === 0 ? $accountId : null,
                'amount_to' => $type === 1 ? $amount : null,
                'amount_from' => $type === 0 ? $amount : null,
                'diff_cents' => $diffCents,
            ],
        ], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (($_GET['ajax'] ?? '') === 'balance_sinc_commit') {
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $raw = file_get_contents('php://input');
        $j = json_decode($raw ?: '[]', true);
        if (!is_array($j)) $j = [];
        $nonce = (string)($j['nonce'] ?? '');
        if ($nonce === '') throw new \Exception('Нет подтверждения (nonce)');

        if (!isset($_SESSION)) {
            if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
        }
        $st = $_SESSION['payday_balance_sinc'] ?? null;
        if (!is_array($st) || (string)($st['nonce'] ?? '') !== $nonce) {
            throw new \Exception('Подтверждение устарело');
        }
        $createdAt = (int)($st['created_at'] ?? 0);
        if ($createdAt <= 0 || (time() - $createdAt) > 300) {
            unset($_SESSION['payday_balance_sinc']);
            throw new \Exception('Подтверждение истекло');
        }
        $diffCents = (int)($st['diff_cents'] ?? 0);
        if ($diffCents === 0) {
            unset($_SESSION['payday_balance_sinc']);
            throw new \Exception('Разница = 0');
        }

        $type = $diffCents > 0 ? 1 : 0;
        $amount = sprintf('%.2f', abs($diffCents) / 100);
        $accountId = 8;
        $comment = 'Коррекция излишек - недостачи за счет чая';
        $by = trim((string)($_SESSION['user_email'] ?? $_SESSION['user_name'] ?? ''));
        if ($by !== '') $comment .= ' by ' . $by;
        $by = trim((string)($_SESSION['user_email'] ?? $_SESSION['user_name'] ?? ''));
        if ($by !== '') $comment .= ' by ' . $by;

        $api3 = new \App\Classes\PosterAPI((string)$token);
        try {
            $rows = $api3->request('finance.getTransactions', [
                'dateFrom' => str_replace('-', '', date('Y-m-d')),
                'dateTo' => str_replace('-', '', date('Y-m-d')),
            
                'timezone' => 'client',
            ]);
            if (is_array($rows)) {
                foreach ($rows as $r) {
                    if (!is_array($r)) continue;
                    if ((int)($r['type'] ?? 0) !== $type) continue;
                    $accToRaw = $r['account_to'] ?? $r['account_to_id'] ?? $r['accountTo'] ?? $r['accountToId'] ?? null;
                    $accFromRaw = $r['account_from'] ?? $r['account_from_id'] ?? $r['accountFrom'] ?? $r['accountFromId'] ?? null;
                    if (is_array($accToRaw)) $accToRaw = $accToRaw['account_id'] ?? $accToRaw['id'] ?? 0;
                    if (is_array($accFromRaw)) $accFromRaw = $accFromRaw['account_id'] ?? $accFromRaw['id'] ?? 0;
                    $accTo = (int)($accToRaw ?? 0);
                    $accFrom = (int)($accFromRaw ?? 0);
                    if ($type === 1 && $accTo !== $accountId) continue;
                    if ($type === 0 && $accFrom !== $accountId) continue;

                    $sum = '';
                    if ($type === 1) $sum = (string)($r['amount_to'] ?? $r['amountTo'] ?? $r['sum'] ?? $r['amount'] ?? '');
                    else $sum = (string)($r['amount_from'] ?? $r['amountFrom'] ?? $r['sum'] ?? $r['amount'] ?? '');

                    $sumCmp = trim(str_replace(',', '.', str_replace(' ', '', $sum)));
                    if ($sumCmp !== '' && (string)$sumCmp === (string)$amount) {
                        $cmt = (string)($r['comment'] ?? $r['description'] ?? '');
                        if ($cmt !== '' && mb_stripos($cmt, $comment) !== false) {
                            unset($_SESSION['payday_balance_sinc']);
                            echo json_encode(['ok' => true, 'already' => true], JSON_UNESCAPED_UNICODE);
                            exit;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
        }

        $payload = [
            'id' => 1,
            'type' => $type,
            'category' => 4,
            'user_id' => 4,
            'date' => date('Y-m-d H:i:s'),
            'comment' => $comment,
        ];
        if ($type === 1) {
            $payload['account_to'] = $accountId;
            $payload['amount_to'] = $amount;
        } else {
            $payload['account_from'] = $accountId;
            $payload['amount_from'] = $amount;
        }
        $res = $api3->request('finance.createTransactions', $payload, 'POST');

        unset($_SESSION['payday_balance_sinc']);
        echo json_encode(['ok' => true, 'response' => $res], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

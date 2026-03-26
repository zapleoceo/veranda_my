<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../src/classes/PosterAPI.php';

veranda_require('payday');
date_default_timezone_set('Asia/Ho_Chi_Minh');

$db->createPaydayTables();

$message = '';
$error = '';

if (!isset($_SESSION)) {
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
}
if (!empty($_SESSION['payday_flash']) && is_array($_SESSION['payday_flash'])) {
    $message = (string)($_SESSION['payday_flash']['message'] ?? '');
    $error = (string)($_SESSION['payday_flash']['error'] ?? '');
    unset($_SESSION['payday_flash']);
}

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

$fmtVndCents = function (int $cents): string {
    $neg = $cents < 0;
    $abs = $neg ? -$cents : $cents;
    $int = (int)floor($abs / 100);
    $frac = (int)($abs % 100);
    $intFmt = number_format($int, 0, '.', ' ');
    return ($neg ? '-' : '') . $intFmt . '.' . str_pad((string)$frac, 2, '0', STR_PAD_LEFT) . ' ₫';
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
$pa = $db->t('poster_accounts');
$pl = $db->t('check_payment_links');
$sh = $db->t('sepay_hidden');

try {
    $db->query("ALTER TABLE {$pc} ADD COLUMN was_deleted TINYINT(1) NOT NULL DEFAULT 0");
} catch (\Throwable $e) {}
try {
    $db->query("ALTER TABLE {$pc} ADD COLUMN deleted_at DATETIME NULL");
} catch (\Throwable $e) {}
try {
    $db->query("ALTER TABLE {$st} ADD COLUMN was_deleted TINYINT(1) NOT NULL DEFAULT 0");
} catch (\Throwable $e) {}
try {
    $db->query("ALTER TABLE {$st} ADD COLUMN deleted_at DATETIME NULL");
} catch (\Throwable $e) {}
try {
    $db->query(
        "CREATE TABLE IF NOT EXISTS {$sh} (
            sepay_id BIGINT NOT NULL,
            comment TEXT NULL,
            created_by VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (sepay_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
    );
} catch (\Throwable $e) {
}

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
        if ($sepayApiToken === '') {
            throw new \Exception('Не задан SEPAY_API_TOKEN в .env');
        }

        $txs = $sepayFetchTransactions($dateFrom, $dateTo, $sepayApiToken, $sepayAccountNumber);

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
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create_transfer') {
        $kind = (string)($_POST['kind'] ?? '');
        if (!in_array($kind, ['vietnam', 'tips'], true)) {
            throw new \Exception('Bad request');
        }
        $amountCents = 0;
        if ($kind === 'vietnam') {
            $amountCents = (int)$db->query(
                "SELECT COALESCE(SUM(payed_card + payed_third_party), 0)
                 FROM {$pc}
                 WHERE day_date BETWEEN ? AND ?
                   AND pay_type IN (2,3)
                   AND (payed_card + payed_third_party) > 0
                   AND poster_payment_method_id = 11",
                [$dateFrom, $dateTo]
            )->fetchColumn();
        } else {
            $amountCents = (int)$db->query(
                "SELECT COALESCE(SUM(tip_sum), 0)
                 FROM {$pc}
                 WHERE day_date BETWEEN ? AND ?
                   AND pay_type IN (2,3)
                   AND (payed_card + payed_third_party) > 0
                   AND tip_sum > 0",
                [$dateFrom, $dateTo]
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
            ]);
        } catch (\Throwable $e) {
            $txs = [];
        }
        if (!is_array($txs) || count($txs) === 0) {
            try {
                $txs = $api->request('finance.getTransactions', [
                    'dateFrom' => date('dmY', $startTs !== false ? $startTs : time()),
                    'dateTo' => date('dmY', $endTs !== false ? $endTs : time()),
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
            $type = (int)($row['type'] ?? 0);
            if ($type !== 2) continue;
            $toRaw = $row['account_to_id'] ?? $row['account_to'] ?? $row['accountToId'] ?? $row['accountTo'] ?? 0;
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
        $amountCents = 0;
        if ($kind === 'vietnam') {
            $amountCents = (int)$db->query(
                "SELECT COALESCE(SUM(payed_card + payed_third_party), 0)
                 FROM {$pc}
                 WHERE day_date BETWEEN ? AND ?
                   AND pay_type IN (2,3)
                   AND (payed_card + payed_third_party) > 0
                   AND poster_payment_method_id = 11",
                [$dFrom, $dTo]
            )->fetchColumn();
        } else {
            $amountCents = (int)$db->query(
                "SELECT COALESCE(SUM(tip_sum), 0)
                 FROM {$pc}
                 WHERE day_date BETWEEN ? AND ?
                   AND pay_type IN (2,3)
                   AND (payed_card + payed_third_party) > 0
                   AND tip_sum > 0",
                [$dFrom, $dTo]
            )->fetchColumn();
        }
        if ($amountCents <= 0) {
            throw new \Exception($kind === 'vietnam'
                ? 'Сумма = 0: нет чеков Vietnam Company (payment_method_id=11) за период.'
                : 'Сумма = 0: нет tip_sum за период.'
            );
        }
        $amountVnd = (int)$posterCentsToVnd($amountCents);
        if ($amountVnd <= 0) {
            throw new \Exception('Сумма для перевода = 0.');
        }

        $targetDate = $dTo . ' 23:55:00';
        $targetTs = strtotime($targetDate);
        $startTs = strtotime($dTo . ' 00:00:00');
        $endTs = strtotime($dTo . ' 23:59:59');
        if ($targetTs === false || $startTs === false || $endTs === false) {
            throw new \Exception('Bad date');
        }

        $accountTo = $kind === 'vietnam' ? 9 : 8;
        $comment = $kind === 'vietnam'
            ? 'Перевод чеков вьетнаской компании'
            : 'Перевод типсов';
        $expectedUserId = 4;

        $txs = [];
        try {
            $txs = $api->request('finance.getTransactions', [
                'dateFrom' => str_replace('-', '', $dTo),
                'dateTo' => str_replace('-', '', $dTo),
            ]);
        } catch (\Throwable $e) {
            $txs = [];
        }
        if (!is_array($txs) || count($txs) === 0) {
            try {
                $txs = $api->request('finance.getTransactions', [
                    'dateFrom' => date('dmY', $startTs),
                    'dateTo' => date('dmY', $endTs),
                ]);
            } catch (\Throwable $e) {
                $txs = [];
            }
        }
        if (!is_array($txs)) $txs = [];

        $found = null;
        foreach ($txs as $row) {
            if (!is_array($row)) continue;
            if ((int)($row['type'] ?? 0) !== 2) continue;
            $toRaw = $row['account_to_id'] ?? $row['account_to'] ?? $row['accountToId'] ?? $row['accountTo'] ?? 0;
            if (is_array($toRaw)) $toRaw = $toRaw['account_id'] ?? $toRaw['id'] ?? 0;
            if ((int)$toRaw !== $accountTo) continue;

            $uRaw = $row['user_id'] ?? $row['userId'] ?? $row['user'] ?? $row['employee_id'] ?? null;
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
            if ($ts === null) continue;
            if ($ts < $startTs || $ts > $endTs) continue;
            if (abs($ts - $targetTs) > 60) continue;

            $sumRaw = $row['amount_from'] ?? $row['amountFrom'] ?? $row['amount_to'] ?? $row['amountTo'] ?? $row['sum'] ?? $row['amount'] ?? 0;
            $sumF = 0.0;
            if (is_int($sumRaw) || is_float($sumRaw)) $sumF = (float)$sumRaw;
            else if (is_string($sumRaw)) $sumF = (float)str_replace(',', '.', str_replace(' ', '', trim($sumRaw)));
            $sumInt = (int)round($sumF);
            $sumMaybe = ($sumInt > 200000000 && $sumInt % 100 === 0) ? (int)round($sumInt / 100) : $sumInt;

            $cmt = (string)($row['comment'] ?? $row['description'] ?? $row['comment_text'] ?? '');
            $found = [
                'ts' => $ts,
                'sum' => $sumMaybe,
                'comment' => $cmt !== '' ? $cmt : $comment,
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
                'comment' => (string)$found['comment'],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $api->request('finance.createTransactions', [
            'type' => 2,
            'user_id' => $expectedUserId,
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

        echo json_encode([
            'ok' => true,
            'already' => false,
            'date' => date('d.m.Y', (int)$targetTs),
            'time' => date('H:i:s', (int)$targetTs),
            'sum' => (int)$amountVnd,
            'comment' => $comment,
        ], JSON_UNESCAPED_UNICODE);
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

        try { $db->query("DELETE FROM {$pa}"); } catch (\Throwable $e) {}

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

        $api3 = new \App\Classes\PosterAPI((string)$token);
        try {
            $rows = $api3->request('finance.getTransactions', [
                'dateFrom' => str_replace('-', '', date('Y-m-d')),
                'dateTo' => str_replace('-', '', date('Y-m-d')),
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

$sepayRows = $db->query(
    "SELECT s.sepay_id, s.transaction_date, s.transfer_amount, s.payment_method, s.content, s.reference_code
     FROM {$st} s
     WHERE s.transaction_date BETWEEN ? AND ?
       AND s.transfer_type = 'in'
       AND (s.payment_method IS NULL OR s.payment_method IN ('Card','Bybit'))
       AND COALESCE(s.was_deleted, 0) = 0
       AND NOT EXISTS (SELECT 1 FROM {$sh} h WHERE h.sepay_id = s.sepay_id)
     ORDER BY s.transaction_date ASC",
    [$periodFrom, $periodTo]
)->fetchAll();

$posterRows = $db->query(
    "SELECT p.transaction_id, p.receipt_number, p.date_close, p.payed_card, p.payed_third_party, p.tip_sum,
            pm.title AS payment_method_display,
            p.waiter_name, p.table_id, p.poster_payment_method_id
     FROM {$pc} p
     LEFT JOIN {$ppm} pm ON pm.payment_method_id = p.poster_payment_method_id
     WHERE p.day_date BETWEEN ? AND ?
       AND COALESCE(p.was_deleted, 0) = 0
       AND p.pay_type IN (2,3)
       AND (p.payed_card + p.payed_third_party) > 0
     ORDER BY date_close ASC",
    [$dateFrom, $dateTo]
)->fetchAll();

$sepayTotalVnd = 0;
$posterTotalVnd = 0;
$posterBybitVnd = 0;
$posterVietVnd = 0;
try {
    $sepayTotalVnd = (int)$db->query(
        "SELECT COALESCE(SUM(s.transfer_amount), 0)
         FROM {$st} s
         WHERE s.transaction_date BETWEEN ? AND ?
           AND s.transfer_type = 'in'
           AND (s.payment_method IS NULL OR s.payment_method IN ('Card','Bybit'))
           AND COALESCE(s.was_deleted, 0) = 0
           AND NOT EXISTS (SELECT 1 FROM {$sh} h WHERE h.sepay_id = s.sepay_id)",
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
           AND COALESCE(p.was_deleted, 0) = 0
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
           AND COALESCE(was_deleted, 0) = 0
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
           AND COALESCE(was_deleted, 0) = 0
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
    "SELECT l.poster_transaction_id, l.sepay_id, l.link_type,
            CASE WHEN l.link_type = 'manual' THEN 1 ELSE 0 END AS is_manual
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
        "SELECT COUNT(*) AS c
         FROM {$st} s
         WHERE s.transaction_date BETWEEN ? AND ?
           AND s.transfer_type = 'in'
           AND (s.payment_method IS NULL OR s.payment_method IN ('Card','Bybit'))
           AND NOT EXISTS (SELECT 1 FROM {$sh} h WHERE h.sepay_id = s.sepay_id)",
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

$transferVietnamExists = false;
$transferTipsExists = false;
$transferVietnamFound = null;
$transferTipsFound = null;
try {
    $targetTs = strtotime($dateTo . ' 23:55:00');
    $startTs = strtotime($dateTo . ' 00:00:00');
    $endTs = strtotime($dateTo . ' 23:59:59');
    if ($targetTs !== false && $startTs !== false && $endTs !== false) {
        $apiFinance = new \App\Classes\PosterAPI((string)$token);
        $rows = [];
        try {
            $rows = $apiFinance->request('finance.getTransactions', [
                'dateFrom' => str_replace('-', '', $dateTo),
                'dateTo' => str_replace('-', '', $dateTo),
            ]);
        } catch (\Throwable $e) {
            $rows = [];
        }
        if (!is_array($rows) || count($rows) === 0) {
            try {
                $rows = $apiFinance->request('finance.getTransactions', [
                    'dateFrom' => date('dmY', $startTs),
                    'dateTo' => date('dmY', $endTs),
                ]);
            } catch (\Throwable $e) {
                $rows = [];
            }
        }
        if (!is_array($rows)) $rows = [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            if ((int)($r['type'] ?? 0) !== 2) continue;
            $accToRaw = $r['account_to_id'] ?? $r['account_to'] ?? $r['accountToId'] ?? $r['accountTo'] ?? 0;
            if (is_array($accToRaw)) $accToRaw = $accToRaw['account_id'] ?? $accToRaw['id'] ?? 0;
            $accTo = (int)$accToRaw;
            if ($accTo !== 9 && $accTo !== 8) continue;

            $uRaw = $r['user_id'] ?? $r['userId'] ?? $r['user'] ?? $r['employee_id'] ?? null;
            if (is_array($uRaw)) $uRaw = $uRaw['user_id'] ?? $uRaw['id'] ?? $uRaw['userId'] ?? null;
            $uId = (int)($uRaw ?? 0);
            if ($uId !== 0 && $uId !== 4) continue;

            $dRaw = $r['date'] ?? $r['created_at'] ?? $r['createdAt'] ?? $r['time'] ?? $r['datetime'] ?? $r['date_time'] ?? $r['created'] ?? null;
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
            if (abs($ts - $targetTs) > 60) continue;

            $sumRaw = $r['amount_from'] ?? $r['amountFrom'] ?? $r['amount_to'] ?? $r['amountTo'] ?? $r['sum'] ?? $r['amount'] ?? 0;
            $sumF = 0.0;
            if (is_int($sumRaw) || is_float($sumRaw)) $sumF = (float)$sumRaw;
            else if (is_string($sumRaw)) $sumF = (float)str_replace(',', '.', str_replace(' ', '', trim($sumRaw)));
            $sumInt = (int)round($sumF);
            $sumMaybe = ($sumInt > 200000000 && $sumInt % 100 === 0) ? (int)round($sumInt / 100) : $sumInt;
            $cmt = (string)($r['comment'] ?? $r['description'] ?? $r['comment_text'] ?? '');

            if (!$transferVietnamExists && $accTo === 9) {
                $transferVietnamExists = true;
                $transferVietnamFound = ['ts' => $ts, 'sum' => $sumMaybe, 'comment' => $cmt];
            }
            if (!$transferTipsExists && $accTo === 8) {
                $transferTipsExists = true;
                $transferTipsFound = ['ts' => $ts, 'sum' => $sumMaybe, 'comment' => $cmt];
            }
        }
    }
} catch (\Throwable $e) {
}

$posterAccounts = [];
$posterAccountsById = [];
try {
    $posterAccounts = $db->query(
        "SELECT account_id, name, type, balance, currency_symbol
         FROM {$pa}
         ORDER BY account_id ASC"
    )->fetchAll();
    foreach ($posterAccounts as $r) {
        $id = (int)($r['account_id'] ?? 0);
        if ($id > 0) $posterAccountsById[$id] = $r;
    }
} catch (\Throwable $e) {
    $posterAccounts = [];
    $posterAccountsById = [];
}

$posterBalanceAndrey = null;
$posterBalanceVietnam = null;
$posterBalanceCash = null;
$posterBalanceTotal = null;
if (isset($posterAccountsById[1]) || isset($posterAccountsById[8])) {
    $posterBalanceAndrey = (int)($posterAccountsById[1]['balance'] ?? 0) + (int)($posterAccountsById[8]['balance'] ?? 0);
}
if (isset($posterAccountsById[9])) {
    $posterBalanceVietnam = (int)($posterAccountsById[9]['balance'] ?? 0);
}
if (isset($posterAccountsById[2])) {
    $posterBalanceCash = (int)($posterAccountsById[2]['balance'] ?? 0);
}
if (count($posterAccountsById) > 0) {
    $sum = 0;
    foreach ($posterAccountsById as $r) {
        $sum += (int)($r['balance'] ?? 0);
    }
    $posterBalanceTotal = $sum;
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
    <script src="/assets/app.js" defer></script>
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
        .toggle-wrap { display:flex; align-items:center; gap: 8px; font-weight: 900; font-size: 12px; color:#374151; }
        .toggle-wrap .toggle-text { user-select:none; }
        .switch { position: relative; display:inline-block; width: 52px; height: 28px; flex: 0 0 auto; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position:absolute; cursor:pointer; top:0; left:0; right:0; bottom:0; background:#d1d5db; transition: 180ms; border-radius: 999px; }
        .slider:before { position:absolute; content:""; height: 22px; width: 22px; left: 3px; bottom: 3px; background: #fff; transition: 180ms; border-radius: 999px; box-shadow: 0 1px 2px rgba(0,0,0,0.2); }
        .switch input:checked + .slider { background:#1a73e8; }
        .switch input:checked + .slider:before { transform: translateX(24px); }
        .card { background: #fff; border: 1px solid #e0e0e0; border-radius: 14px; padding: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .grid { display:grid; grid-template-columns: 1fr 104px 1fr; gap: 12px; align-items:start; }
        
        #tablesRoot { position: relative; overflow: hidden; }
        #lineLayer { position:absolute; inset:0; pointer-events:none; overflow:hidden; z-index: 2; grid-column: 1 / -1; grid-row: 1 / -1; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px; border-bottom: 1px solid #e0e0e0; vertical-align: top; }
        th { background: #f8f9fa; color: #65676b; font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em; }
        #sepayTable th, #sepayTable td,
        #posterTable th, #posterTable td { padding: 6px 8px; vertical-align: middle; }
        #sepayTable td, #posterTable td { font-size: 13px; line-height: 1.2; }
        #sepayTable .col-sepay-cb, #sepayTable .col-sepay-dot { width: 1%; padding-left: 6px; padding-right: 6px; text-align: center; }
        #sepayTable .col-sepay-dot { padding-left: 4px; padding-right: 4px; }
        #sepayTable .col-sepay-content { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 320px; }
        #posterTable td:first-child, #posterTable th:first-child { width: 1%; padding-left: 6px; padding-right: 6px; }
        #posterTable .cell-anchor { gap: 6px; }
        #posterTable .cell-anchor input[type="checkbox"] { width: 14px; height: 14px; }
        #posterTable .col-poster-method,
        #posterTable .col-poster-waiter { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 160px; }
        @media (max-width: 1050px) {
            #sepayTable td, #posterTable td { font-size: 12px; }
            #sepayTable th, #posterTable th { font-size: 11px; }
        }
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
        .sepay-hide { width: 14px; height: 14px; border-radius: 999px; border: 1px solid #d0d5dd; background: #fff; color: #111827; display:inline-flex; align-items:center; justify-content:center; font-weight: 900; font-size: 12px; line-height: 1; cursor: pointer; padding: 0; margin-right: 6px; }
        .sepay-hide:hover { background: #f3f4f6; }
        .cell-anchor { display:flex; align-items:center; gap: 8px; }
        .cell-anchor input[type="checkbox"] { width: 16px; height: 16px; }
        .mid-col { display:flex; flex-direction: column; align-items:center; justify-content:flex-start; gap: 10px; padding: 10px 8px 12px; margin-top: 6px; border-radius: 14px; background: rgba(255,255,255,0.30); backdrop-filter: blur(2px); border: 1px solid rgba(208,213,221,0.6); position: relative; z-index: 3; }
        .mid-btn { width: 44px; height: 44px; border-radius: 14px; border: 1px solid #d0d5dd; background: #fff; font-weight: 900; cursor: pointer; display:flex; align-items:center; justify-content:center; position: relative; overflow: hidden; }
        .mid-btn.primary { background: #1a73e8; border-color: #1a73e8; color: #fff; }
        .mid-btn.active { background: #111827; border-color: #111827; color: #fff; }
        .mid-btn:disabled { opacity: 0.5; cursor: default; }
        .mid-check { display:flex; gap: 8px; align-items:center; font-weight: 800; font-size: 12px; color: #374151; user-select: none; }
        @media (max-width: 1050px) {
            .grid { grid-template-columns: 1fr 70px 1fr; }
            .mid-col { padding-top: 10px; gap: 6px; }
            .mid-btn { width: 22px; height: 22px; border-radius: 8px; font-size: 14px; }
            .mid-legend { display: none; }
            #sepayTable th.col-sepay-sum, #sepayTable td.col-sepay-sum { width: 80px; max-width: 80px; }
        }
        .bottom-two { display:flex; gap: 12px; align-items:flex-start; flex-wrap: wrap; }
        .bottom-two > .card { flex: 1 1 420px; }
        .bottom-two > .card.card-finance { flex: 0.7 1 360px; }
        .bottom-two > .card.card-balances { flex: 1 1 560px; }
        .btn.tiny { padding: 6px 10px; border-radius: 10px; font-weight: 900; font-size: 11px; }
        .bal-grid { width: 100%; border: 1px solid #e5e7eb; border-radius: 10px; overflow:hidden; background:#fff; }
        .bal-grid table { width:100%; border-collapse: collapse; }
        .bal-grid th, .bal-grid td { padding: 6px 8px; border-bottom: 1px solid #eef2f7; vertical-align: middle; font-size: 12px; }
        .bal-grid th { background:#f9fafb; text-transform:none; letter-spacing: 0; }
        .bal-grid input { width: 140px; max-width: 100%; padding: 6px 8px; border: 1px solid #d0d5dd; border-radius: 10px; font-weight: 900; font-size: 12px; }
        .bal-diff-pos { color:#16a34a; font-weight: 900; }
        .bal-diff-neg { color:#dc2626; font-weight: 900; }
        body.mode-lite .col-sepay-content { display: none; }
        body.mode-lite .col-poster-num,
        body.mode-lite .col-poster-card,
        body.mode-lite .col-poster-tips,
        body.mode-lite .col-poster-waiter,
        body.mode-lite .col-poster-table { display: none; }
        .pm-lite { display: none; }
        body.mode-lite .pm-full { display: none; }
        body.mode-lite .pm-lite { display: inline; }
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

            <div class="toggle-wrap" title="Lite/Full">
                <span class="toggle-text">Lite</span>
                <label class="switch">
                    <input id="modeToggle" type="checkbox">
                    <span class="slider"></span>
                </label>
                <span class="toggle-text">Full</span>
            </div>
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
                                <th class="sortable col-sepay-content" data-sort-key="content">Content</th>
                                <th class="nowrap sortable col-sepay-time" data-sort-key="ts">Время</th>
                                <th class="nowrap sortable col-sepay-sum" data-sort-key="sum">Сумма</th>
                                <th></th>
                                <th></th>
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
                                <td class="col-sepay-content"><?= htmlspecialchars((string)($r['content'] ?? '')) ?></td>
                                <td class="nowrap col-sepay-time"><?= date('H:i:s', strtotime($r['transaction_date'])) ?></td>
                                <td class="sum col-sepay-sum"><?= htmlspecialchars($fmtVnd((int)$r['transfer_amount'])) ?></td>
                                <td class="col-sepay-cb"><input type="checkbox" class="sepay-cb" data-id="<?= $sid ?>"></td>
                                <td class="nowrap col-sepay-dot"><button type="button" class="sepay-hide" data-sepay-id="<?= $sid ?>" title="Скрыть (не чек)">−</button><span class="anchor" id="sepay-<?= $sid ?>"></span></td>
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
                    <div>←</div>
                    <div id="selSepaySum">0 ₫</div>
                    <div style="height: 10px;"></div>
                    <div>→</div>
                    <div id="selPosterSum">0 ₫</div>
                    <div style="height: 10px;"></div>
                    <div id="selMatch" style="font-size: 16px;">❗</div>
                    <div id="selDiff" style="font-weight: 900;">0 ₫</div>
                </div>
                <div class="muted mid-legend" style="text-align:center; font-weight:900; line-height: 1.35;">
                    <div><span style="display:inline-block; width:18px; height:3px; border-radius:999px; background:#2e7d32; vertical-align:middle; margin-right:6px;"></span>Авто 1</div>
                    <div><span style="display:inline-block; width:18px; height:3px; border-radius:999px; background:#f6c026; vertical-align:middle; margin-right:6px;"></span>Авто 2</div>
                    <div><span style="display:inline-block; width:18px; height:3px; border-radius:999px; background:#6b7280; vertical-align:middle; margin-right:6px;"></span>Ручная связь</div>
                </div>
                <div class="muted" style="text-align:center; font-weight:900; margin-top: 6px;">
                    <span id="totalsDiff">—</span>
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
                                <th class="nowrap sortable col-poster-num" data-sort-key="num">№</th>
                                <th class="nowrap sortable col-poster-time" data-sort-key="ts">Время</th>
                                <th class="nowrap sortable col-poster-card" data-sort-key="card">Card</th>
                                <th class="nowrap sortable col-poster-tips" data-sort-key="tips">Tips</th>
                                <th class="nowrap sortable col-poster-total" data-sort-key="total">Card+Tips</th>
                                <th class="sortable col-poster-method" data-sort-key="method">Метод</th>
                                <th class="sortable col-poster-waiter" data-sort-key="waiter">Официант</th>
                                <th class="nowrap sortable col-poster-table" data-sort-key="table">Стол</th>
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
                                $pmFull = $pm !== '' ? $pm : '—';
                                $pmLite = $pmFull;
                                if (stripos($pmFull, 'vietnam') !== false) $pmLite = 'VC';
                                else if (stripos($pmFull, 'bybit') !== false) $pmLite = 'BB';
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
                                <td class="nowrap col-poster-num"><?= htmlspecialchars((string)$receiptNumber) ?></td>
                                <td class="nowrap col-poster-time"><?= date('H:i:s', strtotime($r['date_close'])) ?></td>
                                <td class="sum col-poster-card"><?= htmlspecialchars($fmtVnd($cardVnd)) ?></td>
                                <td class="sum col-poster-tips"><?= htmlspecialchars($fmtVnd($tipVnd)) ?></td>
                                <td class="sum col-poster-total"><?= htmlspecialchars($fmtVnd($cardVnd + $tipVnd)) ?></td>
                                <td class="nowrap col-poster-method"><span class="pm-full"><?= htmlspecialchars($pmFull) ?></span><span class="pm-lite"><?= htmlspecialchars($pmLite) ?></span></td>
                                <td class="col-poster-waiter"><?= htmlspecialchars((string)($r['waiter_name'] ?? '')) ?></td>
                                <td class="nowrap col-poster-table"><?= htmlspecialchars((string)($r['table_id'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="muted" style="padding: 10px 12px; font-weight: 900;">
                    Итого: <span id="posterTotal"><?= htmlspecialchars($fmtVnd((int)$posterTotalVnd)) ?></span>
                    • Tips: <span id="posterTipsLinked">—</span>
                    • в таблице связи: <span id="posterLinked">—</span>
                    • несвязи: <span id="posterUnlinked">—</span>
                    • BB: <span><?= htmlspecialchars($fmtVnd((int)$posterBybitVnd)) ?></span>
                    • VC: <span><?= htmlspecialchars($fmtVnd((int)$posterVietVnd)) ?></span>
                </div>
            </div>
        </div>

        <div class="divider"></div>

        <div class="bottom-two">
        <div class="card card-finance" style="background:#fbfbfd;">
            <div style="font-weight: 900; margin-bottom: 10px;">Финансовые транзакции</div>

            <?php
            $vietnamCents = $financeVietnamCents;
            $tipsCents = $financeTipsCents;
            $vietnamVnd = $vietnamCents !== null ? $posterCentsToVnd((int)$vietnamCents) : null;
            $tipsVnd = $tipsCents !== null ? $posterCentsToVnd((int)$tipsCents) : null;
            $vietnamDisabled = $transferVietnamExists || $vietnamCents === null || (int)$vietnamCents <= 0;
            $tipsDisabled = $transferTipsExists || $tipsCents === null || (int)$tipsCents <= 0;
            $vietnamFoundLine = null;
            if ($transferVietnamFound !== null && isset($transferVietnamFound['ts'])) {
                $ts = (int)$transferVietnamFound['ts'];
                $sum = (int)($transferVietnamFound['sum'] ?? 0);
                $cmt = trim((string)($transferVietnamFound['comment'] ?? ''));
                if ($cmt === '') $cmt = 'Перевод чеков вьетнаской компании';
                $vietnamFoundLine = date('d.m.Y', $ts) . ' - ' . date('H:i:s', $ts) . ' - ' . $fmtVnd($sum) . ' - ' . $cmt;
            }
            $tipsFoundLine = null;
            if ($transferTipsFound !== null && isset($transferTipsFound['ts'])) {
                $ts = (int)$transferTipsFound['ts'];
                $sum = (int)($transferTipsFound['sum'] ?? 0);
                $cmt = trim((string)($transferTipsFound['comment'] ?? ''));
                if ($cmt === '') $cmt = 'Перевод типсов';
                $tipsFoundLine = date('d.m.Y', $ts) . ' - ' . date('H:i:s', $ts) . ' - ' . $fmtVnd($sum) . ' - ' . $cmt;
            }
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
                <form method="POST" class="finance-transfer" data-kind="vietnam" data-date-from="<?= htmlspecialchars($dateFrom) ?>" data-date-to="<?= htmlspecialchars($dateTo) ?>" style="display:flex; flex-direction:column; align-items:flex-end; gap: 4px;">
                    <input type="hidden" name="action" value="create_transfer">
                    <input type="hidden" name="kind" value="vietnam">
                    <input type="hidden" name="dateFrom" value="<?= htmlspecialchars($dateFrom) ?>">
                    <input type="hidden" name="dateTo" value="<?= htmlspecialchars($dateTo) ?>">
                    <button class="btn" type="submit" <?= $vietnamDisabled ? 'disabled' : '' ?>>Создать перевод</button>
                    <div class="muted finance-status">
                        <?php if ($transferVietnamExists): ?>
                            <span style="color:#16a34a; font-weight:900;">Найдена транзакция:</span>
                            <span><?= htmlspecialchars((string)($vietnamFoundLine ?? '')) ?></span>
                        <?php elseif ($vietnamDisabled): ?>
                            <?= htmlspecialchars($vietnamDisabledReason) ?>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <div class="finance-row">
                <div class="finance-left">
                    <div style="font-weight:900;">Card tips per shift</div>
                    <div class="muted"><?= $tipsVnd !== null ? htmlspecialchars($fmtVnd($tipsVnd)) : '—' ?></div>
                </div>
                <form method="POST" class="finance-transfer" data-kind="tips" data-date-from="<?= htmlspecialchars($dateFrom) ?>" data-date-to="<?= htmlspecialchars($dateTo) ?>" style="display:flex; flex-direction:column; align-items:flex-end; gap: 4px;">
                    <input type="hidden" name="action" value="create_transfer">
                    <input type="hidden" name="kind" value="tips">
                    <input type="hidden" name="dateFrom" value="<?= htmlspecialchars($dateFrom) ?>">
                    <input type="hidden" name="dateTo" value="<?= htmlspecialchars($dateTo) ?>">
                    <button class="btn" type="submit" <?= $tipsDisabled ? 'disabled' : '' ?>>Создать перевод</button>
                    <div class="muted finance-status">
                        <?php if ($transferTipsExists): ?>
                            <span style="color:#16a34a; font-weight:900;">Найдена транзакция:</span>
                            <span><?= htmlspecialchars((string)($tipsFoundLine ?? '')) ?></span>
                        <?php elseif ($tipsDisabled): ?>
                            <?= htmlspecialchars($tipsDisabledReason) ?>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
        <div class="card card-balances" style="background:#fbfbfd;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; gap: 10px; margin-bottom: 10px;">
                <div style="font-weight: 900;">Обновляем Балансы Poster</div>
                <div style="display:flex; gap: 8px; align-items:center;">
                    <button class="btn tiny" id="balanceSyncBtn" type="button" title="UPLD">UPLD</button>
                    <button class="btn" id="posterAccountsBtn" type="button" title="Обновить балансы">🔄</button>
                </div>
            </div>

            <div class="bal-grid" style="margin-bottom: 10px;">
                <table>
                    <thead>
                    <tr>
                        <th style="text-align:left;">Показатель</th>
                        <th style="text-align:right;">Poster</th>
                        <th style="text-align:right;">Факт.</th>
                        <th style="text-align:right;">Разница</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr data-key="andrey">
                        <td style="font-weight:900;">Баланс Счета Андрей</td>
                        <td style="text-align:right;">
                            <span id="balAndrey" data-cents="<?= $posterBalanceAndrey !== null ? (int)$posterBalanceAndrey : '' ?>"><?= $posterBalanceAndrey !== null ? htmlspecialchars($fmtVndCents((int)$posterBalanceAndrey)) : '—' ?></span>
                        </td>
                        <td style="text-align:right;"><input id="balAndreyActual" type="text" inputmode="decimal" placeholder="0.00" style="text-align:right;"></td>
                        <td style="text-align:right;"><span id="balAndreyDiff">—</span></td>
                    </tr>
                    <tr data-key="vietnam">
                        <td style="font-weight:900;">Баланс вьетнамской компании</td>
                        <td style="text-align:right;">
                            <span id="balVietnam" data-cents="<?= $posterBalanceVietnam !== null ? (int)$posterBalanceVietnam : '' ?>"><?= $posterBalanceVietnam !== null ? htmlspecialchars($fmtVndCents((int)$posterBalanceVietnam)) : '—' ?></span>
                        </td>
                        <td style="text-align:right;"><input id="balVietnamActual" type="text" inputmode="decimal" placeholder="0.00" style="text-align:right;"></td>
                        <td style="text-align:right;"><span id="balVietnamDiff">—</span></td>
                    </tr>
                    <tr data-key="cash">
                        <td style="font-weight:900;">Баланс кассы</td>
                        <td style="text-align:right;">
                            <span id="balCash" data-cents="<?= $posterBalanceCash !== null ? (int)$posterBalanceCash : '' ?>"><?= $posterBalanceCash !== null ? htmlspecialchars($fmtVndCents((int)$posterBalanceCash)) : '—' ?></span>
                        </td>
                        <td style="text-align:right;"><input id="balCashActual" type="text" inputmode="decimal" placeholder="0.00" style="text-align:right;"></td>
                        <td style="text-align:right;"><span id="balCashDiff">—</span></td>
                    </tr>
                    <tr data-key="total">
                        <td style="font-weight:900;">Total</td>
                        <td style="text-align:right;">
                            <span id="balTotal" data-cents="<?= $posterBalanceTotal !== null ? (int)$posterBalanceTotal : '' ?>"><?= $posterBalanceTotal !== null ? htmlspecialchars($fmtVndCents((int)$posterBalanceTotal)) : '—' ?></span>
                        </td>
                        <td style="text-align:right;"><input id="balTotalActual" type="text" inputmode="decimal" placeholder="0.00" style="text-align:right;" readonly></td>
                        <td style="text-align:right;"><span id="balTotalDiff">—</span></td>
                    </tr>
                    </tbody>
                </table>
            </div>

            <div style="max-height: 260px; overflow:auto; border: 1px solid #e5e7eb; border-radius: 10px; background: #fff;">
                <table style="width:100%; border-collapse: collapse;">
                    <thead>
                    <tr style="background:#f9fafb;">
                        <th style="text-align:left; padding: 8px 10px; font-weight: 900;">ID</th>
                        <th style="text-align:left; padding: 8px 10px; font-weight: 900;">Счёт</th>
                        <th style="text-align:right; padding: 8px 10px; font-weight: 900;">Баланс</th>
                    </tr>
                    </thead>
                    <tbody id="posterAccountsTbody">
                    <?php foreach ($posterAccounts as $a): ?>
                        <?php
                        $aid = (int)($a['account_id'] ?? 0);
                        $an = (string)($a['name'] ?? '');
                        $bal = (int)($a['balance'] ?? 0);
                        ?>
                        <tr>
                            <td style="padding: 8px 10px;"><?= htmlspecialchars((string)$aid) ?></td>
                            <td style="padding: 8px 10px;"><?= htmlspecialchars($an) ?></td>
                            <td style="padding: 8px 10px; text-align:right;"><?= htmlspecialchars($fmtVndCents($bal)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (count($posterAccounts) === 0): ?>
                        <tr><td colspan="3" style="padding: 10px; color:#6b7280; font-weight:900;">Нет данных: нажми 🔄</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
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

    const escapeHtml = (s) => String(s)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');

    const modeToggleEl = document.getElementById('modeToggle');
    const applyMode = (mode) => {
        const m = (mode === 'lite') ? 'lite' : 'full';
        document.body.classList.toggle('mode-lite', m === 'lite');
        if (modeToggleEl) modeToggleEl.checked = (m === 'full');
        try { localStorage.setItem('payday_mode', m); } catch (_) {}
    };

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

    const posterAccountsBtn = document.getElementById('posterAccountsBtn');
    const posterAccountsTbody = document.getElementById('posterAccountsTbody');
    const balAndreyEl = document.getElementById('balAndrey');
    const balVietnamEl = document.getElementById('balVietnam');
    const balCashEl = document.getElementById('balCash');
    const balTotalEl = document.getElementById('balTotal');
    const balanceSyncBtn = document.getElementById('balanceSyncBtn');
    const balAndreyActualEl = document.getElementById('balAndreyActual');
    const balVietnamActualEl = document.getElementById('balVietnamActual');
    const balCashActualEl = document.getElementById('balCashActual');
    const balTotalActualEl = document.getElementById('balTotalActual');
    const balAndreyDiffEl = document.getElementById('balAndreyDiff');
    const balVietnamDiffEl = document.getElementById('balVietnamDiff');
    const balCashDiffEl = document.getElementById('balCashDiff');
    const balTotalDiffEl = document.getElementById('balTotalDiff');

    const fmtIntSpaces = (n) => String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    const fmtVndCentsJs = (cents) => {
        const c = Number(cents || 0) || 0;
        const neg = c < 0;
        const abs = Math.abs(Math.trunc(c));
        const i = Math.floor(abs / 100);
        const f = abs % 100;
        return (neg ? '-' : '') + fmtIntSpaces(i) + '.' + String(f).padStart(2, '0') + ' ₫';
    };
    const parseVndCentsJs = (raw) => {
        const s = String(raw || '').trim();
        if (!s) return null;
        const cleaned = s.replace(/[^\d.,-]/g, '').replaceAll(' ', '').replaceAll(',', '.').trim();
        if (!cleaned) return null;
        const n = Number(cleaned);
        if (!Number.isFinite(n)) return null;
        return Math.round(n * 100);
    };
    const digitsOnly = (s) => String(s || '').replace(/\D+/g, '');
    const sanitizeInputDigits = (el) => {
        if (!el) return;
        const v = digitsOnly(el.value);
        el.value = v;
    };
    const updateTotalActual = () => {
        const a = Number(digitsOnly(balAndreyActualEl ? balAndreyActualEl.value : '')) || 0;
        const v = Number(digitsOnly(balVietnamActualEl ? balVietnamActualEl.value : '')) || 0;
        const c = Number(digitsOnly(balCashActualEl ? balCashActualEl.value : '')) || 0;
        const sum = a + v + c;
        if (balTotalActualEl) balTotalActualEl.value = String(sum);
    };

    const setDiff = (el, diffCents) => {
        if (!el) return;
        el.classList.remove('bal-diff-pos', 'bal-diff-neg');
        if (diffCents === null) {
            el.textContent = '—';
            return;
        }
        const d = Number(diffCents) || 0;
        if (d > 0) {
            el.classList.add('bal-diff-pos');
            el.textContent = '+' + fmtVndCentsJs(d);
        } else if (d < 0) {
            el.classList.add('bal-diff-neg');
            el.textContent = fmtVndCentsJs(d);
        } else {
            el.textContent = fmtVndCentsJs(0);
        }
    };

    const updateBalanceDiffs = () => {
        const expAndrey = balAndreyEl ? parseInt(balAndreyEl.getAttribute('data-cents') || '', 10) : NaN;
        const expVietnam = balVietnamEl ? parseInt(balVietnamEl.getAttribute('data-cents') || '', 10) : NaN;
        const expCash = balCashEl ? parseInt(balCashEl.getAttribute('data-cents') || '', 10) : NaN;
        const expTotal = balTotalEl ? parseInt(balTotalEl.getAttribute('data-cents') || '', 10) : NaN;

        const factAndrey = balAndreyActualEl ? parseVndCentsJs(balAndreyActualEl.value) : null;
        const factVietnam = balVietnamActualEl ? parseVndCentsJs(balVietnamActualEl.value) : null;
        const factCash = balCashActualEl ? parseVndCentsJs(balCashActualEl.value) : null;
        const factTotal = balTotalActualEl ? parseVndCentsJs(balTotalActualEl.value) : null;

        setDiff(balAndreyDiffEl, Number.isFinite(expAndrey) && factAndrey !== null ? (factAndrey - expAndrey) : null);
        setDiff(balVietnamDiffEl, Number.isFinite(expVietnam) && factVietnam !== null ? (factVietnam - expVietnam) : null);
        setDiff(balCashDiffEl, Number.isFinite(expCash) && factCash !== null ? (factCash - expCash) : null);
        setDiff(balTotalDiffEl, Number.isFinite(expTotal) && factTotal !== null ? (factTotal - expTotal) : null);

        try {
            if (balAndreyActualEl) localStorage.setItem('payday_bal_andrey', balAndreyActualEl.value || '');
            if (balVietnamActualEl) localStorage.setItem('payday_bal_vietnam', balVietnamActualEl.value || '');
            if (balCashActualEl) localStorage.setItem('payday_bal_cash', balCashActualEl.value || '');
            if (balTotalActualEl) localStorage.setItem('payday_bal_total', balTotalActualEl.value || '');
        } catch (_) {}
    };

    const refreshPosterAccounts = () => {
        const url = <?= json_encode('?' . http_build_query(['dateFrom' => $dateFrom, 'dateTo' => $dateTo, 'ajax' => 'poster_accounts'])) ?>;
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({}),
        })
        .then((r) => r.json())
        .then((j) => {
            if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
            if (balAndreyEl) { balAndreyEl.textContent = String(j.balance_andrey || '—'); if ('balance_andrey_cents' in j) balAndreyEl.setAttribute('data-cents', String(j.balance_andrey_cents)); }
            if (balVietnamEl) { balVietnamEl.textContent = String(j.balance_vietnam || '—'); if ('balance_vietnam_cents' in j) balVietnamEl.setAttribute('data-cents', String(j.balance_vietnam_cents)); }
            if (balCashEl) { balCashEl.textContent = String(j.balance_cash || '—'); if ('balance_cash_cents' in j) balCashEl.setAttribute('data-cents', String(j.balance_cash_cents)); }
            if (balTotalEl) { balTotalEl.textContent = String(j.balance_total || '—'); if ('balance_total_cents' in j) balTotalEl.setAttribute('data-cents', String(j.balance_total_cents)); }

            if (posterAccountsTbody) {
                const rows = Array.isArray(j.accounts) ? j.accounts : [];
                if (rows.length === 0) {
                    posterAccountsTbody.innerHTML = '<tr><td colspan="3" style="padding: 10px; color:#6b7280; font-weight:900;">Нет данных</td></tr>';
                } else {
                    posterAccountsTbody.innerHTML = rows.map((a) => {
                        const id = Number(a.account_id || 0);
                        const name = String(a.name || '');
                        const bal = String(a.balance || '0 ₫');
                        return `<tr>
                            <td style="padding: 8px 10px;">${String(id)}</td>
                            <td style="padding: 8px 10px;">${escapeHtml(name)}</td>
                            <td style="padding: 8px 10px; text-align:right;">${escapeHtml(bal)}</td>
                        </tr>`;
                    }).join('');
                }
            }
            updateBalanceDiffs();
        });
    };

    if (posterAccountsBtn) {
        posterAccountsBtn.addEventListener('click', () => {
            posterAccountsBtn.classList.add('loading');
            posterAccountsBtn.disabled = true;
            refreshPosterAccounts()
                .catch((e) => alert(e && e.message ? e.message : 'Ошибка'))
                .finally(() => {
                    posterAccountsBtn.classList.remove('loading');
                    posterAccountsBtn.disabled = false;
                });
        });
    }

    try {
        if (balAndreyActualEl) balAndreyActualEl.value = localStorage.getItem('payday_bal_andrey') || '';
        if (balVietnamActualEl) balVietnamActualEl.value = localStorage.getItem('payday_bal_vietnam') || '';
        if (balCashActualEl) balCashActualEl.value = localStorage.getItem('payday_bal_cash') || '';
        if (balTotalActualEl) balTotalActualEl.value = localStorage.getItem('payday_bal_total') || '';
    } catch (_) {}
    sanitizeInputDigits(balAndreyActualEl);
    sanitizeInputDigits(balVietnamActualEl);
    sanitizeInputDigits(balCashActualEl);
    updateTotalActual();
    updateBalanceDiffs();

    [balAndreyActualEl, balVietnamActualEl, balCashActualEl].forEach((el) => {
        if (!el) return;
        el.addEventListener('input', () => {
            sanitizeInputDigits(el);
            updateTotalActual();
            updateBalanceDiffs();
        }, { passive: true });
    });
    if (balTotalActualEl) {
        balTotalActualEl.addEventListener('input', () => {
            sanitizeInputDigits(balTotalActualEl);
            updateBalanceDiffs();
        }, { passive: true });
    }

    if (balanceSyncBtn) {
        balanceSyncBtn.addEventListener('click', () => {
            const exp = balAndreyEl ? parseInt(balAndreyEl.getAttribute('data-cents') || '', 10) : NaN;
            const fact = balAndreyActualEl ? parseVndCentsJs(balAndreyActualEl.value) : null;
            if (!Number.isFinite(exp)) return alert('Нет баланса Poster по Счету Андрей');
            if (fact === null) return alert('Заполни фактический баланс (Счет Андрей)');
            const diff = fact - exp;
            if (!diff) return alert('Разница = 0');

            balanceSyncBtn.classList.add('loading');
            balanceSyncBtn.disabled = true;
            const urlPlan = <?= json_encode('?' . http_build_query(['dateFrom' => $dateFrom, 'dateTo' => $dateTo, 'ajax' => 'balance_sinc_plan'])) ?>;
            const urlCommit = <?= json_encode('?' . http_build_query(['dateFrom' => $dateFrom, 'dateTo' => $dateTo, 'ajax' => 'balance_sinc_commit'])) ?>;
            fetch(urlPlan, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ diff_cents: diff }),
            })
            .then((r) => r.json())
            .then((j) => {
                if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
                const p = j.plan || {};
                const sum = String(p.sum || '');
                const accId = Number(p.account_to || p.account_from || 0);
                const accName = String(p.account_name || '');
                const type = Number(p.type || 0);
                const action = type === 1 ? 'Начислить' : 'Списать';
                const accLabel = accName ? `счёт ${accId} (${accName})` : `счёт ${accId}`;
                const ok = confirm(`${action} ${sum} ₫ на ${accLabel}?\nКомментарий: ${String(p.comment || '')}`);
                if (!ok) return null;

                const nonce = String(j.nonce || '');
                if (!nonce) throw new Error('Нет подтверждения (nonce)');
                return fetch(urlCommit, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ nonce }),
                }).then((r) => r.json());
            })
            .then((j) => {
                if (j === null) return null;
                if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
                return refreshPosterAccounts();
            })
            .catch((e) => alert(e && e.message ? e.message : 'Ошибка'))
            .finally(() => {
                balanceSyncBtn.classList.remove('loading');
                balanceSyncBtn.disabled = false;
            });
        });
    }

    let initialMode = 'full';
    try { initialMode = localStorage.getItem('payday_mode') || 'full'; } catch (_) {}
    if (!initialMode || initialMode === 'full') {
        try {
            const mq = window.matchMedia('(max-width: 1050px)');
            if (mq && mq.matches) initialMode = 'lite';
        } catch (_) {}
    }
    applyMode(initialMode);

    if (modeToggleEl) {
        modeToggleEl.addEventListener('change', () => {
            const next = modeToggleEl.checked ? 'full' : 'lite';
            applyMode(next);
            try { window.dispatchEvent(new Event('resize')); } catch (_) {}
            setTimeout(() => { try { window.dispatchEvent(new Event('resize')); } catch (_) {} }, 200);
        });
    }

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
        const x = (r.left + r.width / 2) - rootRect.left;
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
        const sepaySumById = new Map();
        document.querySelectorAll('#sepayTable tbody tr[data-sepay-id]').forEach((tr) => {
            const sid = Number(tr.getAttribute('data-sepay-id') || 0);
            const sum = Number(tr.getAttribute('data-sum') || 0) || 0;
            if (sid > 0) sepaySumById.set(sid, sum);
            sepayTotal += sum;
            if (state.sepay.has(sid)) sepayLinked += sum;
            else sepayUnlinked += sum;
        });

        let posterTotal = 0;
        let posterLinked = 0;
        let posterUnlinked = 0;
        let posterTipsLinked = 0;
        const posterVietnam = new Set();
        document.querySelectorAll('#posterTable tbody tr[data-poster-id]').forEach((tr) => {
            const isVietnam = String(tr.getAttribute('data-vietnam') || '0') === '1';
            const pid = Number(tr.getAttribute('data-poster-id') || 0);
            const sum = Number(tr.getAttribute('data-total') || 0) || 0;
            const tips = Number(tr.getAttribute('data-tips') || 0) || 0;
            if (isVietnam) {
                if (pid > 0) posterVietnam.add(pid);
                return;
            }
            posterTotal += sum;
            if (state.poster.has(pid)) {
                posterLinked += sum;
                posterTipsLinked += tips;
            } else {
                posterUnlinked += sum;
            }
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
        setText('posterTipsLinked', posterTipsLinked);
        setText('posterLinked', posterLinked);
        setText('posterUnlinked', posterUnlinked);

        const totalsDiffEl = document.getElementById('totalsDiff');
        if (totalsDiffEl) {
            let vcSepaySum = 0;
            if (Array.isArray(links) && posterVietnam.size > 0 && sepaySumById.size > 0) {
                const vcSepayIds = new Set();
                for (const l of links) {
                    const pid = Number(l.poster_transaction_id || 0);
                    if (!pid || !posterVietnam.has(pid)) continue;
                    const sid = Number(l.sepay_id || 0);
                    if (sid > 0) vcSepayIds.add(sid);
                }
                for (const sid of vcSepayIds) {
                    vcSepaySum += Number(sepaySumById.get(sid) || 0);
                }
            }
            const sepayNoVc = sepayTotal - vcSepaySum;
            const diff = sepayNoVc - posterTotal;
            const arrow = diff > 0 ? '←' : (diff < 0 ? '→' : '↔');
            totalsDiffEl.textContent = `${arrow} ${fmtVnd(Math.abs(diff))}`;
        }
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
                const dxBtn = b.x - a.x;
                const dyBtn = b.y - a.y;
                const lenBtn = Math.hypot(dxBtn, dyBtn) || 1;
                const insetPx = 6;
                const tBtn = Math.min(0.99, Math.max(0.75, 1 - (insetPx / lenBtn)));
                const mx = a.x + dxBtn * tBtn;
                const my = a.y + dyBtn * tBtn;
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

    let relayoutRaf = 0;
    const scheduleRelayout = () => {
        if (relayoutRaf) return;
        relayoutRaf = requestAnimationFrame(() => {
            relayoutRaf = 0;
            positionLines();
            positionWidgets();
        });
    };
    const scheduleRelayoutBurst = () => {
        scheduleRelayout();
        setTimeout(scheduleRelayout, 50);
        setTimeout(scheduleRelayout, 200);
        setTimeout(scheduleRelayout, 600);
    };

    if (tablesRoot) {
        tablesRoot.addEventListener('scroll', () => scheduleRelayout(), { passive: true, capture: true });
    }
    if (sepayScroll) {
        sepayScroll.addEventListener('scroll', () => scheduleRelayout(), { passive: true });
    }
    if (posterScroll) {
        posterScroll.addEventListener('scroll', () => scheduleRelayout(), { passive: true });
    }
    window.addEventListener('resize', () => scheduleRelayoutBurst(), { passive: true });
    window.addEventListener('pageshow', () => scheduleRelayoutBurst(), { passive: true });
    try {
        if (window.visualViewport) {
            window.visualViewport.addEventListener('resize', () => scheduleRelayoutBurst(), { passive: true });
            window.visualViewport.addEventListener('scroll', () => scheduleRelayout(), { passive: true });
        }
    } catch (_) {}

    try {
        if (typeof ResizeObserver !== 'undefined') {
            const ro = new ResizeObserver(() => scheduleRelayoutBurst());
            if (tablesRoot) ro.observe(tablesRoot);
            if (sepayScroll) ro.observe(sepayScroll);
            if (posterScroll) ro.observe(posterScroll);
        }
    } catch (_) {}

    window.addEventListener('load', () => {
        drawLines();
        applyRowClasses();
        updateStats();
        applyHideLinked();
        scheduleRelayoutBurst();
    });
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            drawLines();
            applyRowClasses();
            updateStats();
            applyHideLinked();
            scheduleRelayoutBurst();
        });
    } else {
        drawLines();
        applyRowClasses();
        updateStats();
        applyHideLinked();
        scheduleRelayoutBurst();
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
    const selMatchEl = document.getElementById('selMatch');
    const selDiffEl = document.getElementById('selDiff');

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
        if (selDiffEl) {
            const diff = pSum - sSum;
            selDiffEl.textContent = fmtVnd(Math.abs(diff));
        }
        if (selMatchEl) {
            const ok = sSum === pSum;
            selMatchEl.textContent = ok ? '✅' : '❗';
            selMatchEl.style.color = ok ? '#16a34a' : '#dc2626';
        }
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
    document.querySelectorAll('button.sepay-hide').forEach((btn) => {
        btn.addEventListener('click', () => {
            const sepayId = Number(btn.getAttribute('data-sepay-id') || 0);
            if (!sepayId) return;
            const comment = prompt('Комментарий (почему скрываем этот платеж):', '');
            if (comment === null) return;
            const c = String(comment || '').trim();
            if (!c) return;
            fetch('?ajax=sepay_hide', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ sepay_id: sepayId, comment: c }),
            })
            .then((r) => r.json())
            .then((j) => {
                if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
                const tr = btn.closest('tr');
                if (tr) tr.remove();
                selectedSepay.delete(sepayId);
                updateStats();
                applyHideLinked();
                drawLines();
                try { scheduleRelayoutBurst(); } catch (e) {}
            })
            .catch((e) => alert(e && e.message ? e.message : 'Ошибка'));
        });
    });
    document.querySelectorAll('form.finance-transfer').forEach((form) => {
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            const btn = form.querySelector('button.btn');
            const statusEl = form.querySelector('.finance-status');
            if (btn && btn.disabled) return;
            const kind = String(form.getAttribute('data-kind') || '');
            const dateFrom = String(form.getAttribute('data-date-from') || '');
            const dateTo = String(form.getAttribute('data-date-to') || '');
            if (!kind || !dateFrom || !dateTo) return;
            if (btn) {
                btn.classList.add('loading');
                btn.disabled = true;
            }
            fetch('?ajax=create_transfer', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ kind, dateFrom, dateTo }),
            })
            .then((r) => r.json())
            .then((j) => {
                if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
                const line = `${j.date || ''} - ${j.time || ''} - ${Number(j.sum || 0).toLocaleString('en-US')} ₫ - ${j.comment || ''}`.trim();
                if (statusEl) {
                    const label = j.already ? 'Найдена транзакция:' : 'Транзакция создана:';
                    statusEl.innerHTML = `<span style="color:#16a34a; font-weight:900;">${label}</span> <span>${line}</span>`;
                }
                if (btn) {
                    btn.classList.remove('loading');
                    btn.disabled = true;
                }
            })
            .catch((err) => {
                const msg = err && err.message ? err.message : 'Ошибка';
                if (statusEl) statusEl.textContent = msg;
                if (btn) {
                    btn.classList.remove('loading');
                    btn.disabled = false;
                }
            });
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

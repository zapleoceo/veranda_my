<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../src/classes/PosterAPI.php';

veranda_require('payday');
$apiTzName = trim((string)($_ENV['POSTER_SPOT_TIMEZONE'] ?? 'Asia/Ho_Chi_Minh'));
if ($apiTzName === '') $apiTzName = 'Asia/Ho_Chi_Minh';
date_default_timezone_set($apiTzName);

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
    $int = (int)round($abs / 100);
    $intFmt = number_format($int, 0, '.', "\u{202F}");
    return ($neg && $int > 0 ? '-' : '') . $intFmt;
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
$pfh = $db->t('poster_finance_hidden');
$mh = $db->t('mail_hidden');
$ol = $db->t('out_links');

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
try {
    $db->query(
        "CREATE TABLE IF NOT EXISTS {$pfh} (
            id BIGINT NOT NULL AUTO_INCREMENT,
            date_to DATE NOT NULL,
            kind VARCHAR(32) NOT NULL,
            transfer_id BIGINT NULL,
            tx_id BIGINT NULL,
            comment TEXT NULL,
            created_by VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_date_kind (date_to, kind),
            KEY idx_transfer (transfer_id),
            KEY idx_tx (tx_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
    );
} catch (\Throwable $e) {
}
try {
    $db->query(
        "CREATE TABLE IF NOT EXISTS {$mh} (
            id BIGINT NOT NULL AUTO_INCREMENT,
            mail_uid BIGINT NOT NULL,
            date_to DATE NOT NULL,
            comment TEXT NULL,
            created_by VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_mail_date (mail_uid, date_to),
            KEY idx_date (date_to)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
    );
} catch (\Throwable $e) {
}
try {
    $db->query(
        "CREATE TABLE IF NOT EXISTS {$ol} (
            id BIGINT NOT NULL AUTO_INCREMENT,
            date_to DATE NOT NULL,
            mail_uid BIGINT NOT NULL,
            finance_id BIGINT NOT NULL,
            link_type VARCHAR(32) NOT NULL,
            is_manual TINYINT(1) NOT NULL DEFAULT 0,
            created_by VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_pair_date (date_to, mail_uid, finance_id),
            KEY idx_date (date_to),
            KEY idx_mail (mail_uid),
            KEY idx_fin (finance_id)
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
            
                'timezone' => $apiTzName,]);
        } catch (\Throwable $e) {
            $txs = [];
        }
        if (!is_array($txs) || count($txs) === 0) {
            try {
                $txs = $api->request('finance.getTransactions', [
                    'dateFrom' => date('dmY', $startTs !== false ? $startTs : time()),
                    'dateTo' => date('dmY', $endTs !== false ? $endTs : time()),
                
                'timezone' => $apiTzName,]);
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
            $isOut = ($tRaw === '0' || strtoupper($tRaw) === 'O' || strtolower($tRaw) === 'out');
            if (!$isTransfer && !$isOut) continue;
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
        $comment = $kind === 'vietnam'
            ? 'Перевод чеков вьетнаской компании'
            : 'Перевод типсов';
        $by = trim((string)($_SESSION['user_email'] ?? $_SESSION['user_name'] ?? ''));
        if ($by !== '') $comment .= ' by ' . $by;
        $expectedUserId = 4;

        $txs = [];
        try {
            $txs = $api->request('finance.getTransactions', [
                'dateFrom' => date('dmY', $startTs),
                'dateTo' => date('dmY', $endTs),
            
                'timezone' => $apiTzName,]);
        } catch (\Throwable $e) {
            $txs = [];
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

        $found = null;
        foreach ($txs as $row) {
            if (!is_array($row)) continue;
            $tRaw = (string)($row['type'] ?? '');
            $isOut = ($tRaw === '0' || strtoupper($tRaw) === 'O' || strtolower($tRaw) === 'out');
            $isIn = ($tRaw === '1' || strtoupper($tRaw) === 'I' || strtolower($tRaw) === 'in');
            if (!$isOut && !$isIn) continue;
            $type = $isOut ? 0 : 1;

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

            $accRaw = $row['account_id'] ?? $row['accountId'] ?? $row['account_from_id'] ?? $row['account_from'] ?? $row['accountFromId'] ?? $row['accountFrom'] ?? 0;
            if (is_array($accRaw)) $accRaw = $accRaw['account_id'] ?? $accRaw['id'] ?? 0;
            $accId = (int)$accRaw;
            
            $toRaw = $row['recipient_id'] ?? $row['account_to_id'] ?? $row['account_to'] ?? 0;
            if (is_array($toRaw)) $toRaw = $toRaw['account_id'] ?? $toRaw['id'] ?? 0;
            $toId = (int)$toRaw;

            $sumRaw = $row['amount_from'] ?? $row['amountFrom'] ?? $row['amount_to'] ?? $row['amountTo'] ?? $row['sum'] ?? $row['amount'] ?? 0;
            $sumMaybe = $normMoney($sumRaw);
            if (abs($sumMaybe) !== $amountVnd) continue;

            $cmt = (string)($row['comment'] ?? $row['description'] ?? $row['comment_text'] ?? '');
            if ($normText($cmt !== '' ? $cmt : $comment) !== $normText($comment)) continue;

            $isMatch = false;
            if ($type === 0 && $accId === 1 && $toId === $accountTo) $isMatch = true;
            if ($type === 1 && $sumMaybe > 0 && $accId === $accountTo) $isMatch = true;
            if (!$isMatch) continue;

            $uRaw = $row['user_id'] ?? $row['userId'] ?? $row['user'] ?? $row['employee_id'] ?? null;
            if (is_array($uRaw)) $uRaw = $uRaw['user_id'] ?? $uRaw['id'] ?? $uRaw['userId'] ?? null;
            $uId = (int)($uRaw ?? 0);
            $userName = '';
            $uObj = $row['user'] ?? $row['employee'] ?? null;
            if (is_array($uObj)) {
                $userName = (string)($uObj['name'] ?? $uObj['user_name'] ?? $uObj['username'] ?? $uObj['title'] ?? '');
                $userName = trim($userName);
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
            'date' => date('d.m.Y', strtotime($targetDate) ?: time()),
            'time' => '23:55:00',
            'sum' => (int)$amountVnd,
            'user' => '#' . (string)$expectedUserId,
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
        $startTs = strtotime($dFrom . ' 00:00:00');
        $endTs = strtotime($dTo . ' 23:59:59');
        if ($startTs === false || $endTs === false) {
            throw new \Exception('Bad date');
        }

        $rows = [];
        try {
            $rows = $api->request('finance.getTransactions', [
                'dateFrom' => date('dmY', $startTs),
                'dateTo' => date('dmY', $endTs),
            
                'timezone' => $apiTzName,]);
        } catch (\Throwable $e) {
            $rows = [];
        }
        if (!is_array($rows)) $rows = [];

        $normMoney = function ($sumRaw): int {
            $sumF = 0.0;
            if (is_int($sumRaw) || is_float($sumRaw)) $sumF = (float)$sumRaw;
            else if (is_string($sumRaw)) $sumF = (float)str_replace(',', '.', str_replace(' ', '', trim($sumRaw)));
            $sumInt = (int)round($sumF);
            return ($sumInt > 200000000 && $sumInt % 100 === 0) ? (int)round($sumInt / 100) : $sumInt;
        };

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            if (((int)($row['status'] ?? 0)) === 3) continue;
            $tRaw = (string)($row['type'] ?? '');
            $isTransfer = ($tRaw === '2');
            $isOut = ($tRaw === '0' || strtoupper($tRaw) === 'O' || strtolower($tRaw) === 'out');
            if (!$isTransfer && !$isOut) continue;

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

            $accFromRaw = $row['account_from'] ?? $row['account_from_id'] ?? $row['account_id'] ?? 0;
            if (is_array($accFromRaw)) $accFromRaw = $accFromRaw['account_id'] ?? $accFromRaw['id'] ?? 0;
            $accFromId = (int)$accFromRaw;

            if ($isTransfer) {
                $accToRaw = $row['account_to'] ?? $row['account_to_id'] ?? 0;
            } else {
                $accToRaw = $row['recipient_id'] ?? $row['account_to'] ?? $row['account_to_id'] ?? 0;
            }
            if (is_array($accToRaw)) $accToRaw = $accToRaw['account_id'] ?? $accToRaw['id'] ?? 0;
            $accToId = (int)$accToRaw;

            if ($accFromId !== $accountFrom || $accToId !== $accountTo) continue;

            $cmt = (string)($row['comment'] ?? $row['description'] ?? $row['comment_text'] ?? '');
            $sumRaw = $row['amount'] ?? $row['amount_to'] ?? $row['amount_from'] ?? $row['sum'] ?? 0;
            $sum = abs($normMoney($sumRaw));

            $uRaw = $row['user_id'] ?? $row['userId'] ?? $row['user'] ?? $row['employee_id'] ?? null;
            if (is_array($uRaw)) $uRaw = $uRaw['user_id'] ?? $uRaw['id'] ?? $uRaw['userId'] ?? null;
            $uId = (int)($uRaw ?? 0);
            $userName = '';
            $uObj = $row['user'] ?? $row['employee'] ?? null;
            if (is_array($uObj)) {
                $userName = (string)($uObj['name'] ?? $uObj['user_name'] ?? $uObj['username'] ?? $uObj['title'] ?? '');
                $userName = trim($userName);
            }
            if ($userName === '' && $uId > 0) $userName = '#' . $uId;

            $txId = (int)($row['transaction_id'] ?? $row['id'] ?? 0);
            $out[] = [
                'transaction_id' => $txId,
                'transfer_id' => $txId,
                'ts' => (int)$ts,
                'sum' => (int)$sum,
                'comment' => $cmt,
                'user' => $userName,
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

if (($_GET['ajax'] ?? '') === 'delete_finance_transfer') {
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
    $transferId = (int)($payload['transfer_id'] ?? 0);
    $txId = (int)($payload['tx_id'] ?? 0);
    $dTo = trim((string)($payload['dateTo'] ?? ''));
    if (!in_array($kind, ['vietnam', 'tips'], true) || ($transferId <= 0 && $txId <= 0) || $dTo === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $startTs = strtotime($dTo . ' 00:00:00');
    $endTs = strtotime($dTo . ' 23:59:59');
    if ($startTs === false || $endTs === false) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Bad date'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $comment = trim((string)($payload['comment'] ?? ''));
    if (mb_strlen($comment, 'UTF-8') > 2000) $comment = mb_substr($comment, 0, 2000, 'UTF-8');
    $by = '';
    if (!isset($_SESSION)) {
        if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    }
    $by = trim((string)($_SESSION['user_email'] ?? $_SESSION['user_name'] ?? ''));
    try {
        $rows = [];
        try {
            $rows = $api->request('finance.getTransactions', [
                'dateFrom' => date('dmY', $startTs),
                'dateTo' => date('dmY', $endTs),
            
                'timezone' => $apiTzName,]);
        } catch (\Throwable $e) {
            $rows = [];
        }
        if (!is_array($rows)) $rows = [];

        $isMatchKind = function (array $r, string $kind): bool {
            if (((int)($r['status'] ?? 0)) === 3) return false;
            $tRaw = (string)($r['type'] ?? '');
            $type = (int)$tRaw;
            $isTransfer = ($tRaw === '2');
            $isIn = ($tRaw === '1' || strtoupper($tRaw) === 'I' || strtolower($tRaw) === 'in');
            $isOut = ($tRaw === '0' || strtoupper($tRaw) === 'O' || strtolower($tRaw) === 'out');
            
            if ($isTransfer || $isIn || $isOut) {
                if ($isTransfer || $isIn) {
                    $accId = (int)($r['account_id'] ?? 0);
                } else {
                    $accId = (int)($r['recipient_id'] ?? $r['account_to'] ?? $r['account_to_id'] ?? 0);
                }
                $expectedTo = ($kind === 'vietnam') ? 9 : (($kind === 'tips') ? 8 : 0);
                if ($accId === $expectedTo) {
                    $cmt = (string)($r['comment'] ?? $r['description'] ?? $r['comment_text'] ?? '');
                    $cmt = mb_strtolower(trim($cmt), 'UTF-8');
                    if ($kind === 'vietnam' && mb_stripos($cmt, 'вьетна', 0, 'UTF-8') !== false) return true;
                    if ($kind === 'tips' && (mb_stripos($cmt, 'типс', 0, 'UTF-8') !== false || mb_stripos($cmt, 'tips', 0, 'UTF-8') !== false)) return true;
                }
            }
            return false;
        };

        $found = false;
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $tid = (int)($r['transaction_id'] ?? 0);
            if ($txId > 0 && $tid !== $txId) continue;
            if ($transferId > 0 && $txId <= 0) {
                $bt = (int)($r['binding_type'] ?? 0);
                $bid = (int)($r['binding_id'] ?? 0);
                $rt = (int)($r['recipient_type'] ?? 0);
                $rid = (int)($r['recipient_id'] ?? 0);
                $match = false;
                if ($bt === 1 && $bid === $transferId) $match = true;
                if (!$match && $rt === 1 && $rid === $transferId) $match = true;
                if (!$match) continue;
            }
            if (!$isMatchKind($r, $kind)) continue;
            $found = true;
            break;
        }
        if (!$found) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Транзакция не найдена для выбранного дня'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Сначала удаляем транзакцию из Poster API
        if ($txId > 0) {
            try {
                $api->request('finance.removeTransaction', [
                    'transaction_id' => $txId
                ], 'POST');
            } catch (\Throwable $e) {
                // Игнорируем ошибку удаления из API (возможно она уже удалена)
            }
        } elseif ($transferId > 0) {
            try {
                $api->request('finance.removeTransaction', [
                    'transaction_id' => $transferId
                ], 'POST');
            } catch (\Throwable $e) {
            }
        }

        $db->query(
            "INSERT INTO {$pfh} (date_to, kind, transfer_id, tx_id, comment, created_by)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$dTo, $kind, $transferId > 0 ? $transferId : null, $txId > 0 ? $txId : null, $comment !== '' ? $comment : null, $by !== '' ? $by : null]
        );

        $hiddenTx = [];
        $hiddenTransfer = [];
        try {
            $hRows = $db->query(
                "SELECT transfer_id, tx_id
                 FROM {$pfh}
                 WHERE date_to = ? AND kind = ?",
                [$dTo, $kind]
            )->fetchAll();
            if (!is_array($hRows)) $hRows = [];
            foreach ($hRows as $hr) {
                $hidT = (int)($hr['transfer_id'] ?? 0);
                $hidX = (int)($hr['tx_id'] ?? 0);
                if ($hidT > 0) $hiddenTransfer[$hidT] = true;
                if ($hidX > 0) $hiddenTx[$hidX] = true;
            }
        } catch (\Throwable $e) {
        }

        $remaining = 0;
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            if (!$isMatchKind($r, $kind)) continue;
            $tid = (int)($r['transaction_id'] ?? 0);
            if ($tid > 0 && !empty($hiddenTx[$tid])) continue;
            $bt = (int)($r['binding_type'] ?? 0);
            $bid = (int)($r['binding_id'] ?? 0);
            $rt = (int)($r['recipient_type'] ?? 0);
            $rid = (int)($r['recipient_id'] ?? 0);
            if ($bt === 1 && $bid > 0 && !empty($hiddenTransfer[$bid])) continue;
            if ($rt === 1 && $rid > 0 && !empty($hiddenTransfer[$rid])) continue;
            $remaining++;
        }

        echo json_encode(['ok' => true, 'remaining' => $remaining], JSON_UNESCAPED_UNICODE);
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
    } catch (\Throwable $e) {}
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
        $by = trim((string)($_SESSION['user_email'] ?? $_SESSION['user_name'] ?? ''));
        if ($by !== '') $comment .= ' by ' . $by;
        $by = trim((string)($_SESSION['user_email'] ?? $_SESSION['user_name'] ?? ''));
        if ($by !== '') $comment .= ' by ' . $by;

        $api3 = new \App\Classes\PosterAPI((string)$token);
        try {
            $rows = $api3->request('finance.getTransactions', [
                'dateFrom' => str_replace('-', '', date('Y-m-d')),
                'dateTo' => str_replace('-', '', date('Y-m-d')),
            
                'timezone' => $apiTzName,]);
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

$sepayHiddenRows = $db->query(
    "SELECT s.sepay_id, s.transaction_date, s.transfer_amount, s.payment_method, s.content, s.reference_code,
            h.comment AS hidden_comment
     FROM {$st} s
     JOIN {$sh} h ON h.sepay_id = s.sepay_id
     WHERE s.transaction_date BETWEEN ? AND ?
       AND s.transfer_type = 'in'
       AND (s.payment_method IS NULL OR s.payment_method IN ('Card','Bybit'))
       AND COALESCE(s.was_deleted, 0) = 0
     ORDER BY s.transaction_date ASC",
    [$periodFrom, $periodTo]
)->fetchAll();

$posterRows = $db->query(
    "SELECT p.transaction_id, p.receipt_number, p.date_close, p.payed_card, p.payed_third_party, p.tip_sum,
            pm.title AS payment_method_display,
            p.waiter_name, p.table_id, p.spot_id, p.poster_payment_method_id
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
        "SELECT COALESCE(SUM(payed_card + payed_third_party + tip_sum), 0)
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
} catch (\Throwable $e) {
    $financeTipsCents = null;
}

$transferVietnamExists = false;
$transferTipsExists = false;
$transferVietnamFoundList = [];
$transferTipsFoundList = [];
$hiddenFinanceByKind = ['vietnam' => ['tx' => [], 'transfer' => []], 'tips' => ['tx' => [], 'transfer' => []]];
try {
    $hRows = $db->query(
        "SELECT kind, transfer_id, tx_id
         FROM {$pfh}
         WHERE date_to = ?",
        [$dateTo]
    )->fetchAll();
    if (!is_array($hRows)) $hRows = [];
    foreach ($hRows as $r) {
        $k = (string)($r['kind'] ?? '');
        if (!isset($hiddenFinanceByKind[$k])) continue;
        $tid = (int)($r['transfer_id'] ?? 0);
        $xid = (int)($r['tx_id'] ?? 0);
        if ($tid > 0) $hiddenFinanceByKind[$k]['transfer'][$tid] = true;
        if ($xid > 0) $hiddenFinanceByKind[$k]['tx'][$xid] = true;
    }
} catch (\Throwable $e) {
}
try {
    $targetTs = strtotime($dateTo . ' 23:55:00');
    $startTs = strtotime($dateTo . ' 00:00:00');
    $endTs = strtotime($dateTo . ' 23:59:59');
    if ($targetTs !== false && $startTs !== false && $endTs !== false) {
        $apiFinance = new \App\Classes\PosterAPI((string)$token);
        $rows = [];
        try {
            $rows = $apiFinance->request('finance.getTransactions', [
                'dateFrom' => date('dmY', $startTs),
                'dateTo' => date('dmY', $endTs),
            
                'timezone' => $apiTzName,]);
        } catch (\Throwable $e) {
            $rows = [];
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
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $tRaw = (string)($r['type'] ?? '');
            $isTransfer = ($tRaw === '2');
            $isIn = ($tRaw === '1' || strtoupper($tRaw) === 'I' || strtolower($tRaw) === 'in');
            $isOut = ($tRaw === '0' || strtoupper($tRaw) === 'O' || strtolower($tRaw) === 'out');
            if (!$isTransfer && !$isIn && !$isOut) continue;

            $dStr = (string)($r['date'] ?? '');
            $ts = $dStr !== '' ? strtotime($dStr) : false;
            if ($ts === false || $ts < $startTs || $ts > $endTs) continue;

            if ($isTransfer || $isIn) {
                $accId = (int)($r['account_id'] ?? 0);
            } else {
                $accId = (int)($r['recipient_id'] ?? $r['account_to'] ?? $r['account_to_id'] ?? 0);
            }
            if ($accId !== 8 && $accId !== 9) continue;

            $amountMinor = (int)($r['amount'] ?? 0);
            if (abs($amountMinor) <= 0) continue;

            $cmt = (string)($r['comment'] ?? $r['description'] ?? $r['comment_text'] ?? '');
            $cmtNorm = $normText($cmt);
            $isVietnam = $accId === 9 && mb_stripos($cmtNorm, 'вьетна', 0, 'UTF-8') !== false;
            $isTips = $accId === 8 && (mb_stripos($cmtNorm, 'типс', 0, 'UTF-8') !== false || mb_stripos($cmtNorm, 'tips', 0, 'UTF-8') !== false);
            if (!$isVietnam && !$isTips) continue;

            $tid = (int)($r['transaction_id'] ?? 0);
            $bt = (int)($r['binding_type'] ?? 0);
            $bid = (int)($r['binding_id'] ?? 0);
            $rt = (int)($r['recipient_type'] ?? 0);
            $rid = (int)($r['recipient_id'] ?? 0);
            $transferId = 0;
            if ($rt === 1 && $rid > 0) $transferId = $rid;
            elseif ($bt === 1 && $bid > 0) $transferId = $bid;

            $uRaw = $r['user_id'] ?? $r['userId'] ?? $r['user'] ?? $r['employee_id'] ?? null;
            if (is_array($uRaw)) $uRaw = $uRaw['user_id'] ?? $uRaw['id'] ?? $uRaw['userId'] ?? null;
            $uId = (int)($uRaw ?? 0);
            $userName = '';
            $uObj = $r['user'] ?? $r['employee'] ?? null;
            if (is_array($uObj)) {
                $userName = (string)($uObj['name'] ?? $uObj['user_name'] ?? $uObj['username'] ?? $uObj['title'] ?? '');
                $userName = trim($userName);
            }
            if ($userName === '' && $uId > 0) $userName = '#' . $uId;

            $rowOut = [
                'transfer_id' => $transferId,
                'transaction_id' => $tid,
                'ts' => (int)$ts,
                'sum_minor' => abs($amountMinor),
                'comment' => $cmt,
                'user' => $userName,
                'type' => $tRaw,
            ];
            if ($isVietnam) {
                if (!empty($hiddenFinanceByKind['vietnam']['transfer'][$transferId]) || !empty($hiddenFinanceByKind['vietnam']['tx'][$tid])) continue;
                $transferVietnamFoundList[] = $rowOut;
            }
            if ($isTips) {
                if (!empty($hiddenFinanceByKind['tips']['transfer'][$transferId]) || !empty($hiddenFinanceByKind['tips']['tx'][$tid])) continue;
                $transferTipsFoundList[] = $rowOut;
            }
        }
    }
} catch (\Throwable $e) {
}

$transferVietnamExists = count($transferVietnamFoundList) > 0;
$transferTipsExists = count($transferTipsFoundList) > 0;

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
    return number_format($v, 0, '.', "\u{202F}");
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
    <script src="/assets/user_menu.js" defer></script>
      <?php include $_SERVER['DOCUMENT_ROOT'] . '/analytics.php'; ?>
  <link rel="stylesheet" href="/assets/css/common.css?v=20260412_0170">
  <link rel="stylesheet" href="/assets/css/payday_index.css?v=20260413_1545">
</head>
<body>
<div class="container">
    <div class="top-nav">
        <div class="nav-left">
            <div class="nav-title">Payday</div>
            <div class="tabs">
                <button type="button" class="tab active" id="tabIn">IN</button>
                <button type="button" class="tab" id="tabOut">OUT</button>
                <button type="button" class="tab" id="btnKashShift" style="margin-left: 15px; background: rgba(184,135,70,0.15); color: #B88746;">KashShift</button>
                <button type="button" class="tab" id="btnSupplies" style="margin-left: 5px; background: rgba(184,135,70,0.15); color: #B88746;">Supplies</button>
            </div>
            <form method="GET" id="dateForm" style="display: flex; gap: 10px; margin-left: 10px;">
                <input type="date" name="dateFrom" value="<?= htmlspecialchars($dateFrom) ?>" class="btn" style="padding: 8px 10px; width: 180px;">
                <input type="date" name="dateTo" value="<?= htmlspecialchars($dateTo) ?>" class="btn" style="padding: 8px 10px; width: 180px;">
                <button class="btn" type="submit">Открыть</button>
            </form>
        </div>
        <?php require __DIR__ . '/../partials/user_menu.php'; ?>
    </div>

    <?php if ($error !== ''): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="card">
        <div class="toolbar toolbar-line" style="margin-bottom: 10px;">
            <form method="POST" id="posterSyncForm">
                <input type="hidden" name="action" value="load_poster_checks">
                <input type="hidden" name="dateFrom" value="<?= htmlspecialchars($dateFrom) ?>">
                <input type="hidden" name="dateTo" value="<?= htmlspecialchars($dateTo) ?>">
                <button class="btn primary" id="posterSyncBtn" type="submit">Загрузить чеки</button>
            </form>

            <form method="POST" id="sepaySyncForm">
                <input type="hidden" name="action" value="reload_sepay_api">
                <input type="hidden" name="dateFrom" value="<?= htmlspecialchars($dateFrom) ?>">
                <input type="hidden" name="dateTo" value="<?= htmlspecialchars($dateTo) ?>">
                <button class="btn primary" id="sepaySyncBtn" type="submit">Обновить платежи</button>
            </form>
            <button class="btn" id="outMailBtn" type="button" style="display:none;">Обновить Платежи Out</button>
            <button class="btn" id="outFinanceBtn" type="button" style="display:none;">Обновить транзакции</button>

            <form method="POST" id="clearDayForm">
                <input type="hidden" name="action" value="clear_day">
                <input type="hidden" name="dateFrom" value="<?= htmlspecialchars($dateFrom) ?>">
                <input type="hidden" name="dateTo" value="<?= htmlspecialchars($dateTo) ?>">
                <button class="btn" id="clearDayBtn" type="submit" onclick="return confirm('Очистить все данные за выбранный день (Poster, SePay, связи)?')">Очистить день</button>
            </form>

            <div class="toggle-wrap" title="Lite/Full">
                <span class="toggle-text"><span class="tt-full">Lite</span><span class="tt-short">L</span></span>
                <label class="switch">
                    <input id="modeToggle" type="checkbox">
                    <span class="slider"></span>
                </label>
                <span class="toggle-text"><span class="tt-full">Full</span><span class="tt-short">F</span></span>
            </div>
        </div>

        <div class="divider"></div>

        <div id="outSection" style="display:none;">
            <div class="grid" id="outGrid" style="grid-template-columns: 1fr 70px 1fr; gap:12px; position: relative;">
                <div id="outLineLayer"></div>
                <div class="card" style="padding:0;">
                    <div style="padding:8px 12px; font-weight:900;" class="vc-subtitle">
                        <span>Sepay (Mail)</span>
                        <button type="button" class="vc-toggle hidden-toggle" id="toggleOutMailHiddenBtn" title="Показать/скрыть скрытые">👁</button>
                    </div>
                    <div id="outSepayScroll" style="max-height: 56vh; overflow:auto;">
                        <table id="outSepayTable">
                            <thead><tr><th class="col-out-hide"></th><th class="col-out-content">Content</th><th class="nowrap col-out-time">Время</th><th class="nowrap col-out-sum">Сумма</th><th class="col-out-select"></th><th class="col-out-anchor"></th></tr></thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
                <div class="mid-col" id="outMidCol">
                    <button class="mid-btn primary" id="outLinkMakeBtn" type="button" title="Связать выбранные" disabled>🎯</button>
                    <button class="mid-btn" id="outHideLinkedBtn" type="button" title="Скрыть связанные">👁</button>
                    <button class="mid-btn" id="outLinkAutoBtn" type="button" title="Автосвязи">🧩</button>
                    <button class="mid-btn" id="outLinkClearBtn" type="button" title="Разорвать связи">⛓️‍💥</button>
                    <div class="muted" style="text-align:center; font-weight:900; line-height: 1.35;">
                        <div>←</div>
                        <div id="outSelSepaySum">0</div>
                        <div style="height: 10px;"></div>
                        <div>→</div>
                        <div id="outSelPosterSum">0</div>
                        <div style="height: 10px;"></div>
                        <div id="outSelMatch" style="font-size: 16px; color: #34d399;">✅</div>
                        <div id="outSelDiff" style="font-weight: 900;">0</div>
                    </div>
                </div>
                <div class="card" style="padding:0;">
                    <div style="padding:8px 12px; font-weight:900;">Poster Finance</div>
                    <div id="outPosterScroll" style="max-height: 56vh; overflow:auto;">
                        <table id="outPosterTable">
                            <thead>
                                <tr>
                                    <th></th><th class="nowrap col-out-date">Дата</th><th class="col-out-user">User</th><th class="col-out-category">Category</th><th class="col-out-type">Type</th><th class="col-out-amount">Amount</th><th class="col-out-balance">Balance</th><th class="col-out-comment">Comment</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid" id="tablesRoot">
            <div class="confirm-backdrop" id="kashshiftModal" style="display:none; z-index: 9999; align-items: flex-start; padding-top: 5vh;">
                <div class="confirm-modal" role="dialog" style="max-width: 900px; width: 90%;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 15px;">
                        <h3 style="margin:0;">KashShift</h3>
                        <button type="button" class="btn2" id="kashshiftClose" style="min-width: 40px; font-weight: bold; font-size: 16px;">✕</button>
                    </div>
                    <div class="body" id="kashshiftBody" style="max-height: 85vh; overflow: auto;">
                        <div style="text-align:center;">Загрузка...</div>
                    </div>
                </div>
            </div>

            <div class="confirm-backdrop" id="suppliesModal" style="display:none; z-index: 9999; align-items: flex-start; padding-top: 5vh;">
                <div class="confirm-modal" role="dialog" style="max-width: 900px; width: 90%;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 15px;">
                        <h3 style="margin:0;">Supplies</h3>
                        <button type="button" class="btn2" id="suppliesClose" style="min-width: 40px; font-weight: bold; font-size: 16px;">✕</button>
                    </div>
                    <div class="body" id="suppliesBody" style="max-height: 85vh; overflow: auto;">
                        <div style="text-align:center;">Загрузка...</div>
                    </div>
                </div>
            </div>

            <div class="confirm-backdrop" id="financeConfirm">
                <div class="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="financeConfirmTitle">
                    <h3 id="financeConfirmTitle">Подтверждение</h3>
                    <div class="body" id="financeConfirmText"></div>
                    <div class="sub">
                        <label style="display:flex; align-items:center; gap: 8px; margin: 0;">
                            <input type="checkbox" id="financeConfirmChecked">
                            проверил
                        </label>
                    </div>
                    <div class="actions">
                        <button type="button" class="btn2" id="financeConfirmCancel">Отмена</button>
                        <button type="button" class="btn2 primary" id="financeConfirmOk" disabled>OK</button>
                    </div>
                </div>
            </div>
            <div id="lineLayer"></div>
            <div class="card" style="padding: 0;">
                <div style="padding: 12px 12px 6px;">
                    <div style="font-weight:900;">SePay</div>
                    <div class="muted vc-subtitle">
                        <span>Приходы за день</span>
                        <button type="button" class="vc-toggle hidden-toggle" id="toggleSepayHiddenBtn" title="Показать/скрыть скрытые транзакции">👁</button>
                    </div>
                </div>
                <div id="sepayScroll" style="max-height: 56vh; overflow:auto;">
                    <table id="sepayTable">
                        <thead>
                            <tr>
                                <th></th>
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
                                <td class="nowrap col-sepay-hide"><button type="button" class="sepay-hide" data-sepay-id="<?= $sid ?>" title="Скрыть (не чек)">−</button></td>
                                <td class="col-sepay-content"><?= htmlspecialchars((string)($r['content'] ?? '')) ?></td>
                                <td class="nowrap col-sepay-time"><?= date('H:i:s', strtotime($r['transaction_date'])) ?></td>
                                <td class="sum col-sepay-sum"><?= htmlspecialchars($fmtVnd((int)$r['transfer_amount'])) ?></td>
                                <td class="col-sepay-cb"><input type="checkbox" class="sepay-cb" data-id="<?= $sid ?>"></td>
                                <td class="nowrap col-sepay-dot"><span class="anchor" id="sepay-<?= $sid ?>"></span></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php foreach ($sepayHiddenRows as $r): ?>
                            <?php
                                $sid = (int)$r['sepay_id'];
                                $pm = (string)($r['payment_method'] ?? '');
                                $cmt = trim((string)($r['hidden_comment'] ?? ''));
                                $contentShow = $cmt !== '' ? $cmt : ('Скрыто: ' . (string)($r['content'] ?? ''));
                            ?>
                            <?php $tsRow = strtotime($r['transaction_date']) ?: 0; ?>
                            <tr class="row-hidden" data-hidden="1" data-sepay-id="<?= $sid ?>" data-ts="<?= (int)$tsRow ?>" data-sum="<?= (int)$r['transfer_amount'] ?>" data-content="<?= htmlspecialchars(mb_strtolower($contentShow, 'UTF-8')) ?>">
                                <td class="nowrap col-sepay-hide"><button type="button" class="sepay-hide" data-sepay-id="<?= $sid ?>" title="Изменить комментарий скрытия">−</button></td>
                                <td class="col-sepay-content"><?= htmlspecialchars($contentShow) ?></td>
                                <td class="nowrap col-sepay-time"><?= date('H:i:s', strtotime($r['transaction_date'])) ?></td>
                                <td class="sum col-sepay-sum"><?= htmlspecialchars($fmtVnd((int)$r['transfer_amount'])) ?></td>
                                <td class="col-sepay-cb"><input type="checkbox" class="sepay-cb" data-id="<?= $sid ?>"></td>
                                <td class="nowrap col-sepay-dot"><span class="anchor" id="sepay-<?= $sid ?>"></span></td>
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
                    <div id="selSepaySum">0</div>
                    <div style="height: 10px;"></div>
                    <div>→</div>
                    <div id="selPosterSum">0</div>
                    <div style="height: 10px;"></div>
                    <div id="selMatch" style="font-size: 16px;">❗</div>
                    <div id="selDiff" style="font-weight: 900;">0</div>
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
                    <div class="muted vc-subtitle">
                        <span>Безнал / смешанная (за выбранный день)</span>
                        <button type="button" class="vc-toggle" id="toggleVietnamBtn" title="Показать/скрыть Vietnam Company">👁</button>
                    </div>
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
                                $spotIdRow = (int)($r['spot_id'] ?? 0);
                                $tableIdRow = (int)($r['table_id'] ?? 0);
                                $tableNumCache = $tableNumCache ?? [];
                                $getTableNum = $getTableNum ?? function (int $spotId, int $tableId) use (&$tableNumCache, $token): ?int {
                                    if ($spotId <= 0 || $tableId <= 0) return null;
                                    if (!isset($tableNumCache[$spotId])) {
                                        try {
                                            $apiTables = new \App\Classes\PosterAPI((string)$token);
                                            $rows = $apiTables->request('spots.getTableHallTables', [
                                                'spot_id' => $spotId,
                                                'without_deleted' => 0,
                                            ], 'GET');
                                            if (!is_array($rows)) $rows = [];
                                            $m = [];
                                            foreach ($rows as $t) {
                                                if (!is_array($t)) continue;
                                                $tid = (int)($t['table_id'] ?? 0);
                                                $tn = (int)($t['table_num'] ?? 0);
                                                if ($tid > 0 && $tn > 0) $m[$tid] = $tn;
                                            }
                                            $tableNumCache[$spotId] = $m;
                                        } catch (\Throwable $e) {
                                            $tableNumCache[$spotId] = [];
                                        }
                                    }
                                    return isset($tableNumCache[$spotId][$tableId]) ? (int)$tableNumCache[$spotId][$tableId] : null;
                                };
                                $tableNum = $getTableNum($spotIdRow, $tableIdRow);
                                $tableDisplay = $tableNum !== null ? (string)$tableNum : (string)$tableIdRow;
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
                            <tr class="<?= $cls ?>" data-poster-id="<?= $pid ?>" data-vietnam="<?= $isVietnam ? '1' : '0' ?>" data-num="<?= (int)$receiptNumber ?>" data-ts="<?= (int)$tsRow ?>" data-card="<?= (int)$cardVnd ?>" data-tips="<?= (int)$tipVnd ?>" data-total="<?= (int)($cardVnd + $tipVnd) ?>" data-method="<?= htmlspecialchars(mb_strtolower($pm, 'UTF-8')) ?>" data-waiter="<?= htmlspecialchars(mb_strtolower((string)($r['waiter_name'] ?? ''), 'UTF-8')) ?>" data-table="<?= (int)($tableNum !== null ? $tableNum : ($r['table_id'] ?? 0)) ?>">
                                <td><div class="cell-anchor"><span class="anchor" id="poster-<?= $pid ?>"></span><input type="checkbox" class="poster-cb" data-id="<?= $pid ?>"></div></td>
                                <td class="nowrap col-poster-num"><?= htmlspecialchars((string)$receiptNumber) ?></td>
                                <td class="nowrap col-poster-time"><?= date('H:i:s', strtotime($r['date_close'])) ?></td>
                                <td class="sum col-poster-card"><?= htmlspecialchars($fmtVnd($cardVnd)) ?></td>
                                <td class="sum col-poster-tips"><?= htmlspecialchars($fmtVnd($tipVnd)) ?></td>
                                <td class="sum col-poster-total"><?= htmlspecialchars($fmtVnd($cardVnd + $tipVnd)) ?></td>
                                <td class="nowrap col-poster-method"><span class="pm-full"><?= htmlspecialchars($pmFull) ?></span><span class="pm-lite"><?= htmlspecialchars($pmLite) ?></span></td>
                                <td class="col-poster-waiter"><?= htmlspecialchars((string)($r['waiter_name'] ?? '')) ?></td>
                                <td class="nowrap col-poster-table"><?= htmlspecialchars($tableDisplay) ?></td>
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
        <div class="card card-finance">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 10px;">
                <div style="font-weight: 900;">Финансовые транзакции</div>
                <button class="btn tiny" id="finance-refresh-all" type="button" title="Обновить">🔄</button>
            </div>

            <?php
            $vietnamCents = $financeVietnamCents;
            $tipsCents = $financeTipsCents;
            $vietnamVnd = $vietnamCents !== null ? $posterCentsToVnd((int)$vietnamCents) : null;
            $tipsVnd = $tipsCents !== null ? $posterCentsToVnd((int)$tipsCents) : null;
            $vietnamDisabledReason = $vietnamCents === null
                ? 'Нет данных за период: нажми «Загрузить чеки из Poster».'
                : 'Сумма = 0: нет чеков Vietnam Company (payment_method_id=11) за выбранный период.';
            $tipsDisabledReason = $tipsCents === null
                ? 'Нет данных за период: нажми «Загрузить чеки из Poster».'
                : 'Сумма = 0: нет типсов по связанным чекам за выбранный период.';

            $vietnamFound = [];
            if ($vietnamVnd !== null) {
                foreach ($transferVietnamFoundList as $f) {
                    if (!is_array($f)) continue;
                    $sumMinor = (int)($f['sum_minor'] ?? 0);
                    $sumVnd = (int)$posterCentsToVnd($sumMinor);
                    if ($sumVnd !== (int)$vietnamVnd) continue;
                    $vietnamFound[] = $f;
                }
            }

            $tipsFound = [];
            if ($tipsVnd !== null) {
                foreach ($transferTipsFoundList as $f) {
                    if (!is_array($f)) continue;
                    $sumMinor = (int)($f['sum_minor'] ?? 0);
                    $sumVnd = (int)$posterCentsToVnd($sumMinor);
                    if ($sumVnd !== (int)$tipsVnd) continue;
                    $tipsFound[] = $f;
                }
            }

            $vietnamExists = count($vietnamFound) > 0;
            $tipsExists = count($tipsFound) > 0;
            $vietnamDisabled = $vietnamExists || $vietnamCents === null || (int)$vietnamCents <= 0;
            $tipsDisabled = $tipsExists || $tipsCents === null || (int)$tipsCents <= 0;
            ?>

            <div class="finance-row">
                <form method="POST" class="finance-transfer" style="width:100%;"
                      data-kind="vietnam"
                      data-date-from="<?= htmlspecialchars($dateFrom) ?>"
                      data-date-to="<?= htmlspecialchars($dateTo) ?>"
                      data-account-from-id="1"
                      data-account-to-id="9"
                      data-account-from-name="<?= htmlspecialchars((string)($posterAccountsById[1]['name'] ?? '#1')) ?>"
                      data-account-to-name="<?= htmlspecialchars((string)($posterAccountsById[9]['name'] ?? '#9')) ?>"
                      data-sum-vnd="<?= htmlspecialchars((string)($vietnamVnd !== null ? (int)$vietnamVnd : 0)) ?>">
                    <input type="hidden" name="action" value="create_transfer">
                    <input type="hidden" name="kind" value="vietnam">
                    <input type="hidden" name="dateFrom" value="<?= htmlspecialchars($dateFrom) ?>">
                    <input type="hidden" name="dateTo" value="<?= htmlspecialchars($dateTo) ?>">
                    <div style="display:flex; align-items:center; gap: 10px;">
                        <div style="font-weight:900; white-space:nowrap;">Vietnam Company</div>
                        <div style="flex:1; text-align:center; font-weight:900;"><?= $vietnamVnd !== null ? htmlspecialchars($fmtVnd((int)$vietnamVnd)) : '—' ?></div>
                        <button class="btn" type="submit" <?= $vietnamDisabled ? 'disabled' : '' ?>>Создать транзакцию</button>
                    </div>
                    <div class="muted finance-status" style="margin-top: 6px;">
                        <?php if ($vietnamExists): ?>
                            <div style="overflow-x:auto; max-width:100%;">
                                <table class="table" style="margin-top:5px; font-size:12px; width:100%;">
                                    <thead><tr><th style="padding:2px 4px;">Дата<br><span style="font-weight:normal;">Время</span></th><th style="padding:2px 4px;">Сумма</th><th style="padding:2px 4px;">Комментарий</th><th style="padding:2px 0px; width:1%;"></th></tr></thead>
                                    <tbody>
                                    <?php foreach ($vietnamFound as $f): ?>
                                        <?php
                                            $ts = (int)($f['ts'] ?? 0);
                                            $sumMinor = (int)($f['sum_minor'] ?? 0);
                                            $sumVnd = (int)$posterCentsToVnd($sumMinor);
                                            $tRaw = (string)($f['type'] ?? '');
                                            $isOut = ($tRaw === '0' || strtoupper($tRaw) === 'O' || strtolower($tRaw) === 'out');
                                            $sumSignedVnd = $isOut ? -$sumVnd : $sumVnd;
                                            $cmt = trim((string)($f['comment'] ?? ''));
                                            $u = trim((string)($f['user'] ?? ''));
                                            $commentText = $u !== '' ? "$cmt ($u)" : $cmt;
                                            $dateStr = date('d.m.Y', $ts);
                                            $timeStr = date('H:i:s', $ts);
                                        ?>
                                        <tr>
                                            <td>
                                                <div><?= htmlspecialchars($dateStr) ?></div>
                                                <div class="muted"><?= htmlspecialchars($timeStr) ?></div>
                                            </td>
                                            <td class="sum"><?= htmlspecialchars($fmtVnd((int)$sumSignedVnd)) ?></td>
                                            <td>
                                                <div style="display:flex; justify-content:space-between; gap: 8px; align-items:flex-start;">
                                                    <div><?= htmlspecialchars($commentText) ?></div>
                                                    <button type="button" class="finance-del btn tiny" style="padding:0 4px; flex:0 0 auto;" data-kind="vietnam" data-transfer-id="<?= (int)($f['transfer_id'] ?? 0) ?>" data-tx-id="<?= (int)($f['transaction_id'] ?? 0) ?>" data-date-to="<?= htmlspecialchars($dateTo) ?>" title="Скрыть транзакцию">✕</button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php elseif ($vietnamDisabled): ?>
                            <?= htmlspecialchars($vietnamDisabledReason) ?>
                        <?php else: ?>
                            <span style="color:var(--muted);">Транзакция не найдена</span>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <div class="finance-row">
                <form method="POST" class="finance-transfer" style="width:100%;"
                      data-kind="tips"
                      data-date-from="<?= htmlspecialchars($dateFrom) ?>"
                      data-date-to="<?= htmlspecialchars($dateTo) ?>"
                      data-account-from-id="1"
                      data-account-to-id="8"
                      data-account-from-name="<?= htmlspecialchars((string)($posterAccountsById[1]['name'] ?? '#1')) ?>"
                      data-account-to-name="<?= htmlspecialchars((string)($posterAccountsById[8]['name'] ?? '#8')) ?>"
                      data-sum-vnd="<?= htmlspecialchars((string)($tipsVnd !== null ? (int)$tipsVnd : 0)) ?>">
                    <input type="hidden" name="action" value="create_transfer">
                    <input type="hidden" name="kind" value="tips">
                    <input type="hidden" name="dateFrom" value="<?= htmlspecialchars($dateFrom) ?>">
                    <input type="hidden" name="dateTo" value="<?= htmlspecialchars($dateTo) ?>">
                    <div style="display:flex; align-items:center; gap: 10px;">
                        <div style="font-weight:900; white-space:nowrap;">Tips</div>
                        <div style="flex:1; text-align:center; font-weight:900;"><?= $tipsVnd !== null ? htmlspecialchars($fmtVnd((int)$tipsVnd)) : '—' ?></div>
                        <button class="btn" type="submit" <?= $tipsDisabled ? 'disabled' : '' ?>>Создать транзакцию</button>
                    </div>
                    <div class="muted finance-status" style="margin-top: 6px;">
                        <?php if ($tipsExists): ?>
                            <div style="overflow-x:auto; max-width:100%;">
                                <table class="table" style="margin-top:5px; font-size:12px; width:100%;">
                                    <thead><tr><th style="padding:2px 4px;">Дата<br><span style="font-weight:normal;">Время</span></th><th style="padding:2px 4px;">Сумма</th><th style="padding:2px 4px;">Комментарий</th><th style="padding:2px 0px; width:1%;"></th></tr></thead>
                                    <tbody>
                                    <?php foreach ($tipsFound as $f): ?>
                                        <?php
                                            $ts = (int)($f['ts'] ?? 0);
                                            $sumMinor = (int)($f['sum_minor'] ?? 0);
                                            $sumVnd = (int)$posterCentsToVnd($sumMinor);
                                            $tRaw = (string)($f['type'] ?? '');
                                            $isOut = ($tRaw === '0' || strtoupper($tRaw) === 'O' || strtolower($tRaw) === 'out');
                                            $sumSignedVnd = $isOut ? -$sumVnd : $sumVnd;
                                            $cmt = trim((string)($f['comment'] ?? ''));
                                            $u = trim((string)($f['user'] ?? ''));
                                            $commentText = $u !== '' ? "$cmt ($u)" : $cmt;
                                            $dateStr = date('d.m.Y', $ts);
                                            $timeStr = date('H:i:s', $ts);
                                        ?>
                                        <tr>
                                            <td>
                                                <div><?= htmlspecialchars($dateStr) ?></div>
                                                <div class="muted"><?= htmlspecialchars($timeStr) ?></div>
                                            </td>
                                            <td class="sum"><?= htmlspecialchars($fmtVnd((int)$sumSignedVnd)) ?></td>
                                            <td>
                                                <div style="display:flex; justify-content:space-between; gap: 8px; align-items:flex-start;">
                                                    <div><?= htmlspecialchars($commentText) ?></div>
                                                    <button type="button" class="finance-del btn tiny" style="padding:0 4px; flex:0 0 auto;" data-kind="tips" data-transfer-id="<?= (int)($f['transfer_id'] ?? 0) ?>" data-tx-id="<?= (int)($f['transaction_id'] ?? 0) ?>" data-date-to="<?= htmlspecialchars($dateTo) ?>" title="Скрыть транзакцию">✕</button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php elseif ($tipsDisabled): ?>
                            <?= htmlspecialchars($tipsDisabledReason) ?>
                        <?php else: ?>
                            <span style="color:var(--muted);">Транзакция не найдена</span>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
        <div class="card card-balances">
            <div style="display:flex; justify-content:flex-start; align-items:center; gap: 10px; margin-bottom: 10px; flex-wrap: wrap;">
                <div style="font-weight: 900;">Обновляем Балансы Poster</div>
                <div style="display:flex; gap: 8px; align-items:center;">
                    <button class="btn tiny" id="balanceSyncBtn" type="button" title="UPLD">UPLD</button>
                    <button class="btn tiny" id="posterAccountsBtn" type="button" title="Обновить балансы" style="padding: 4px 10px;">🔄</button>
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
                        <td style="font-weight:900;">Счет Андрей</td>
                        <td style="text-align:right;">
                            <span id="balAndrey" data-cents="<?= $posterBalanceAndrey !== null ? (int)$posterBalanceAndrey : '' ?>"><?= $posterBalanceAndrey !== null ? htmlspecialchars($fmtVndCents((int)$posterBalanceAndrey)) : '—' ?></span>
                        </td>
                        <td style="text-align:right;"><input id="balAndreyActual" type="text" inputmode="numeric" placeholder="0" style="text-align:right;"></td>
                        <td style="text-align:right;"><span id="balAndreyDiff">—</span></td>
                    </tr>
                    <tr data-key="vietnam">
                        <td style="font-weight:900;">Вьет. счет</td>
                        <td style="text-align:right;">
                            <span id="balVietnam" data-cents="<?= $posterBalanceVietnam !== null ? (int)$posterBalanceVietnam : '' ?>"><?= $posterBalanceVietnam !== null ? htmlspecialchars($fmtVndCents((int)$posterBalanceVietnam)) : '—' ?></span>
                        </td>
                        <td style="text-align:right;"><input id="balVietnamActual" type="text" inputmode="numeric" placeholder="0" style="text-align:right;"></td>
                        <td style="text-align:right;"><span id="balVietnamDiff">—</span></td>
                    </tr>
                    <tr data-key="cash">
                        <td style="font-weight:900;">Касса</td>
                        <td style="text-align:right;">
                            <span id="balCash" data-cents="<?= $posterBalanceCash !== null ? (int)$posterBalanceCash : '' ?>"><?= $posterBalanceCash !== null ? htmlspecialchars($fmtVndCents((int)$posterBalanceCash)) : '—' ?></span>
                        </td>
                        <td style="text-align:right;"><input id="balCashActual" type="text" inputmode="numeric" placeholder="0" style="text-align:right;"></td>
                        <td style="text-align:right;"><span id="balCashDiff">—</span></td>
                    </tr>
                    <tr data-key="total">
                        <td style="font-weight:900;">Total</td>
                        <td style="text-align:right;">
                            <span id="balTotal" data-cents="<?= $posterBalanceTotal !== null ? (int)$posterBalanceTotal : '' ?>"><?= $posterBalanceTotal !== null ? htmlspecialchars($fmtVndCents((int)$posterBalanceTotal)) : '—' ?></span>
                        </td>
                        <td style="text-align:right;"><input id="balTotalActual" type="text" inputmode="numeric" placeholder="0" style="text-align:right;" readonly></td>
                        <td style="text-align:right;"><span id="balTotalDiff">—</span></td>
                    </tr>
                    </tbody>
                </table>
            </div>

            <div class="bal-grid" style="max-height: 260px; overflow:auto;">
                <table style="width:100%; border-collapse: collapse;">
                    <thead>
                    <tr>
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
                        <tr><td colspan="3" style="padding: 10px; color:var(--muted); font-weight:900;">Нет данных: нажми 🔄</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        </div>
    </div>
</div>

<script>
window.__USER_EMAIL__ = <?= json_encode((string)($_SESSION['user_email'] ?? ''), JSON_UNESCAPED_UNICODE) ?>;
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
    const tabIn = document.getElementById('tabIn');
    const tabOut = document.getElementById('tabOut');
    const outSection = document.getElementById('outSection');
    const outMailBtn = document.getElementById('outMailBtn');
    const outFinanceBtn = document.getElementById('outFinanceBtn');
    const outSepayTable = document.getElementById('outSepayTable');
    const outPosterTable = document.getElementById('outPosterTable');
    const toggleOutMailHiddenBtn = document.getElementById('toggleOutMailHiddenBtn');
    const fetchJsonSafe = (url) => fetch(url).then(async (r) => { const txt = await r.text(); let j; try { j = JSON.parse(txt); } catch (e) { throw new Error('Bad JSON: ' + (txt || '(empty)')); } return j; });
    const posterMinorToVnd = (n) => {
        const x = Number(n || 0);
        return x / 100;
    };
    const fmtVnd2 = (v) => {
        try {
            return new Intl.NumberFormat('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(Math.round(Number(v) || 0)).replace(/,/g, '\u202F');
        } catch (_) {
            const num = Math.round(Number(v) || 0);
            return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '\u202F');
        }
    };
    const fmtVnd0 = (v) => {
        try {
            return new Intl.NumberFormat('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(Math.round(Number(v) || 0)).replace(/,/g, '\u202F');
        } catch (_) {
            const num = Math.round(Number(v) || 0);
            return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '\u202F');
        }
    };
    const formatOutDT = (txTime, dateStr) => {
        const s1 = String(txTime || '').trim();
        const s2 = String(dateStr || '').trim();
        if (s1 && /\d{2}\/\d{2}\/\d{4}\s+\d{2}:\d{2}:\d{2}/.test(s1)) {
            const m = s1.match(/^(\d{2}\/\d{2}\/\d{4})\s+(\d{2}:\d{2}:\d{2})$/);
            if (m) return { date: m[1], time: m[2] };
        }
        if (s2 && /\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}/.test(s2)) {
            const [dRaw, t] = s2.split(/\s+/);
            const [Y, M, D] = dRaw.split('-');
            return { date: `${D}/${M}/${Y}`, time: t };
        }
        return { date: '', time: '' };
    };
    const getDateRange = () => {
        const dfEl = document.querySelector('input[name="dateFrom"]');
        const dtEl = document.querySelector('input[name="dateTo"]');
        return { dateFrom: dfEl ? dfEl.value : '', dateTo: dtEl ? dtEl.value : '' };
    };
    const setBtnBusy = (btn, state) => {
        if (!btn) return () => {};
        const origHtml = btn.innerHTML;
        btn.dataset.origHtml = origHtml;
        const title = String(state && state.title ? state.title : btn.textContent || '').trim() || 'Загрузка';
        const pct = Number(state && state.pct != null ? state.pct : 0);
        const pctClamped = Math.max(0, Math.min(100, Math.round(pct)));
        btn.innerHTML = `<span class="btn-label">${escapeHtml(title)} <span class="btn-pct">${pctClamped}%</span></span><span class="btn-busy-line"><span class="btn-busy-fill" style="width:${pctClamped}%"></span></span>`;
        btn.classList.add('loading');
        btn.disabled = true;
        return () => {
            btn.classList.remove('loading');
            btn.disabled = false;
            btn.innerHTML = btn.dataset.origHtml || origHtml;
            delete btn.dataset.origHtml;
        };
    };
    const updateBtnBusy = (btn, state) => {
        if (!btn) return;
        const pctEl = btn.querySelector('.btn-pct');
        const fillEl = btn.querySelector('.btn-busy-fill');
        if (state && state.title != null) {
            const labelEl = btn.querySelector('.btn-label');
            if (labelEl && pctEl) {
                labelEl.innerHTML = escapeHtml(String(state.title)) + ' <span class="btn-pct">' + pctEl.textContent + '</span>';
            }
        }
        const pct = Number(state && state.pct != null ? state.pct : NaN);
        if (Number.isFinite(pct) && pctEl && fillEl) {
            const pctClamped = Math.max(0, Math.min(100, Math.round(pct)));
            pctEl.textContent = String(pctClamped) + '%';
            fillEl.style.width = String(pctClamped) + '%';
        }
    };
    let activeTab = 'in';
    const setTab = (m) => {
        const inOn = m === 'in';
        const tablesRoot = document.getElementById('tablesRoot');
        const lineLayer = document.getElementById('lineLayer');
        if (tablesRoot) tablesRoot.style.display = inOn ? '' : 'none';
        if (lineLayer) lineLayer.style.display = inOn ? '' : 'none';
        if (tabIn && tabOut) { tabIn.classList.toggle('active', inOn); tabOut.classList.toggle('active', !inOn); }
        if (outSection) outSection.style.display = inOn ? 'none' : '';
        if (outMailBtn) outMailBtn.style.display = inOn ? 'none' : '';
        if (outFinanceBtn) outFinanceBtn.style.display = inOn ? 'none' : '';
        const posterSyncForm = document.getElementById('posterSyncForm');
        const sepaySyncForm = document.getElementById('sepaySyncForm');
        const clearDayForm = document.getElementById('clearDayForm');
        if (posterSyncForm) posterSyncForm.style.display = inOn ? '' : 'none';
        if (sepaySyncForm) sepaySyncForm.style.display = inOn ? '' : 'none';
        if (clearDayForm) clearDayForm.style.display = inOn ? '' : 'none';
        activeTab = inOn ? 'in' : 'out';
        if (!inOn) outScheduleRelayout();
    };
    if (tabIn) tabIn.addEventListener('click', () => setTab('in'));
    if (tabOut) tabOut.addEventListener('click', () => setTab('out'));
    setTab('in');
    const loadOutMail = (onProgress) => {
        const { dateFrom, dateTo } = getDateRange();
        const qs = new URLSearchParams({ dateFrom, dateTo, include_hidden: '1' });
        if (typeof onProgress === 'function') onProgress(10, 'SePay: начало');
        return fetchJsonSafe(location.pathname + '?' + qs.toString() + '&ajax=mail_out').then((j) => {
            if (typeof onProgress === 'function') onProgress(50, 'SePay: письма загружены');
            if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка mail_out');
            const tbody = outSepayTable.tBodies[0]; tbody.innerHTML = '';
            (j.rows || []).forEach((row) => {
                const tr = document.createElement('tr');
                tr.setAttribute('data-mail-uid', String(row.mail_uid || 0));
                tr.setAttribute('data-sum', String(row.amount || 0));
                const isHidden = Number(row.is_hidden || 0) === 1;
                const hiddenComment = String(row.hidden_comment || '').trim();
                if (isHidden) {
                    tr.classList.add('row-hidden');
                    tr.setAttribute('data-hidden', '1');
                }
                const dt = formatOutDT(row.tx_time, row.date);
                const contentShow = (isHidden && hiddenComment) ? hiddenComment : String(row.content || '');
                tr.innerHTML = `
                    <td class="nowrap col-out-hide"><button type="button" class="sepay-hide out-hide" data-mail-uid="${Number(row.mail_uid || 0)}" title="Скрыть (не чек)">−</button></td>
                    <td class="col-out-content">${escapeHtml(contentShow)}</td>
                    <td class="nowrap col-out-time"><div class="col-out-date-part">${escapeHtml(dt.date)}</div><div class="col-out-time-part">${escapeHtml(dt.time)}</div></td>
                    <td class="sum col-out-sum">${Math.round(Number(row.amount || 0)).toLocaleString('en-US').replace(/,/g, '\u202F')}</td>
                    <td class="col-out-select"><input type="checkbox" class="out-sepay-cb" data-id="${Number(row.mail_uid || 0)}"></td>
                    <td class="col-out-anchor"><span class="anchor" id="out-sepay-${Number(row.mail_uid || 0)}"></span></td>
                `;
                tbody.appendChild(tr);
            });
            return fetchJsonSafe(location.pathname + '?ajax=out_links&dateTo=' + encodeURIComponent(dateTo));
        }).then((j2) => {
            if (typeof onProgress === 'function') onProgress(80, 'SePay: связи загружены');
            if (!j2 || !j2.ok) throw new Error((j2 && j2.error) ? j2.error : 'Ошибка out_links');
            outLinks.length = 0;
            outLinkByMail.clear();
            outLinkByFin.clear();
            (j2.links || []).forEach((l) => {
                const link = { mail_uid: Number(l.mail_uid || 0), finance_id: Number(l.finance_id || 0), link_type: String(l.link_type || ''), is_manual: !!l.is_manual };
                if (!link.mail_uid || !link.finance_id) return;
                outLinks.push(link);
                if (!outLinkByMail.has(link.mail_uid)) outLinkByMail.set(link.mail_uid, []);
                if (!outLinkByFin.has(link.finance_id)) outLinkByFin.set(link.finance_id, []);
                outLinkByMail.get(link.mail_uid).push(link);
                outLinkByFin.get(link.finance_id).push(link);
            });
            applyOutRowClasses();
            applyOutHideLinked();
            outScheduleRelayout();
            if (typeof onProgress === 'function') onProgress(100, 'SePay: готово');
        });
    };
    let employeesMap = null;
    let categoriesMap = null;
    const ensureEmployees = () => {
        if (employeesMap) return Promise.resolve(employeesMap);
        return fetchJsonSafe(location.pathname + '?ajax=poster_employees')
            .then((j) => {
                if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка poster_employees');
                employeesMap = j.employees || {};
                return employeesMap;
            });
    };
    const ensureCategories = () => {
        if (categoriesMap) return Promise.resolve(categoriesMap);
        return fetchJsonSafe(location.pathname + '?ajax=finance_categories')
            .then((j) => {
                if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка finance_categories');
                categoriesMap = j.categories || {};
                return categoriesMap;
            });
    };
    const loadOutFinance = (onProgress) => {
        const { dateFrom, dateTo } = getDateRange();
        const qs = new URLSearchParams({ dateFrom, dateTo });
        if (typeof onProgress === 'function') onProgress(10, 'Poster: пользователи/категории');
        return Promise.all([
            ensureEmployees(),
            ensureCategories(),
            fetchJsonSafe(location.pathname + '?' + qs.toString() + '&ajax=finance_out'),
        ]).then(([emps, cats, j]) => {
            if (typeof onProgress === 'function') onProgress(60, 'Poster: транзакции');
            if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка finance_out');
            const tbody = outPosterTable.tBodies[0]; tbody.innerHTML = '';
            (j.rows || []).forEach((row) => {
                const rawAmount = Number(row.amount || 0);
                const sign = rawAmount > 0 ? '+' : (rawAmount < 0 ? '−' : '');
                const amountVnd = posterMinorToVnd(Math.abs(rawAmount));
                const balanceVnd = posterMinorToVnd(Math.abs(Number(row.balance || 0)));
                const amountInt = Math.round(amountVnd);
                const balanceInt = Math.round(balanceVnd);
                const userName = String(emps && emps[Number(row.user_id || 0)] ? emps[Number(row.user_id || 0)] : row.user_id || '');
                let catName = String(cats && cats[Number(row.category_id || 0)] ? cats[Number(row.category_id || 0)] : row.category_id || '');
                if (catName === 'book_category_action_supplies') catName = 'поставки';
                const tr = document.createElement('tr');
                tr.setAttribute('data-finance-id', String(row.transaction_id || 0));
                tr.setAttribute('data-sum', String(amountInt));
                const dt2 = formatOutDT('', row.date);
                tr.innerHTML = `
                    <td class="nowrap"><div class="cell-anchor"><span class="anchor" id="out-poster-${Number(row.transaction_id || 0)}"></span><input type="checkbox" class="out-poster-cb" data-id="${Number(row.transaction_id || 0)}"></div></td>
                    <td class="nowrap col-out-date"><div class="col-out-date-date">${escapeHtml(dt2.date)}</div><div class="col-out-date-time">${escapeHtml(dt2.time)}</div></td>
                    <td class="col-out-user">${escapeHtml(userName)}</td>
                    <td class="col-out-category">${escapeHtml(catName)}</td>
                    <td class="col-out-type">${Number(row.type || 0)}</td>
                    <td class="sum col-out-amount">${sign}${fmtVnd0(amountInt)}</td>
                    <td class="sum col-out-balance">${fmtVnd0(balanceInt)}</td>
                    <td class="col-out-comment">${escapeHtml(row.comment || '')}</td>
                `;
                tbody.appendChild(tr);
            });
            applyOutRowClasses();
            applyOutHideLinked();
            if (typeof onProgress === 'function') onProgress(100, 'Poster: готово');
        });
    };
    if (outMailBtn) outMailBtn.addEventListener('click', () => {
        const restore = setBtnBusy(outMailBtn, { title: 'OUT SePay', pct: 0 });
        loadOutMail((pct) => updateBtnBusy(outMailBtn, { pct, title: 'OUT SePay' }))
            .catch((e) => alert(e && e.message ? e.message : 'Ошибка'))
            .finally(() => { restore(); outScheduleRelayout(); });
    });
    if (outFinanceBtn) outFinanceBtn.addEventListener('click', () => {
        const restore = setBtnBusy(outFinanceBtn, { title: 'OUT Poster', pct: 0 });
        loadOutFinance((pct) => updateBtnBusy(outFinanceBtn, { pct, title: 'OUT Poster' }))
            .catch((e) => alert(e && e.message ? e.message : 'Ошибка'))
            .finally(() => { restore(); outScheduleRelayout(); });
    });
    const dateForm = document.getElementById('dateForm');
    if (dateForm) {
        dateForm.addEventListener('submit', (ev) => {
            if (activeTab === 'out') {
                ev.preventDefault();
                const restoreMail = outMailBtn ? setBtnBusy(outMailBtn, { title: 'OUT SePay', pct: 0 }) : null;
                const restoreFin = outFinanceBtn ? setBtnBusy(outFinanceBtn, { title: 'OUT Poster', pct: 0 }) : null;
                Promise.all([
                    loadOutMail((pct) => updateBtnBusy(outMailBtn, { pct, title: 'OUT SePay' })),
                    loadOutFinance((pct) => updateBtnBusy(outFinanceBtn, { pct, title: 'OUT Poster' })),
                ])
                    .catch((e) => alert(e && e.message ? e.message : 'Ошибка'))
                    .finally(() => {
                        if (restoreMail) restoreMail();
                        if (restoreFin) restoreFin();
                        outScheduleRelayout();
                    });
            }
        });
    }

    const outLinkMakeBtn = document.getElementById('outLinkMakeBtn');
    const outHideLinkedBtn = document.getElementById('outHideLinkedBtn');
    const outLinkAutoBtn = document.getElementById('outLinkAutoBtn');
    const outLinkClearBtn = document.getElementById('outLinkClearBtn');
    const outSelSepaySumEl = document.getElementById('outSelSepaySum');
    const outSelPosterSumEl = document.getElementById('outSelPosterSum');
    const outSelDiffEl = document.getElementById('outSelDiff');
    const outSelMatchEl = document.getElementById('outSelMatch');
    const outGrid = document.getElementById('outGrid');
    const outLineLayer = document.getElementById('outLineLayer');
    const outSepayScroll = document.getElementById('outSepayScroll');
    const outPosterScroll = document.getElementById('outPosterScroll');
    const outWidgets = new Map();
    const outSvgState = { svg: null, defs: null, group: null };

    const outClearLines = () => {
        if (outSvgState.group) {
            while (outSvgState.group.firstChild) outSvgState.group.removeChild(outSvgState.group.firstChild);
        }
        Array.from(outWidgets.values()).forEach((btn) => { try { btn.remove(); } catch (_) {} });
        outWidgets.clear();
    };

    const outReloadLinks = (dateTo) => {
        return fetchJsonSafe(location.pathname + '?ajax=out_links&dateTo=' + encodeURIComponent(String(dateTo || '')))
            .then((j2) => {
                if (!j2 || !j2.ok) throw new Error((j2 && j2.error) ? j2.error : 'Ошибка out_links');
                outLinks.length = 0;
                outLinkByMail.clear();
                outLinkByFin.clear();
                (j2.links || []).forEach((lx) => {
                    const link = {
                        mail_uid: Number(lx.mail_uid || 0),
                        finance_id: Number(lx.finance_id || 0),
                        link_type: String(lx.link_type || ''),
                        is_manual: (lx.is_manual === true || lx.is_manual === 1 || lx.is_manual === '1')
                    };
                    if (!link.mail_uid || !link.finance_id) return;
                    outLinks.push(link);
                    if (!outLinkByMail.has(link.mail_uid)) outLinkByMail.set(link.mail_uid, []);
                    if (!outLinkByFin.has(link.finance_id)) outLinkByFin.set(link.finance_id, []);
                    outLinkByMail.get(link.mail_uid).push(link);
                    outLinkByFin.get(link.finance_id).push(link);
                });
                applyOutRowClasses();
                applyOutHideLinked();
                updateOutSelection();
                outScheduleRelayout();
            });
    };

    const outSyncButtons = () => {
        if (!outGrid) return;
        const keep = new Set();
        outLinks.forEach((l) => {
            const key = String(l.mail_uid) + ':' + String(l.finance_id);
            keep.add(key);
            if (outWidgets.has(key)) return;
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'link-x';
            btn.textContent = '×';
            btn.title = 'Удалить связь';
            btn.style.display = 'none';
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                const { dateTo } = getDateRange();
                fetch(location.pathname + '?ajax=out_unlink', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ dateTo, mail_uid: Number(l.mail_uid || 0), finance_id: Number(l.finance_id || 0) }),
                })
                .then((r) => r.json())
                .then((j) => {
                    if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка out_unlink');
                    return outReloadLinks(dateTo);
                })
                .catch((err) => alert(err && err.message ? err.message : 'Ошибка'));
            });
            outGrid.appendChild(btn);
            outWidgets.set(key, btn);
        });
        Array.from(outWidgets.entries()).forEach(([key, btn]) => {
            if (keep.has(key)) return;
            try { btn.remove(); } catch (_) {}
            outWidgets.delete(key);
        });
    };

    const outEnsureSvg = () => {
        if (!outLineLayer || !outGrid) return;
        if (outSvgState.svg) return;
        const ns = 'http://www.w3.org/2000/svg';
        const svg = document.createElementNS(ns, 'svg');
        svg.setAttribute('width', '100%');
        svg.setAttribute('height', '100%');
        svg.style.display = 'block';
        const defs = document.createElementNS(ns, 'defs');
        const g = document.createElementNS(ns, 'g');
        svg.appendChild(defs);
        svg.appendChild(g);
        outLineLayer.appendChild(svg);
        outSvgState.svg = svg;
        outSvgState.defs = defs;
        outSvgState.group = g;
    };

    const outIsVisibleInScrollY = (el, scrollEl) => {
        if (!el || !scrollEl) return false;
        const tr = el.closest('tr');
        if (!tr) return false;
        if (!tr.getClientRects().length) return false;
        if (tr.style.display === 'none') return false;
        const r = tr.getBoundingClientRect();
        const sr = scrollEl.getBoundingClientRect();
        return r.bottom >= sr.top && r.top <= sr.bottom;
    };

    const outDrawLines = () => {
        outEnsureSvg();
        outClearLines();
        outSyncButtons();
        if (!outGrid || !outSvgState.svg || !outSvgState.group) return;
        const rootRect = outGrid.getBoundingClientRect();
        const w = Math.max(1, Math.round(rootRect.width));
        const h = Math.max(1, Math.round(rootRect.height));
        const scrollW = outGrid.scrollWidth || w;
        outSvgState.svg.setAttribute('viewBox', `0 0 ${scrollW} ${h}`);
        outSvgState.svg.style.width = scrollW + 'px';

        outLinks.forEach((l) => {
            const s = document.getElementById('out-sepay-' + l.mail_uid);
            const p = document.getElementById('out-poster-' + l.finance_id);
            if (!s || !p) return;
            if (!s.getClientRects().length || !p.getClientRects().length) return;
            if (outSepayScroll && !outIsVisibleInScrollY(s, outSepayScroll)) return;
            if (outPosterScroll && !outIsVisibleInScrollY(p, outPosterScroll)) return;
            const size = 2;
            const color = colorFor(l.link_type, l.link_type === 'manual');

            const a0 = outGetAnchorPoint(s, 'right', rootRect);
            const b0 = outGetAnchorPoint(p, 'left', rootRect);
            if (a0.y < 0 || b0.y < 0 || a0.y > h || b0.y > h) return;
            
            if (outSepayScroll) {
                const sr = outSepayScroll.getBoundingClientRect();
                a0.x = Math.max(sr.left - rootRect.left + outGrid.scrollLeft, Math.min(sr.right - rootRect.left + outGrid.scrollLeft, a0.x));
            }
            if (outPosterScroll) {
                const sr = outPosterScroll.getBoundingClientRect();
                b0.x = Math.max(sr.left - rootRect.left + outGrid.scrollLeft, Math.min(sr.right - rootRect.left + outGrid.scrollLeft, b0.x));
            }

            const clamp = (v, min, max) => Math.max(min, Math.min(max, v));
            const a = { x: clamp(a0.x, 0, w + outGrid.scrollLeft), y: clamp(a0.y, 0, h) };
            const b = { x: clamp(b0.x, 0, w + outGrid.scrollLeft), y: clamp(b0.y, 0, h) };
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
            outSvgState.group.appendChild(outline);

            const path = document.createElementNS(ns, 'path');
            path.setAttribute('d', d);
            path.setAttribute('fill', 'none');
            path.setAttribute('stroke', color);
            path.setAttribute('stroke-width', String(size));
            path.setAttribute('stroke-linecap', 'round');
            path.setAttribute('stroke-linejoin', 'round');
            outSvgState.group.appendChild(path);

            const key = String(l.mail_uid) + ':' + String(l.finance_id);
            const btn = outWidgets.get(key);
            if (btn) {
                const dxBtn = b.x - a.x;
                const dyBtn = b.y - a.y;
                const lenBtn = Math.hypot(dxBtn, dyBtn) || 1;
                const insetPx = 6;
                const tBtn = Math.min(0.99, Math.max(0.75, 1 - (insetPx / lenBtn)));
                const mx = a.x + dxBtn * tBtn;
                const my = a.y + dyBtn * tBtn;
                const localX = Math.max(8, Math.min(scrollW - 8, mx));
                const localY = Math.max(8, Math.min(h - 8, my));
                btn.style.left = Math.round(localX - 8) + 'px';
                btn.style.top = Math.round(localY - 8) + 'px';
                btn.style.display = 'flex';
            }
        });
    };

    const outPositionLines = () => outDrawLines();
    const outScheduleRelayout = () => {
        requestAnimationFrame(outPositionLines);
        setTimeout(outPositionLines, 50);
        setTimeout(outPositionLines, 200);
        setTimeout(outPositionLines, 600);
    };
    if (outGrid) {
        outGrid.addEventListener('scroll', () => outScheduleRelayout(), { passive: true, capture: true });
    }
    if (outSepayScroll) {
        outSepayScroll.addEventListener('scroll', () => outScheduleRelayout(), { passive: true });
    }
    if (outPosterScroll) {
        outPosterScroll.addEventListener('scroll', () => outScheduleRelayout(), { passive: true });
    }
    window.addEventListener('resize', () => outScheduleRelayout(), { passive: true });

    let outHideLinkedOn = false;
    let showOutMailHidden = false;
    try { showOutMailHidden = localStorage.getItem('payday_show_out_mail_hidden') === '1'; } catch (e) {}
    const outLinks = [];
    const outLinkByMail = new Map();
    const outLinkByFin = new Map();
    const outSelectedMail = new Set();
    const outSelectedFin = new Set();

    const updateOutSelection = () => {
        const mailRows = Array.from(outSepayTable.tBodies[0]?.rows || []);
        const finRows = Array.from(outPosterTable.tBodies[0]?.rows || []);
        const sumMail = mailRows.reduce((acc, tr) => {
            const cb = tr.querySelector('input.out-sepay-cb');
            const id = cb ? Number(cb.getAttribute('data-id') || 0) : 0;
            if (!id || !outSelectedMail.has(id)) return acc;
            return acc + Number(tr.getAttribute('data-sum') || 0);
        }, 0);
        const sumFin = finRows.reduce((acc, tr) => {
            const cb = tr.querySelector('input.out-poster-cb');
            const id = cb ? Number(cb.getAttribute('data-id') || 0) : 0;
            if (!id || !outSelectedFin.has(id)) return acc;
            return acc + Number(tr.getAttribute('data-sum') || 0);
        }, 0);
        const diff = Math.abs(sumMail - sumFin);
        if (outSelSepaySumEl) outSelSepaySumEl.textContent = Math.round(Number(sumMail)).toLocaleString('en-US').replace(/,/g, '\u202F');
        if (outSelPosterSumEl) outSelPosterSumEl.textContent = Math.round(Number(sumFin)).toLocaleString('en-US').replace(/,/g, '\u202F');
        if (outSelDiffEl) outSelDiffEl.textContent = Math.round(Number(diff)).toLocaleString('en-US').replace(/,/g, '\u202F');
        if (outSelMatchEl) outSelMatchEl.style.color = diff === 0 ? '#16a34a' : '#dc2626';
        if (outLinkMakeBtn) outLinkMakeBtn.disabled = (outSelectedMail.size === 0 || outSelectedFin.size === 0);
    };

    const applyOutRowClasses = () => {
        const mailRows = Array.from(outSepayTable.tBodies[0]?.rows || []);
        mailRows.forEach((tr) => {
            tr.classList.remove('row-red', 'row-gray', 'row-green', 'row-yellow');
            const uid = Number(tr.getAttribute('data-mail-uid') || 0);
            if (uid && outLinkByMail.has(uid)) {
                const arr = outLinkByMail.get(uid) || [];
                const hasManual = arr.some((l) => l && (l.is_manual || l.link_type === 'manual'));
                const hasYellow = arr.some((l) => l && l.link_type === 'auto_yellow');
                if (hasManual) tr.classList.add('row-gray');
                else if (hasYellow) tr.classList.add('row-yellow');
                else tr.classList.add('row-green');
            } else {
                tr.classList.add('row-red');
            }
        });
        const finRows = Array.from(outPosterTable.tBodies[0]?.rows || []);
        finRows.forEach((tr) => {
            tr.classList.remove('row-red', 'row-gray', 'row-green', 'row-yellow');
            const fid = Number(tr.getAttribute('data-finance-id') || 0);
            if (fid && outLinkByFin.has(fid)) {
                const arr = outLinkByFin.get(fid) || [];
                const hasManual = arr.some((l) => l && (l.is_manual || l.link_type === 'manual'));
                const hasYellow = arr.some((l) => l && l.link_type === 'auto_yellow');
                if (hasManual) tr.classList.add('row-gray');
                else if (hasYellow) tr.classList.add('row-yellow');
                else tr.classList.add('row-green');
            } else {
                tr.classList.add('row-red');
            }
        });
    };

    const applyOutHideLinked = () => {
        const mailRows = Array.from(outSepayTable.tBodies[0]?.rows || []);
        mailRows.forEach((tr) => {
            const uid = Number(tr.getAttribute('data-mail-uid') || 0);
            const isHiddenRow = String(tr.getAttribute('data-hidden') || '0') === '1';
            const isLinked = uid && outLinkByMail.has(uid);
            const hidden = (outHideLinkedOn && isLinked) || (isHiddenRow && !showOutMailHidden);
            tr.style.display = hidden ? 'none' : '';
            if (hidden) {
                const cb = tr.querySelector('input.out-sepay-cb');
                if (cb) cb.checked = false;
                outSelectedMail.delete(uid);
            }
        });
        const finRows = Array.from(outPosterTable.tBodies[0]?.rows || []);
        finRows.forEach((tr) => {
            if (!outHideLinkedOn) { tr.style.display = ''; return; }
            const fid = Number(tr.getAttribute('data-finance-id') || 0);
            tr.style.display = (fid && outLinkByFin.has(fid)) ? 'none' : '';
        });
    };

    document.addEventListener('change', (ev) => {
        const t = ev.target;
        if (!(t instanceof HTMLInputElement)) return;
        if (t.classList.contains('out-sepay-cb')) {
            const id = Number(t.getAttribute('data-id') || 0);
            if (!id) return;
            if (t.checked) outSelectedMail.add(id); else outSelectedMail.delete(id);
            updateOutSelection();
        }
        if (t.classList.contains('out-poster-cb')) {
            const id = Number(t.getAttribute('data-id') || 0);
            if (!id) return;
            if (t.checked) outSelectedFin.add(id); else outSelectedFin.delete(id);
            updateOutSelection();
        }
    });

    if (outLinkMakeBtn) outLinkMakeBtn.addEventListener('click', () => {
        const mails = Array.from(outSelectedMail);
        const fins = Array.from(outSelectedFin);
        const pairs = [];
        if (mails.length === 1 && fins.length >= 1) {
            const uid = mails[0];
            fins.forEach((fid) => {
                if (!uid || !fid) return;
                pairs.push({ mail_uid: uid, finance_id: fid });
            });
        } else if (fins.length === 1 && mails.length >= 1) {
            const fid = fins[0];
            mails.forEach((uid) => {
                if (!uid || !fid) return;
                pairs.push({ mail_uid: uid, finance_id: fid });
            });
        } else {
            const n = Math.min(mails.length, fins.length);
            for (let i = 0; i < n; i++) {
                const uid = mails[i], fid = fins[i];
                if (!uid || !fid) continue;
                pairs.push({ mail_uid: uid, finance_id: fid });
            }
        }
        const { dateTo } = getDateRange();
        fetch(location.pathname + '?ajax=out_manual_link', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ dateTo, links: pairs }),
        })
        .then((r) => r.json())
        .then((j) => {
            if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка out_manual_link');
            return fetchJsonSafe(location.pathname + '?ajax=out_links&dateTo=' + encodeURIComponent(dateTo));
        })
        .then((j2) => {
            if (!j2 || !j2.ok) throw new Error((j2 && j2.error) ? j2.error : 'Ошибка out_links');
            outLinks.length = 0;
            outLinkByMail.clear();
            outLinkByFin.clear();
            (j2.links || []).forEach((l) => {
                const link = { mail_uid: Number(l.mail_uid || 0), finance_id: Number(l.finance_id || 0), link_type: String(l.link_type || ''), is_manual: !!l.is_manual };
                if (!link.mail_uid || !link.finance_id) return;
                outLinks.push(link);
                if (!outLinkByMail.has(link.mail_uid)) outLinkByMail.set(link.mail_uid, []);
                if (!outLinkByFin.has(link.finance_id)) outLinkByFin.set(link.finance_id, []);
                outLinkByMail.get(link.mail_uid).push(link);
                outLinkByFin.get(link.finance_id).push(link);
            });
            applyOutRowClasses();
            applyOutHideLinked();
            updateOutSelection();
            outScheduleRelayout();
        })
        .catch((e) => alert(e && e.message ? e.message : 'Ошибка'));
    });

    if (outLinkClearBtn) outLinkClearBtn.addEventListener('click', () => {
        const { dateTo } = getDateRange();
        fetch(location.pathname + '?ajax=out_clear_links', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ dateTo }),
        })
        .then((r) => r.json())
        .then((j) => {
            if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка out_clear_links');
            outSelectedMail.clear();
            outSelectedFin.clear();
            Array.from(outSepayTable.querySelectorAll('input.out-sepay-cb')).forEach((cb) => { cb.checked = false; });
            Array.from(outPosterTable.querySelectorAll('input.out-poster-cb')).forEach((cb) => { cb.checked = false; });
            outHideLinkedOn = false;
            return outReloadLinks(dateTo);
        })
        .catch((e) => alert(e && e.message ? e.message : 'Ошибка'));
    });

    if (outHideLinkedBtn) outHideLinkedBtn.addEventListener('click', () => {
        outHideLinkedOn = !outHideLinkedOn;
        applyOutHideLinked();
        outScheduleRelayout();
    });

    if (toggleOutMailHiddenBtn) {
        toggleOutMailHiddenBtn.classList.toggle('on', showOutMailHidden);
        toggleOutMailHiddenBtn.addEventListener('click', () => {
            showOutMailHidden = !showOutMailHidden;
            try { localStorage.setItem('payday_show_out_mail_hidden', showOutMailHidden ? '1' : '0'); } catch (e) {}
            toggleOutMailHiddenBtn.classList.toggle('on', showOutMailHidden);
            applyOutHideLinked();
            outScheduleRelayout();
        });
    }

    if (outLinkAutoBtn) outLinkAutoBtn.addEventListener('click', () => {
        const mailRows = Array.from(outSepayTable.tBodies[0]?.rows || []);
        const finRows = Array.from(outPosterTable.tBodies[0]?.rows || []);
        const finBySum = new Map();
        finRows.forEach((tr) => {
            const fid = Number(tr.getAttribute('data-finance-id') || 0);
            if (!fid || outLinkByFin.has(fid)) return;
            const sum = Number(tr.getAttribute('data-sum') || 0);
            if (!finBySum.has(sum)) finBySum.set(sum, []);
            finBySum.get(sum).push(fid);
        });
        const pairs = [];
        mailRows.forEach((tr) => {
            const uid = Number(tr.getAttribute('data-mail-uid') || 0);
            if (!uid || outLinkByMail.has(uid)) return;
            const sum = Number(tr.getAttribute('data-sum') || 0);
            const arr = finBySum.get(sum);
            if (!arr || arr.length === 0) return;
            const fid = arr.shift();
            const lt = (arr.length === 0) ? 'auto_green' : 'auto_yellow';
            pairs.push({ mail_uid: uid, finance_id: fid, link_type: lt });
        });
        if (pairs.length === 0) {
            alert('Нет совпадений для автосвязи по сумме');
            return;
        }
        const { dateTo } = getDateRange();
        fetch(location.pathname + '?ajax=out_auto_link', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ dateTo, links: pairs }),
        })
        .then((r) => r.json())
        .then((j) => {
            if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка out_auto_link');
            return fetchJsonSafe(location.pathname + '?ajax=out_links&dateTo=' + encodeURIComponent(dateTo));
        })
        .then((j2) => {
            if (!j2 || !j2.ok) throw new Error((j2 && j2.error) ? j2.error : 'Ошибка out_links');
            outLinks.length = 0;
            outLinkByMail.clear();
            outLinkByFin.clear();
            (j2.links || []).forEach((l) => {
                const link = { mail_uid: Number(l.mail_uid || 0), finance_id: Number(l.finance_id || 0), link_type: String(l.link_type || ''), is_manual: !!l.is_manual };
                if (!link.mail_uid || !link.finance_id) return;
                outLinks.push(link);
                if (!outLinkByMail.has(link.mail_uid)) outLinkByMail.set(link.mail_uid, []);
                if (!outLinkByFin.has(link.finance_id)) outLinkByFin.set(link.finance_id, []);
                outLinkByMail.get(link.mail_uid).push(link);
                outLinkByFin.get(link.finance_id).push(link);
            });
            applyOutRowClasses();
            applyOutHideLinked();
            updateOutSelection();
            outScheduleRelayout();
        })
        .catch((e) => alert(e && e.message ? e.message : 'Ошибка'));
    });

    document.addEventListener('click', (ev) => {
        const t = ev.target;
        if (!(t instanceof HTMLElement)) return;
        if (t.classList.contains('out-hide')) {
            const uid = Number(t.getAttribute('data-mail-uid') || 0);
            const { dateTo } = getDateRange();
            if (!uid || !dateTo) return;
            const c = prompt('Комментарий (почему скрываем):', '');
            if (c === null) return;
            const comment = String(c || '').trim();
            fetch(location.pathname + '?ajax=mail_hide', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ mail_uid: uid, dateTo, comment }),
                credentials: 'same-origin',
            })
                .then((r) => r.json())
                .then((j) => {
                    if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
                    const tr = t.closest('tr');
                    if (tr) {
                        tr.classList.add('row-hidden');
                        tr.setAttribute('data-hidden', '1');
                        tr.setAttribute('data-content', comment.toLowerCase());
                        const td = tr.querySelector('td.col-out-content');
                        if (td) td.textContent = comment || 'Скрыто';
                        const cb = tr.querySelector('input.out-sepay-cb');
                        if (cb) cb.checked = false;
                    }
                    outSelectedMail.delete(uid);
                    applyOutHideLinked();
                    updateOutSelection();
                    outScheduleRelayout();
                })
                .catch((e) => alert(e && e.message ? e.message : 'Ошибка'));
        }
    });

    const setFormLoading = (formId, btnId, defaultTitle, defaultStep) => {
        const form = document.getElementById(formId);
        const btn = document.getElementById(btnId);
        if (!form || !btn) return;
        form.addEventListener('submit', async (ev) => {
            ev.preventDefault();
            const restore = setBtnBusy(btn, { title: defaultStep || 'Загрузка…', pct: 0 });
            try {
                const fd = new FormData(form);
                fd.append('ajax', '1');
                const res = await fetch(location.href, { method: 'POST', body: fd });
                if (!res.body) throw new Error('No response body');
                const reader = res.body.getReader();
                const decoder = new TextDecoder();
                let buffer = '';
                while (true) {
                    const { done, value } = await reader.read();
                    if (done) break;
                    buffer += decoder.decode(value, { stream: true });
                    const lines = buffer.split('\n');
                    buffer = lines.pop();
                    for (const line of lines) {
                        if (!line.trim()) continue;
                        try {
                            const j = JSON.parse(line);
                            if (j.pct !== undefined) {
                                updateBtnBusy(btn, { pct: j.pct, title: j.step || defaultStep });
                            }
                            if (j.ok) {
                                updateBtnBusy(btn, { pct: 100, title: 'Готово. Обновление...' });
                                setTimeout(() => { window.location.href = window.location.href; }, 400);
                                return;
                            }
                        } catch(e) {}
                    }
                }
                window.location.href = window.location.href;
            } catch (err) {
                alert(err && err.message ? err.message : 'Ошибка');
                restore();
            }
        });
    };
    setFormLoading('posterSyncForm', 'posterSyncBtn', 'IN', 'Poster: загрузка чеков');
    setFormLoading('sepaySyncForm', 'sepaySyncBtn', 'IN', 'SePay: загрузка платежей');
    setFormLoading('clearDayForm', 'clearDayBtn', 'IN', 'Очистка дня');

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

    const fmtIntSpaces = (n) => String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '\u202F');
    const fmtVndCentsJs = (cents) => {
        const c = Number(cents || 0) || 0;
        const neg = c < 0;
        const abs = Math.abs(c);
        const i = Math.round(abs / 100);
        return (neg && i > 0 ? '-' : '') + fmtIntSpaces(i);
    };
    const parseVndCentsJs = (raw) => {
        const s = String(raw || '').trim();
        if (!s) return null;
        const cleaned = s.replace(/[^\d.,-]/g, '').replaceAll('\u202F', '').replaceAll(' ', '').replaceAll(',', '.').trim();
        if (!cleaned) return null;
        const n = Number(cleaned);
        if (!Number.isFinite(n)) return null;
        return Math.round(n * 100);
    };
    const digitsOnly = (s) => String(s || '').replace(/\D+/g, '');
    const fmtDigitsSpaces = (digits) => {
        const d = String(digits || '').replace(/\D+/g, '');
        if (!d) return '';
        const norm = d.replace(/^0+(?=\d)/, '');
        return norm.replace(/\B(?=(\d{3})+(?!\d))/g, '\u202F');
    };
    const sanitizeInputVndInt = (el) => {
        if (!el) return;
        const v = digitsOnly(el.value);
        el.value = fmtDigitsSpaces(v);
    };
    const updateTotalActual = () => {
        const a = Number(digitsOnly(balAndreyActualEl ? balAndreyActualEl.value : '')) || 0;
        const v = Number(digitsOnly(balVietnamActualEl ? balVietnamActualEl.value : '')) || 0;
        const c = Number(digitsOnly(balCashActualEl ? balCashActualEl.value : '')) || 0;
        const sum = a + v + c;
        if (balTotalActualEl) balTotalActualEl.value = fmtDigitsSpaces(String(sum));
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
                    posterAccountsTbody.innerHTML = '<tr><td colspan="3" style="padding: 10px; color:var(--muted); font-weight:900;">Нет данных</td></tr>';
                } else {
                    posterAccountsTbody.innerHTML = rows.map((a) => {
                        const id = Number(a.account_id || 0);
                        const name = String(a.name || '');
                        const bal = String(a.balance || '0');
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
    sanitizeInputVndInt(balAndreyActualEl);
    sanitizeInputVndInt(balVietnamActualEl);
    sanitizeInputVndInt(balCashActualEl);
    updateTotalActual();
    updateBalanceDiffs();

    [balAndreyActualEl, balVietnamActualEl, balCashActualEl].forEach((el) => {
        if (!el) return;
        el.addEventListener('input', () => {
            sanitizeInputVndInt(el);
            updateTotalActual();
            updateBalanceDiffs();
        }, { passive: true });
    });

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
                const ok = confirm(`${action} ${sum} на ${accLabel}?\nКомментарий: ${String(p.comment || '')}`);
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
        // Shift X coordinate by tablesRoot scrollLeft
        const scrollLeft = tablesRoot ? tablesRoot.scrollLeft : 0;
        const cx = (r.left + r.width / 2) - rootRect.left + scrollLeft;
        const cy = (r.top + r.height / 2) - rootRect.top;
        const x = Math.round(cx) + 0.5;
        let y = Math.round(cy) + 0.5;
        return { x, y };
    };

    const outGetAnchorPoint = (el, side, rootRect) => {
        const r = el.getBoundingClientRect();
        // Shift X coordinate by outGrid scrollLeft
        const scrollLeft = outGrid ? outGrid.scrollLeft : 0;
        const cx = (r.left + r.width / 2) - rootRect.left + scrollLeft;
        const cy = (r.top + r.height / 2) - rootRect.top;
        const x = Math.round(cx) + 0.5;
        let y = Math.round(cy) + 0.5;
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
            return new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(Math.round(Number(v) || 0)).replace(/,/g, '\u202F');
        } catch (_) {
            return String(Math.round(Number(v) || 0)).replace(/\B(?=(\d{3})+(?!\d))/g, '\u202F');
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
            if (tr.style && tr.style.display === 'none') return;
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
            if (tr.style && tr.style.display === 'none') return;
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
        
        const scrollW = tablesRoot.scrollWidth || w;
        svgState.svg.setAttribute('viewBox', `0 0 ${scrollW} ${h}`);
        svgState.svg.style.width = scrollW + 'px';
        
        widgets.forEach((btn) => { btn.style.display = 'none'; });

        const isVisibleInScrollY = (el, scrollEl) => {
            if (!el || !scrollEl) return false;
            const tr = el.closest('tr');
            if (!tr) return false;
            if (!tr.getClientRects().length) return false;
            if (tr.style.display === 'none') return false;
            const r = tr.getBoundingClientRect();
            const sr = scrollEl.getBoundingClientRect();
            return r.bottom >= sr.top && r.top <= sr.bottom;
        };

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
            if (sepayScroll && !isVisibleInScrollY(s, sepayScroll)) return;
            if (posterScroll && !isVisibleInScrollY(p, posterScroll)) return;
            const isMany = (sepayCount[l.sepay_id] || 0) > 1 || (posterCount[l.poster_transaction_id] || 0) > 1;
            const isMainGreen = !isMany && !l.is_manual && l.link_type === 'auto_green';
            const size = 2;
            const color = colorFor(l.link_type, l.is_manual);

            const a0 = getAnchorPoint(s, 'right', rootRect);
            const b0 = getAnchorPoint(p, 'left', rootRect);
            
            if (a0.y < -50 || b0.y < -50 || a0.y > h + 50 || b0.y > h + 50) return;

            if (sepayScroll) {
                const sr = sepayScroll.getBoundingClientRect();
                a0.x = Math.max(sr.left - rootRect.left + tablesRoot.scrollLeft, Math.min(sr.right - rootRect.left + tablesRoot.scrollLeft, a0.x));
            }
            if (posterScroll) {
                const sr = posterScroll.getBoundingClientRect();
                b0.x = Math.max(sr.left - rootRect.left + tablesRoot.scrollLeft, Math.min(sr.right - rootRect.left + tablesRoot.scrollLeft, b0.x));
            }

            const clamp = (v, min, max) => Math.max(min, Math.min(max, v));
            const a = { x: clamp(a0.x, 0, w + tablesRoot.scrollLeft), y: clamp(a0.y, 0, h) };
            const b = { x: clamp(b0.x, 0, w + tablesRoot.scrollLeft), y: clamp(b0.y, 0, h) };

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
                const localX = Math.max(8, Math.min(scrollW - 8, mx));
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
    let hideVietnam = false;
    try { hideVietnam = localStorage.getItem('payday_hide_vietnam') === '1'; } catch (e) {}
    const toggleVietnamBtn = document.getElementById('toggleVietnamBtn');
    let showSepayHidden = false;
    try { showSepayHidden = localStorage.getItem('payday_show_sepay_hidden') === '1'; } catch (e) {}
    const toggleSepayHiddenBtn = document.getElementById('toggleSepayHiddenBtn');

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

    const updateVietnamButtonState = () => {
        if (!toggleVietnamBtn) return;
        toggleVietnamBtn.classList.toggle('on', hideVietnam);
    };
    const updateSepayHiddenButtonState = () => {
        if (!toggleSepayHiddenBtn) return;
        toggleSepayHiddenBtn.classList.toggle('on', showSepayHidden);
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
            const isHiddenRow = String(tr.getAttribute('data-hidden') || '0') === '1';
            const hidden = (hideLinked && linked) || (isHiddenRow && !showSepayHidden);
            tr.style.display = hidden ? 'none' : '';
            if (hidden) {
                const cb = tr.querySelector('input.sepay-cb');
                if (cb) cb.checked = false;
                selectedSepay.delete(sid);
            }
        });
        document.querySelectorAll('#posterTable tbody tr[data-poster-id]').forEach((tr) => {
            const isVietnam = String(tr.getAttribute('data-vietnam') || '0') === '1';
            const pid = Number(tr.getAttribute('data-poster-id') || 0);
            const linked = state.poster.has(pid);
            const hidden = (hideLinked && linked) || (hideVietnam && isVietnam);
            tr.style.display = hidden ? 'none' : '';
            if (hidden) {
                const cb = tr.querySelector('input.poster-cb');
                if (cb) cb.checked = false;
                selectedPoster.delete(pid);
            }
        });
        updateLinkButtonState();
        updateStats();
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
                if (tr) {
                    tr.classList.add('row-hidden');
                    tr.setAttribute('data-hidden', '1');
                    tr.setAttribute('data-content', String(c).toLowerCase());
                    const td = tr.querySelector('td.col-sepay-content');
                    if (td) td.textContent = c;
                }
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
            const btn = form.querySelector('button[type="submit"]');
            const statusEl = form.querySelector('.finance-status');
            if (btn && btn.disabled) return;
            const kind = String(form.getAttribute('data-kind') || '');
            const dateFrom = String(form.getAttribute('data-date-from') || '');
            const dateTo = String(form.getAttribute('data-date-to') || '');
            if (!kind || !dateFrom || !dateTo) return;
            const creatorEmail = String(window.__USER_EMAIL__ || '').trim();
            const commentBase = (kind === 'vietnam') ? 'Перевод чеков вьетнаской компании' : 'Перевод типсов';
            const comment = creatorEmail ? (commentBase + ' by ' + creatorEmail) : commentBase;
            const accFromName = String(form.getAttribute('data-account-from-name') || '#1');
            const accToName = String(form.getAttribute('data-account-to-name') || (kind === 'vietnam' ? '#9' : '#8'));
            const sumVnd = Number(form.getAttribute('data-sum-vnd') || 0);
            const sumTxt = sumVnd ? (Math.round(Number(sumVnd)).toLocaleString('en-US').replace(/,/g, '\u202F')) : '—';

            const openConfirm = () => new Promise((resolve) => {
                const backdrop = document.getElementById('financeConfirm');
                const text = document.getElementById('financeConfirmText');
                const cb = document.getElementById('financeConfirmChecked');
                const ok = document.getElementById('financeConfirmOk');
                const cancel = document.getElementById('financeConfirmCancel');
                if (!backdrop || !text || !cb || !ok || !cancel) return resolve(false);
                text.innerHTML =
                    `Будет создан перевод в Poster.<br>` +
                    `Счет списания: <b>${escapeHtml(accFromName)}</b><br>` +
                    `Счет зачисления: <b>${escapeHtml(accToName)}</b><br>` +
                    `Сумма: <b>${escapeHtml(sumTxt)}</b><br>` +
                    `Комментарий: <b>${escapeHtml(comment)}</b><br>` +
                    `Создатель: <b>${escapeHtml(creatorEmail || '—')}</b>`;
                cb.checked = false;
                ok.disabled = true;
                backdrop.style.display = 'flex';

                const close = (v) => {
                    backdrop.style.display = 'none';
                    cancel.removeEventListener('click', onCancel);
                    ok.removeEventListener('click', onOk);
                    cb.removeEventListener('change', onCb);
                    backdrop.removeEventListener('click', onBg);
                    document.removeEventListener('keydown', onEsc, true);
                    resolve(v);
                };
                const onCb = () => { ok.disabled = !cb.checked; };
                const onCancel = () => close(false);
                const onOk = () => close(true);
                const onBg = (ev) => { if (ev.target === backdrop) close(false); };
                const onEsc = (ev) => { if (ev.key === 'Escape' && backdrop.style.display === 'flex') close(false); };

                cb.addEventListener('change', onCb);
                cancel.addEventListener('click', onCancel);
                ok.addEventListener('click', onOk);
                backdrop.addEventListener('click', onBg);
                document.addEventListener('keydown', onEsc, true);
                cancel.focus();
            });

            openConfirm().then((confirmed) => {
                if (!confirmed) return;
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
                    if (btn) {
                        btn.classList.remove('loading');
                        btn.disabled = true;
                    }
                    if (typeof refreshFinanceForm === 'function') {
                        refreshFinanceForm(form, { showLoading: false });
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
    });
    const bindFinanceDeleteBtn = (btn) => {
        if (!btn) return;
        if (btn.dataset.bound === '1') return;
        btn.dataset.bound = '1';
        btn.addEventListener('click', () => {
            const transferId = Number(btn.getAttribute('data-transfer-id') || 0);
            const txId = Number(btn.getAttribute('data-tx-id') || 0);
            const dateTo = String(btn.getAttribute('data-date-to') || '');
            const kind = String(btn.getAttribute('data-kind') || '');
            if ((!transferId && !txId) || !dateTo || !kind) return;
            const comment = prompt('Комментарий (почему скрываем):', '');
            if (comment === null) return;
            const c = String(comment || '').trim();
            fetch('?ajax=delete_finance_transfer', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ kind, transfer_id: transferId, tx_id: txId, dateTo, comment: c }),
            })
            .then((r) => r.json())
            .then((j) => {
                if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
                window.location.href = window.location.href;
            })
            .catch((e) => alert(e && e.message ? e.message : 'Ошибка'));
        });
    };
    const bindFinanceDeleteBtns = (root) => {
        (root || document).querySelectorAll('button.finance-del').forEach(bindFinanceDeleteBtn);
    };
    bindFinanceDeleteBtns(document);

    const renderFinanceTable = (form, rows) => {
        const kind = String(form.getAttribute('data-kind') || '');
        const dateTo = String(form.getAttribute('data-date-to') || '');
        const expectedSum = Number(form.getAttribute('data-sum-vnd') || 0);
        const statusEl = form.querySelector('.finance-status');
        if (!statusEl) return;

        const pad = (n) => String(n).padStart(2, '0');
        const fmtSum = (v) => Math.round(Number(v || 0)).toLocaleString('en-US').replace(/,/g, '\u202F');

        const match = rows.filter((x) => Number(x.sum || 0) === expectedSum);
        if (!match.length) {
            statusEl.innerHTML = '<span style="color:var(--muted);">Транзакция не найдена</span>';
            return;
        }

        let html = '<div style="overflow-x:auto; max-width:100%;">';
        html += '<table class="table" style="margin-top:5px; font-size:12px; width:100%;">';
        html += '<thead><tr><th style="padding:2px 4px;">Дата<br><span style="font-weight:normal;">Время</span></th><th style="padding:2px 4px;">Сумма</th><th style="padding:2px 4px;">Комментарий</th><th style="padding:2px 0px; width:1%;"></th></tr></thead><tbody>';
        match.forEach((x) => {
            const ts = Number(x.ts || 0);
            const d = ts ? new Date(ts * 1000) : null;
            const dateStr = d ? `${pad(d.getDate())}.${pad(d.getMonth() + 1)}.${d.getFullYear()}` : '';
            const timeStr = d ? `${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}` : '';
            const typeRaw = String(x.type || '');
            const isOut = (typeRaw === '0' || typeRaw.toUpperCase() === 'O' || typeRaw.toLowerCase() === 'out');
            const sumSigned = isOut ? -Number(x.sum || 0) : Number(x.sum || 0);
            const comment = String(x.comment || '').trim();
            const user = String(x.user || '').trim();
            const commentText = user ? `${comment} (${user})` : comment;
            const transferId = Number(x.transfer_id || 0);
            const txId = Number(x.transaction_id || 0);
            const delBtn = `<button type="button" class="finance-del btn tiny" style="padding:0 4px; flex:0 0 auto;" data-kind="${escapeHtml(kind)}" data-transfer-id="${transferId}" data-tx-id="${txId}" data-date-to="${escapeHtml(dateTo)}" title="Скрыть транзакцию">✕</button>`;
            html += '<tr>';
            html += `<td><div>${escapeHtml(dateStr)}</div><div class="muted">${escapeHtml(timeStr)}</div></td>`;
            html += `<td class="sum">${escapeHtml(fmtSum(sumSigned))}</td>`;
            html += `<td><div style="display:flex; justify-content:space-between; gap: 8px; align-items:flex-start;"><div>${escapeHtml(commentText)}</div>${delBtn}</div></td>`;
            html += '</tr>';
        });
        html += '</tbody></table></div>';
        statusEl.innerHTML = html;
        bindFinanceDeleteBtns(statusEl);
    };

    window.refreshFinanceForm = (form, opts) => {
        const options = opts && typeof opts === 'object' ? opts : {};
        const showLoading = options.showLoading !== false;
        const kind = String(form.getAttribute('data-kind') || '');
        const dateFrom = String(form.getAttribute('data-date-from') || '');
        const dateTo = String(form.getAttribute('data-date-to') || '');
        const accountFrom = Number(form.getAttribute('data-account-from-id') || 0);
        const accountTo = Number(form.getAttribute('data-account-to-id') || 0);
        const statusEl = form.querySelector('.finance-status');
        if (!kind || !dateFrom || !dateTo || !accountFrom || !accountTo || !statusEl) return Promise.resolve();

        if (showLoading) statusEl.innerHTML = '<span style="color:var(--muted);">Обновление...</span>';
        return fetch('?ajax=refresh_finance_transfers', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ kind, dateFrom, dateTo, accountFrom, accountTo }),
        })
        .then((r) => r.json())
        .then((j) => {
            if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
            const rows = Array.isArray(j.rows) ? j.rows : [];
            renderFinanceTable(form, rows);
        })
        .catch((e) => {
            statusEl.textContent = e && e.message ? e.message : 'Ошибка';
        });
    };

    const refreshAllBtn = document.getElementById('finance-refresh-all');
    if (refreshAllBtn) {
        refreshAllBtn.addEventListener('click', async (e) => {
            const btn = e.currentTarget;
            if (btn.disabled) return;
            const orig = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '...';
            try {
                const forms = document.querySelectorAll('form.finance-transfer');
                for (const form of forms) {
                    await window.refreshFinanceForm(form, { showLoading: true });
                }
            } finally {
                btn.disabled = false;
                btn.innerHTML = orig;
            }
        });
    }
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
    if (toggleVietnamBtn) {
        toggleVietnamBtn.addEventListener('click', () => {
            hideVietnam = !hideVietnam;
            try { localStorage.setItem('payday_hide_vietnam', hideVietnam ? '1' : '0'); } catch (e) {}
            updateVietnamButtonState();
            applyHideLinked();
            drawLines();
            setTimeout(() => { positionLines(); positionWidgets(); }, 0);
        });
        updateVietnamButtonState();
    }
    if (toggleSepayHiddenBtn) {
        toggleSepayHiddenBtn.addEventListener('click', () => {
            showSepayHidden = !showSepayHidden;
            try { localStorage.setItem('payday_show_sepay_hidden', showSepayHidden ? '1' : '0'); } catch (e) {}
            updateSepayHiddenButtonState();
            applyHideLinked();
            drawLines();
            setTimeout(() => { positionLines(); positionWidgets(); }, 0);
        });
        updateSepayHiddenButtonState();
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

    const btnKashShift = document.getElementById('btnKashShift');
    const kashshiftModal = document.getElementById('kashshiftModal');
    const kashshiftClose = document.getElementById('kashshiftClose');
    const kashshiftBody = document.getElementById('kashshiftBody');

    if (btnKashShift && kashshiftModal) {
        btnKashShift.addEventListener('click', () => {
            kashshiftModal.style.display = 'flex';
            kashshiftBody.innerHTML = '<div style="text-align:center;">Загрузка...</div>';
            
            const dFrom = document.querySelector('input[name="dateFrom"]').value || '';
            const dTo = document.querySelector('input[name="dateTo"]').value || '';
            
            const url = '?ajax=kashshift&dateFrom=' + encodeURIComponent(dFrom) + '&dateTo=' + encodeURIComponent(dTo);
            fetchJsonSafe(url).then(res => {
                if (!res.ok) throw new Error(res.error || 'Ошибка');
                
                if (!res.data || res.data.length === 0) {
                    kashshiftBody.innerHTML = '<div style="text-align:center; padding:15px; color:var(--muted);">Нет данных за период</div>';
                    return;
                }
                
                // Оставляем только нужные колонки для кассовых смен
                const keys = ['cash_shift_id', 'date_start', 'date_end', 'amount_start'];
                const displayKeys = ['ID смены', 'Дата открытия', 'Дата закрытия', 'Сумма на старте'];
                
                let html = '<div style="overflow-x:auto;"><table style="width:100%; border-collapse:collapse; white-space:nowrap; font-size:13px;"><thead><tr>';
                displayKeys.forEach(k => {
                    html += '<th style="text-align:left; border-bottom:1px solid var(--border); padding:6px; background:var(--card);">' + escapeHtml(k) + '</th>';
                });
                html += '</tr></thead><tbody>';
                
                res.data.forEach(row => {
                    html += '<tr style="cursor:pointer;" onclick="toggleShiftDetail(this, \'' + escapeHtml(row.cash_shift_id || row.shift_id || '') + '\')">';
                    keys.forEach(k => {
                        let val = row[k];
                        if (val === null || val === undefined) val = '';
                        
                        // Форматирование сумм (убираем копейки)
                        if ((k.includes('amount') || k.includes('sum')) && val !== '') {
                            const nVal = Number(val);
                            if (!isNaN(nVal)) {
                                val = fmtVnd0(posterMinorToVnd(nVal));
                            }
                        }
                        
                        // Форматирование дат (могут приходить в миллисекундах)
                        if ((k.includes('date') || k.includes('time')) && val !== '') {
                            let ts = Number(val);
                            // Если число небольшое, значит это секунды
                            if (!isNaN(ts) && ts > 0 && String(Math.floor(ts)).length === 10) {
                                ts = ts * 1000;
                            }
                            if (!isNaN(ts) && ts > 0) {
                                const d = new Date(ts);
                                if (!isNaN(d.getTime())) {
                                    const p = n => String(n).padStart(2, '0');
                                    val = `${p(d.getDate())}.${p(d.getMonth()+1)}.${d.getFullYear()} ${p(d.getHours())}:${p(d.getMinutes())}`;
                                }
                            }
                        }

                        html += '<td style="border-bottom:1px solid var(--border); padding:6px;">' + escapeHtml(val) + '</td>';
                    });
                    html += '</tr>';
                    const sId = escapeHtml(row.cash_shift_id || row.shift_id || '');
                    if (sId) {
                        html += '<tr id="shift_detail_' + sId + '" style="display:none; background:var(--card2);">';
                        html += '<td colspan="' + keys.length + '" style="border-bottom:1px solid var(--border); padding:15px; white-space:normal;" class="shift-detail-content">Загрузка...</td>';
                        html += '</tr>';
                    }
                });
                
                html += '</tbody></table></div>';
                kashshiftBody.innerHTML = html;
            }).catch(e => {
                kashshiftBody.innerHTML = '<div class="error">' + escapeHtml(e.message) + '</div>';
            });
        });
        
        kashshiftClose.addEventListener('click', () => {
            kashshiftModal.style.display = 'none';
        });
        
        kashshiftModal.addEventListener('click', (e) => {
            if (e.target === kashshiftModal) {
                kashshiftModal.style.display = 'none';
            }
        });
    }
    
    window.toggleShiftDetail = function(tr, shiftId) {
        const detailTr = document.getElementById('shift_detail_' + shiftId);
        if (!detailTr) return;
        if (detailTr.style.display === 'none') {
            detailTr.style.display = 'table-row';
            const contentDiv = detailTr.querySelector('.shift-detail-content');
            if (contentDiv && contentDiv.innerHTML === 'Загрузка...') {
                fetchJsonSafe('?ajax=kashshift_detail&shiftId=' + encodeURIComponent(shiftId))
                    .then(res => {
                        if (!res.ok) throw new Error(res.error || 'Ошибка загрузки транзакций смены');
                        const arr = res.data;
                        if (!Array.isArray(arr) || arr.length === 0) {
                            contentDiv.innerHTML = '<div style="color:var(--muted);">Нет транзакций в этой смене</div>';
                            return;
                        }
                        
                        let h = '<div style="overflow-x:auto;"><table style="width:100%; border-collapse:collapse; white-space:nowrap; font-size:13px; background:var(--card);"><thead><tr>';
                        h += '<th style="text-align:left; border-bottom:1px solid var(--border); padding:6px; width:1%;">Дата</th>';
                        h += '<th style="text-align:left; border-bottom:1px solid var(--border); padding:6px; width:1%;">Тип</th>';
                        h += '<th style="text-align:right; border-bottom:1px solid var(--border); padding:6px; width:1%;">Сумма</th>';
                        h += '<th style="text-align:left; border-bottom:1px solid var(--border); padding:6px; width:auto;">Комментарий</th>';
                        h += '</tr></thead><tbody>';
                        
                        const getTypeLabel = (type) => {
                            if (type === 1) return '<span style="color:#fbbf24;">Открытие</span>';
                            if (type === 2) return '<span style="color:#4ade80;">Доход</span>';
                            if (type === 3) return '<span style="color:#f87171;">Расход</span>';
                            if (type === 4) return '<span style="color:#fbbf24;">Инкассация</span>';
                            if (type === 5) return '<span style="color:#fbbf24;">Закрытие</span>';
                            return String(type);
                        };
                        
                        const formatDate = (tsStr) => {
                            if (!tsStr) return '';
                            let ts = Number(tsStr);
                            // Если число небольшое, значит это секунды
                            if (!isNaN(ts) && ts > 0 && String(Math.floor(ts)).length === 10) {
                                ts = ts * 1000;
                            }
                            const d = new Date(ts);
                            if (isNaN(d.getTime())) return tsStr;
                            const p = n => String(n).padStart(2, '0');
                            return `${p(d.getDate())}.${p(d.getMonth()+1)}.${d.getFullYear()} ${p(d.getHours())}:${p(d.getMinutes())}`;
                        };

                        arr.forEach(tx => {
                            const isDeleted = Number(tx.delete) === 1;
                            const trStyle = isDeleted ? 'text-decoration: line-through;' : '';
                            h += `<tr style="${trStyle}">`;
                            h += '<td style="border-bottom:1px solid var(--border); padding:6px; width:1%;">' + escapeHtml(formatDate(tx.time)) + '</td>';
                            h += '<td style="border-bottom:1px solid var(--border); padding:6px; width:1%;">' + getTypeLabel(Number(tx.type)) + '</td>';
                            
                            // В API поле суммы называется tr_amount
                            const rawAmount = tx.tr_amount || tx.amount || 0;
                            h += '<td style="text-align:right; border-bottom:1px solid var(--border); padding:6px; width:1%; font-weight:bold;">' + fmtVnd0(posterMinorToVnd(rawAmount)) + '</td>';
                            h += '<td style="border-bottom:1px solid var(--border); padding:6px; width:auto; white-space:normal;">' + escapeHtml(tx.comment || '') + '</td>';
                            h += '</tr>';
                        });
                        
                        h += '</tbody></table></div>';
                        contentDiv.innerHTML = h;
                    })
                    .catch(e => {
                        contentDiv.innerHTML = '<div class="error">' + escapeHtml(e.message) + '</div>';
                    });
            }
        } else {
            detailTr.style.display = 'none';
        }
    };

    const btnSupplies = document.getElementById('btnSupplies');
    const suppliesModal = document.getElementById('suppliesModal');
    const suppliesClose = document.getElementById('suppliesClose');
    const suppliesBody = document.getElementById('suppliesBody');

    if (btnSupplies && suppliesModal) {
        btnSupplies.addEventListener('click', () => {
            suppliesModal.style.display = 'flex';
            suppliesBody.innerHTML = '<div style="text-align:center;">Загрузка...</div>';
            
            const dFrom = document.querySelector('input[name="dateFrom"]').value || '';
            const dTo = document.querySelector('input[name="dateTo"]').value || '';
            
            const url = '?ajax=supplies&dateFrom=' + encodeURIComponent(dFrom) + '&dateTo=' + encodeURIComponent(dTo);
            fetchJsonSafe(url).then(res => {
                if (!res.ok) throw new Error(res.error || 'Ошибка');
                
                const accMap = {};
                if (res.accounts) {
                    res.accounts.forEach(a => {
                        // Poster API finance.getAccounts возвращает account_name
                        accMap[a.account_id] = a.account_name || a.name;
                    });
                }
                
                if (!res.supplies || res.supplies.length === 0) {
                    suppliesBody.innerHTML = '<div style="text-align:center; padding:15px; color:var(--muted);">Нет данных за период</div>';
                    return;
                }
                
                // Сбор всех уникальных ключей для динамических колонок
                const allKeys = new Set();
                const ignoredKeys = ['supply_sum_netto', 'supplier_id', 'storage_id', 'delete', 'supply_comment'];
                res.supplies.forEach(row => {
                    Object.keys(row).forEach(k => {
                        if (!ignoredKeys.includes(k)) {
                            allKeys.add(k);
                        }
                    });
                });
                const keys = Array.from(allKeys);
                
                // Заменяем account_id на Название Счета
                const displayKeys = keys.map(k => k === 'account_id' ? 'Название Счета' : k);
                
                let html = '<div style="overflow-x:auto;"><table style="width:100%; border-collapse:collapse; white-space:nowrap; font-size:13px;"><thead><tr>';
                displayKeys.forEach(k => {
                    html += '<th style="text-align:left; border-bottom:1px solid var(--border); padding:6px; background:var(--card);">' + escapeHtml(k) + '</th>';
                });
                html += '</tr></thead><tbody>';
                
                res.supplies.forEach(row => {
                    html += '<tr>';
                    keys.forEach(k => {
                        let val = row[k];
                        if (val === null || val === undefined) val = '';
                        
                        // Специальная обработка для account_id
                        if (k === 'account_id') {
                            const accountId = row.account_id || (row.payed_sum && row.payed_sum.length > 0 ? row.payed_sum[0].account_id : null);
                            if (accountId && accMap[accountId]) {
                                val = accMap[accountId];
                            } else if (accountId) {
                                val = accountId;
                            }
                        }
                        
                        // Форматирование supply_sum и total_sum (в копейках -> без копеек с пробелами)
                        if ((k === 'supply_sum' || k === 'total_sum') && val !== '') {
                            val = fmtVnd0(posterMinorToVnd(val));
                        }
                        
                        // Если значение объект/массив, выводим как JSON
                        if (typeof val === 'object') {
                            val = JSON.stringify(val);
                        }
                        
                        html += '<td style="border-bottom:1px solid var(--border); padding:6px;">' + escapeHtml(val) + '</td>';
                    });
                    html += '</tr>';
                });
                
                html += '</tbody></table></div>';
                suppliesBody.innerHTML = html;
            }).catch(e => {
                suppliesBody.innerHTML = '<div class="error">' + escapeHtml(e.message) + '</div>';
            });
        });
        
        suppliesClose.addEventListener('click', () => {
            suppliesModal.style.display = 'none';
        });
        
        suppliesModal.addEventListener('click', (e) => {
            if (e.target === suppliesModal) {
                suppliesModal.style.display = 'none';
            }
        });
    }

})();
</script>
</body>
</html>

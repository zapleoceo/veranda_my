<?php
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

$getAccountsById = function (\App\Classes\PosterAPI $api): array {
    $out = [];
    try {
        $accounts = $api->request('finance.getAccounts');
        if (!is_array($accounts)) return [];
        foreach ($accounts as $a) {
            $id = (int)($a['account_id'] ?? 0);
            $name = trim((string)($a['account_name'] ?? $a['name'] ?? ''));
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


<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/FinanceHelper.php';

use App\Payday2\FinanceHelper;
use App\Payday2\LocalSettings;

$date = $dateTo;
$periodFrom = $dateFrom . ' 00:00:00';
$periodTo = $dateTo . ' 23:59:59';

$moneyToInt = function ($v): int { return FinanceHelper::moneyToInt($v); };
$posterCentsToVnd = function (int $cents): int { return FinanceHelper::posterCentsToVnd($cents); };
$fmtVndCents = function (int $cents): string { return FinanceHelper::fmtVndCents($cents); };
$parsePosterDateTime = function ($tx): ?string { return FinanceHelper::parsePosterDateTime($tx); };

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

$sendTelegramHtmlMessage = function (
    string $token,
    string $chatId,
    string $html,
    ?int $messageThreadId = null,
    ?int $replyToMessageId = null
): array {
    $token = trim($token);
    $chatId = trim($chatId);
    if ($token === '' || $chatId === '') {
        return ['ok' => false, 'error' => 'Telegram not configured'];
    }

    $baseParams = [
        'text' => $html,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => 'true',
    ];
    if ($messageThreadId !== null && $messageThreadId > 0) {
        $baseParams['message_thread_id'] = $messageThreadId;
    }
    if ($replyToMessageId !== null && $replyToMessageId > 0) {
        $baseParams['reply_to_message_id'] = $replyToMessageId;
    }

    $doSend = function (array $p) use ($token): array {
        $ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
        if ($ch === false) {
            return ['ok' => false, 'error' => 'curl_init failed'];
        }
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($p));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['ok' => false, 'error' => ($error !== '' ? $error : 'Telegram request failed')];
        }
        $decoded = json_decode($response, true);
        if (!is_array($decoded) || empty($decoded['ok'])) {
            $desc = is_array($decoded) ? (string)($decoded['description'] ?? 'Telegram error') : 'Bad Telegram response';
            return ['ok' => false, 'error' => $desc];
        }
        return ['ok' => true, 'decoded' => $decoded];
    };

    $chatCandidates = [];
    $chatCandidates[] = $chatId;
    if ($chatId !== '' && $chatId[0] !== '@' && $chatId[0] !== '-') {
        $digits = preg_replace('/\D+/', '', $chatId);
        if ($digits !== '' && ctype_digit($digits)) {
            $chatCandidates[] = '-100' . $digits;
        }
    }
    $chatCandidates = array_values(array_unique($chatCandidates));

    $lastError = 'Telegram error';
    foreach ($chatCandidates as $cid) {
        $params = $baseParams + ['chat_id' => $cid];
        $send1 = $doSend($params);
        if (!empty($send1['ok'])) {
            $decoded = (array)($send1['decoded'] ?? []);
            return [
                'ok' => true,
                'message_id' => (int)($decoded['result']['message_id'] ?? 0),
                'chat_id' => (string)($decoded['result']['chat']['id'] ?? $cid),
            ];
        }

        $err = (string)($send1['error'] ?? 'Telegram error');
        $lastError = $err;

        if ($replyToMessageId !== null && $replyToMessageId > 0 && stripos($err, 'message to be replied not found') !== false) {
            $params2 = $params;
            unset($params2['reply_to_message_id']);
            $send2 = $doSend($params2);
            if (!empty($send2['ok'])) {
                $decoded = (array)($send2['decoded'] ?? []);
                return [
                    'ok' => true,
                    'message_id' => (int)($decoded['result']['message_id'] ?? 0),
                    'chat_id' => (string)($decoded['result']['chat']['id'] ?? $cid),
                ];
            }
            $lastError = (string)($send2['error'] ?? $err);
            continue;
        }
    }

    return [
        'ok' => false,
        'error' => $lastError,
    ];
};

$findFinanceTransfers = function (string $dateFrom, string $dateTo) use ($token, $getEmployeesById, $getAccountsById): array {
    $out = [
        'vietnam' => [],
        'tips' => [],
    ];

    $startTs = strtotime($dateFrom . ' 00:00:00');
    $endTs = strtotime($dateTo . ' 23:59:59');
    if ($startTs === false || $endTs === false) {
        return $out;
    }

    try {
        $apiFinance = new \App\Classes\PosterAPI((string)$token);
        $rows = [];
        try {
            // `dmY` is the working format used in the original payday implementation.
            $rows = $apiFinance->request('finance.getTransactions', [
                'dateFrom' => date('dmY', $startTs),
                'dateTo' => date('dmY', $endTs),
                'timezone' => 'client',
            ]);
        } catch (\Throwable $e) {
            $rows = [];
        }
        if (!is_array($rows)) {
            return $out;
        }

        $normMoney = function ($sumRaw): int {
            $sumF = 0.0;
            if (is_int($sumRaw) || is_float($sumRaw)) {
                $sumF = (float)$sumRaw;
            } elseif (is_string($sumRaw)) {
                $sumF = (float)str_replace(',', '.', str_replace(' ', '', trim($sumRaw)));
            }
            $sumInt = (int)round($sumF);
            return ($sumInt > 200000000 && $sumInt % 100 === 0) ? (int)round($sumInt / 100) : $sumInt;
        };
        $normText = function (string $s): string {
            return mb_strtolower(trim($s), 'UTF-8');
        };

        $employeesMapFinance = [];
        try {
            $employeesMapFinance = $getEmployeesById($apiFinance);
        } catch (\Throwable $e) {
        }

        $accountsMapFinance = [];
        try {
            $accountsMapFinance = $getAccountsById($apiFinance);
        } catch (\Throwable $e) {
        }

        $idTips = LocalSettings::accountTipsId();
        $idVietnam = LocalSettings::accountVietnamId();

        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            if (((int)($r['status'] ?? 0)) === 3) continue;

            $tRaw = (string)($r['type'] ?? '');
            $isTransfer = ($tRaw === '2');
            $isIn = ($tRaw === '1' || strtoupper($tRaw) === 'I' || strtolower($tRaw) === 'in');
            $isOut = ($tRaw === '0' || strtoupper($tRaw) === 'O' || strtolower($tRaw) === 'out');
            if (!$isTransfer && !$isIn && !$isOut) continue;

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

            $accFromId = 0;
            $accToId = 0;
            if ($isTransfer) {
                $fromRaw = $r['account_from'] ?? $r['account_from_id'] ?? $r['account_id'] ?? 0;
                if (is_array($fromRaw)) $fromRaw = $fromRaw['account_id'] ?? $fromRaw['id'] ?? 0;
                $accFromId = (int)$fromRaw;

                $toRaw = $r['account_to'] ?? $r['account_to_id'] ?? $r['recipient_id'] ?? 0;
                if (is_array($toRaw)) $toRaw = $toRaw['account_id'] ?? $toRaw['id'] ?? 0;
                $accToId = (int)$toRaw;
            } elseif ($isOut) {
                $fromRaw = $r['account_id'] ?? $r['accountId'] ?? $r['account_from_id'] ?? $r['account_from'] ?? $r['accountFromId'] ?? $r['accountFrom'] ?? 0;
                if (is_array($fromRaw)) $fromRaw = $fromRaw['account_id'] ?? $fromRaw['id'] ?? 0;
                $accFromId = (int)$fromRaw;

                $toRaw = $r['recipient_id'] ?? $r['account_to_id'] ?? $r['account_to'] ?? 0;
                if (is_array($toRaw)) $toRaw = $toRaw['account_id'] ?? $toRaw['id'] ?? 0;
                $accToId = (int)$toRaw;
            } else {
                $fromRaw = $r['account_from'] ?? $r['account_from_id'] ?? 0;
                if (is_array($fromRaw)) $fromRaw = $fromRaw['account_id'] ?? $fromRaw['id'] ?? 0;
                $accFromId = (int)$fromRaw;

                $toRaw = $r['account_id'] ?? $r['account_to_id'] ?? $r['account_to'] ?? 0;
                if (is_array($toRaw)) $toRaw = $toRaw['account_id'] ?? $toRaw['id'] ?? 0;
                $accToId = (int)$toRaw;
            }

            if ($accToId !== $idTips && $accToId !== $idVietnam && $accFromId !== $idTips && $accFromId !== $idVietnam) continue;
            $accId = ($accToId === $idTips || $accToId === $idVietnam) ? $accToId : $accFromId;

            $cmt = (string)($r['comment'] ?? $r['description'] ?? $r['comment_text'] ?? '');
            $cmtNorm = $normText($cmt);
            $isVietnam = $accId === $idVietnam && mb_stripos($cmtNorm, 'вьетна', 0, 'UTF-8') !== false;
            $isTips = $accId === $idTips && (mb_stripos($cmtNorm, 'типс', 0, 'UTF-8') !== false || mb_stripos($cmtNorm, 'tips', 0, 'UTF-8') !== false);
            if (!$isVietnam && !$isTips) continue;

            $uRaw = $r['user_id'] ?? $r['userId'] ?? $r['user'] ?? $r['employee_id'] ?? null;
            if (is_array($uRaw)) $uRaw = $uRaw['user_id'] ?? $uRaw['id'] ?? $uRaw['userId'] ?? null;
            $uId = (int)($uRaw ?? 0);
            $userName = '';
            if ($uId > 0 && isset($employeesMapFinance[$uId])) {
                $userName = $employeesMapFinance[$uId];
            } else {
                $uObj = $r['user'] ?? $r['employee'] ?? null;
                if (is_array($uObj)) {
                    $userName = trim((string)($uObj['name'] ?? $uObj['user_name'] ?? $uObj['username'] ?? $uObj['title'] ?? ''));
                }
            }
            if ($userName === '' && $uId > 0) $userName = '#' . $uId;

            $amountMinor = abs($normMoney($r['amount'] ?? $r['amount_to'] ?? $r['amount_from'] ?? $r['sum'] ?? 0));
            if ($amountMinor <= 0) continue;

            $accName = isset($accountsMapFinance[$accId]) ? $accountsMapFinance[$accId] : ('#' . $accId);
            $txId = (int)($r['transaction_id'] ?? $r['id'] ?? 0);
            $rowOut = [
                'transfer_id' => $txId,
                'transaction_id' => $txId,
                'ts' => (int)$ts,
                'sum_minor' => $amountMinor,
                'comment' => $cmt,
                'user' => $userName,
                'account' => $accName,
                'type' => $tRaw,
            ];
            if ($isVietnam) $out['vietnam'][] = $rowOut;
            if ($isTips) $out['tips'][] = $rowOut;
        }

        usort($out['vietnam'], function ($a, $b) {
            return ((int)($b['ts'] ?? 0)) <=> ((int)($a['ts'] ?? 0));
        });
        usort($out['tips'], function ($a, $b) {
            return ((int)($b['ts'] ?? 0)) <=> ((int)($a['ts'] ?? 0));
        });
    } catch (\Throwable $e) {
    }

    return $out;
};

global $db;
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
} catch (\Throwable $e) { error_log('Payday2 Error: ' . $e->getMessage()); }
try {
    $db->query("ALTER TABLE {$pc} ADD COLUMN deleted_at DATETIME NULL");
} catch (\Throwable $e) { error_log('Payday2 Error: ' . $e->getMessage()); }
try {
    $db->query("ALTER TABLE {$st} ADD COLUMN was_deleted TINYINT(1) NOT NULL DEFAULT 0");
} catch (\Throwable $e) { error_log('Payday2 Error: ' . $e->getMessage()); }
try {
    $db->query("ALTER TABLE {$st} ADD COLUMN deleted_at DATETIME NULL");
} catch (\Throwable $e) { error_log('Payday2 Error: ' . $e->getMessage()); }
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

if (!function_exists('payday2_ensure_csrf')) {
    function payday2_ensure_csrf(): string {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        if (empty($_SESSION['payday2_csrf']) || !is_string($_SESSION['payday2_csrf'])) {
            $_SESSION['payday2_csrf'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['payday2_csrf'];
    }
}

if (!function_exists('payday2_csrf_valid')) {
    function payday2_csrf_valid(): bool {
        $expected = (string)(($_SESSION['payday2_csrf'] ?? '') ?: '');
        if ($expected === '') {
            return false;
        }
        $hdr = trim((string)($_SERVER['HTTP_X_PAYDAY2_CSRF'] ?? ''));
        if ($hdr !== '' && hash_equals($expected, $hdr)) {
            return true;
        }
        $post = trim((string)($_POST['payday2_csrf'] ?? ''));
        return $post !== '' && hash_equals($expected, $post);
    }
}

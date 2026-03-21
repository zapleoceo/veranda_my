<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../src/classes/PosterAPI.php';

veranda_require('payday');
date_default_timezone_set('Asia/Ho_Chi_Minh');

$db->createPaydayTables();

$message = '';
$error = '';

$date = trim((string)($_GET['date'] ?? ''));
if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}

$toVnd = function (int $amount): int {
    if ($amount > 0 && $amount % 100 === 0) return (int)round($amount / 100);
    return $amount;
};

$parsePosterDateTime = function ($tx): ?string {
    $ts = null;
    if (is_array($tx)) {
        if (!empty($tx['date_close']) && is_numeric($tx['date_close'])) {
            $n = (int)$tx['date_close'];
            if ($n > 2000000000000) $n = (int)round($n / 1000);
            if ($n > 0) $ts = $n;
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

$extractPaymentMethod = function (array $tx): ?string {
    $hay = strtolower(json_encode($tx, JSON_UNESCAPED_UNICODE));
    if (strpos($hay, 'vietnam company') !== false) return 'Vietnam Company';
    if (strpos($hay, 'bybit') !== false) return 'Bybit';
    if (strpos($hay, 'card') !== false) return 'Card';
    return null;
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
$pl = $db->t('check_payment_links');

$action = (string)($_POST['action'] ?? '');

try {
    $api = new \App\Classes\PosterAPI((string)$token);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'load_poster_checks') {
        $txs = $api->getTransactions($date, $date);
        if (!is_array($txs)) $txs = [];

        $employeesById = null;
        $inserted = 0;
        $updated = 0;
        $skipped = 0;

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
                $detail = null;
                try {
                    $detail = $api->getTransaction($txId);
                    if (is_array($detail)) {
                        $closeAt = $parsePosterDateTime($detail);
                    }
                } catch (\Throwable $e) {
                    $detail = null;
                }
                if ($closeAt === null) {
                    $skipped++;
                    continue;
                }
                if (is_array($detail)) {
                    $tx = array_merge($tx, $detail);
                }
            }

            $dayDate = substr($closeAt, 0, 10);

            $employeeId = (int)($tx['employee_id'] ?? $tx['user_id'] ?? $tx['waiter_id'] ?? 0);
            $waiterName = trim((string)($tx['waiter_name'] ?? $tx['waiterName'] ?? ''));
            if ($waiterName === '' && $employeeId > 0) {
                if ($employeesById === null) {
                    $employeesById = $getEmployeesById($api);
                }
                $waiterName = (string)($employeesById[$employeeId] ?? '');
            }

            $paymentMethod = $extractPaymentMethod($tx);

            $sum = (int)($tx['sum'] ?? 0);
            $payedSum = (int)($tx['payed_sum'] ?? $tx['payedSum'] ?? 0);
            $payedCash = (int)($tx['payed_cash'] ?? $tx['payedCash'] ?? 0);
            $payedCard = (int)($tx['payed_card'] ?? $tx['payedCard'] ?? 0);
            $payedCert = (int)($tx['payed_cert'] ?? $tx['payedCert'] ?? 0);
            $payedBonus = (int)($tx['payed_bonus'] ?? $tx['payedBonus'] ?? 0);
            $reason = isset($tx['reason']) ? (int)$tx['reason'] : null;
            $tipSum = (int)($tx['tip_sum'] ?? $tx['tipSum'] ?? 0);
            $discount = (float)($tx['discount'] ?? 0);
            $tableId = isset($tx['table_id']) ? (int)$tx['table_id'] : (isset($tx['tableId']) ? (int)$tx['tableId'] : null);
            $spotId = isset($tx['spot_id']) ? (int)$tx['spot_id'] : (isset($tx['spotId']) ? (int)$tx['spotId'] : null);

            $exists = (int)$db->query("SELECT 1 FROM {$pc} WHERE transaction_id = ? LIMIT 1", [$txId])->fetchColumn();
            if ($exists === 1) {
                $db->query(
                    "UPDATE {$pc}
                     SET table_id = ?, spot_id = ?, sum = ?, payed_sum = ?, payed_cash = ?, payed_card = ?, payed_cert = ?, payed_bonus = ?,
                         pay_type = ?, reason = ?, tip_sum = ?, discount = ?, date_close = ?, payment_method = ?, waiter_name = ?, day_date = ?
                     WHERE transaction_id = ?
                     LIMIT 1",
                    [
                        $tableId, $spotId, $sum, $payedSum, $payedCash, $payedCard, $payedCert, $payedBonus,
                        $payType, $reason, $tipSum, $discount, $closeAt, $paymentMethod, $waiterName !== '' ? $waiterName : null, $dayDate,
                        $txId
                    ]
                );
                $updated++;
            } else {
                $db->query(
                    "INSERT INTO {$pc}
                        (transaction_id, table_id, spot_id, sum, payed_sum, payed_cash, payed_card, payed_cert, payed_bonus, pay_type, reason, tip_sum, discount, date_close, payment_method, waiter_name, day_date)
                     VALUES
                        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [
                        $txId, $tableId, $spotId, $sum, $payedSum, $payedCash, $payedCard, $payedCert, $payedBonus,
                        $payType, $reason, $tipSum, $discount, $closeAt, $paymentMethod, $waiterName !== '' ? $waiterName : null, $dayDate
                    ]
                );
                $inserted++;
            }
        }

        $message = 'Poster чеки загружены: ' . json_encode(['inserted' => $inserted, 'updated' => $updated, 'skipped' => $skipped], JSON_UNESCAPED_UNICODE);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'auto_link') {
        $preserveManual = !empty($_POST['preserve_manual']);

        if ($preserveManual) {
            $db->query(
                "DELETE l FROM {$pl} l
                 JOIN {$pc} p ON p.transaction_id = l.poster_transaction_id
                 WHERE p.day_date = ?
                   AND l.is_manual = 0
                   AND l.link_type IN ('auto_green','auto_yellow')",
                [$date]
            );
        } else {
            $db->query(
                "DELETE l FROM {$pl} l
                 JOIN {$pc} p ON p.transaction_id = l.poster_transaction_id
                 WHERE p.day_date = ?
                   AND l.link_type IN ('auto_green','auto_yellow','manual')",
                [$date]
            );
        }

        $checks = $db->query(
            "SELECT transaction_id, date_close, payed_card, tip_sum, payment_method
             FROM {$pc}
             WHERE day_date = ?
               AND pay_type IN (2,3)
             ORDER BY date_close ASC",
            [$date]
        )->fetchAll();

        $sepay = $db->query(
            "SELECT sepay_id, transaction_date, transfer_amount
             FROM {$st}
             WHERE DATE(transaction_date) = ?
               AND transfer_type = 'in'
               AND (payment_method IS NULL OR payment_method IN ('Card','Bybit'))
             ORDER BY transaction_date ASC",
            [$date]
        )->fetchAll();

        $linkedSepay = [];
        $linkedPoster = [];
        if ($preserveManual) {
            $manual = $db->query(
                "SELECT l.poster_transaction_id, l.sepay_id
                 FROM {$pl} l
                 JOIN {$pc} p ON p.transaction_id = l.poster_transaction_id
                 WHERE p.day_date = ?
                   AND l.is_manual = 1",
                [$date]
            )->fetchAll();
            foreach ($manual as $m) {
                $linkedPoster[(int)$m['poster_transaction_id']] = true;
                $linkedSepay[(int)$m['sepay_id']] = true;
            }
        }

        $sepayByAmount = [];
        foreach ($sepay as $s) {
            $sid = (int)($s['sepay_id'] ?? 0);
            if ($sid <= 0) continue;
            if (!empty($linkedSepay[$sid])) continue;
            $amt = (int)($s['transfer_amount'] ?? 0);
            $sepayByAmount[$amt][] = $s;
        }

        $linksGreen = [];

        foreach ($checks as $c) {
            $pid = (int)($c['transaction_id'] ?? 0);
            if ($pid <= 0) continue;
            if (!empty($linkedPoster[$pid])) continue;
            $pm = (string)($c['payment_method'] ?? '');
            if (strtolower($pm) === 'vietnam company') continue;

            $payedCardVnd = $toVnd((int)($c['payed_card'] ?? 0));
            $tipVnd = $toVnd((int)($c['tip_sum'] ?? 0));
            $amounts = array_values(array_unique(array_filter([$payedCardVnd, $payedCardVnd + $tipVnd], fn($v) => (int)$v > 0)));
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
                    "INSERT INTO {$pl} (poster_transaction_id, sepay_id, link_type, is_manual)
                     VALUES (?, ?, 'auto_green', 0)",
                    [$pid, $sid]
                );
                $linkedPoster[$pid] = true;
                $linkedSepay[$sid] = true;
                $linksGreen[$pid] = $sid;
            }
        }

        $checksIdx = [];
        foreach ($checks as $i => $c) {
            $pid = (int)($c['transaction_id'] ?? 0);
            if ($pid > 0) $checksIdx[$pid] = $i;
        }

        $linkedGreenPoster = [];
        $rowsGreen = $db->query(
            "SELECT l.poster_transaction_id, l.sepay_id
             FROM {$pl} l
             JOIN {$pc} p ON p.transaction_id = l.poster_transaction_id
             WHERE p.day_date = ?
               AND l.link_type = 'auto_green'",
            [$date]
        )->fetchAll();
        foreach ($rowsGreen as $r) {
            $linkedGreenPoster[(int)$r['poster_transaction_id']] = (int)$r['sepay_id'];
        }

        for ($i = 1; $i < count($checks) - 1; $i++) {
            $pid = (int)($checks[$i]['transaction_id'] ?? 0);
            if ($pid <= 0) continue;
            if (!empty($linkedPoster[$pid])) continue;
            $pm = (string)($checks[$i]['payment_method'] ?? '');
            if (strtolower($pm) === 'vietnam company') continue;

            $prevPid = (int)($checks[$i - 1]['transaction_id'] ?? 0);
            $nextPid = (int)($checks[$i + 1]['transaction_id'] ?? 0);
            if ($prevPid <= 0 || $nextPid <= 0) continue;
            if (empty($linkedGreenPoster[$prevPid]) || empty($linkedGreenPoster[$nextPid])) continue;

            $payedCardVnd = $toVnd((int)($checks[$i]['payed_card'] ?? 0));
            $tipVnd = $toVnd((int)($checks[$i]['tip_sum'] ?? 0));
            $amounts = array_values(array_unique(array_filter([$payedCardVnd, $payedCardVnd + $tipVnd], fn($v) => (int)$v > 0)));
            if (count($amounts) === 0) continue;

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
                    "INSERT INTO {$pl} (poster_transaction_id, sepay_id, link_type, is_manual)
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
            $pm = (string)($c['payment_method'] ?? '');
            if (strtolower($pm) === 'vietnam company') continue;

            $payedCardVnd = $toVnd((int)($c['payed_card'] ?? 0));
            $tipVnd = $toVnd((int)($c['tip_sum'] ?? 0));
            $amounts = array_values(array_unique(array_filter([$payedCardVnd, $payedCardVnd + $tipVnd], fn($v) => (int)$v > 0)));
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
                    "INSERT INTO {$pl} (poster_transaction_id, sepay_id, link_type, is_manual)
                     VALUES (?, ?, 'auto_yellow', 0)",
                    [$pid, $sid]
                );
                $linkedPoster[$pid] = true;
                $linkedSepay[$sid] = true;
            }
        }

        $message = 'Связи пересчитаны.';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create_transfer') {
        $kind = (string)($_POST['kind'] ?? '');
        if (!in_array($kind, ['vietnam', 'tips'], true)) {
            throw new \Exception('Bad request');
        }
        $txs = $api->request('finance.getTransactions', [
            'dateFrom' => str_replace('-', '', $date),
            'dateTo' => str_replace('-', '', $date),
        ]);
        if (!is_array($txs)) $txs = [];

        $matchRow = null;
        foreach ($txs as $row) {
            if (!is_array($row)) continue;
            $hay = strtolower(json_encode($row, JSON_UNESCAPED_UNICODE));
            if ($kind === 'vietnam') {
                if (strpos($hay, 'vietnam company') === false) continue;
                if (strpos($hay, 'card') === false) continue;
                $matchRow = $row;
                break;
            } else {
                if (strpos($hay, 'tip') === false) continue;
                if (strpos($hay, 'shift') === false) continue;
                $matchRow = $row;
                break;
            }
        }
        if ($matchRow === null) {
            throw new \Exception('Не найдена исходная транзакция в Poster за день.');
        }

        $amount = (int)($matchRow['sum'] ?? $matchRow['amount'] ?? 0);
        $origDateRaw = $matchRow['date'] ?? $matchRow['created_at'] ?? $matchRow['time'] ?? null;
        $origTs = null;
        if (is_numeric($origDateRaw)) {
            $n = (int)$origDateRaw;
            if ($n > 2000000000000) $n = (int)round($n / 1000);
            if ($n > 0) $origTs = $n;
        } elseif (is_string($origDateRaw) && trim($origDateRaw) !== '') {
            $t = strtotime($origDateRaw);
            if ($t !== false && $t > 0) $origTs = $t;
        }
        if ($origTs === null) $origTs = time();
        $targetTs = $origTs + 60;
        $targetDate = date('Y-m-d H:i:s', $targetTs);

        $accountTo = $kind === 'vietnam' ? 9 : 8;

        $dup = false;
        foreach ($txs as $row) {
            if (!is_array($row)) continue;
            $type = (int)($row['type'] ?? 0);
            if ($type !== 2) continue;
            $sum = (int)($row['sum'] ?? $row['amount'] ?? 0);
            if ($sum !== $amount) continue;
            $toId = (int)($row['account_to_id'] ?? $row['accountTo'] ?? 0);
            if ($toId !== $accountTo) continue;
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
            if ($ts !== null && $ts >= $origTs) {
                $dup = true;
                break;
            }
        }
        if ($dup) {
            throw new \Exception('Перевод за этот день уже создан.');
        }

        $api->request('finance.createTransactions', [
            'type' => 2,
            'account_id' => 1,
            'account_to_id' => $accountTo,
            'sum' => $amount,
            'date' => $targetDate,
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
    $posterId = (int)($payload['poster_transaction_id'] ?? 0);
    $sepayId = (int)($payload['sepay_id'] ?? 0);
    $mode = (string)($payload['mode'] ?? 'toggle');
    if ($posterId <= 0 || $sepayId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $db->query("DELETE FROM {$pl} WHERE (poster_transaction_id = ? OR sepay_id = ?) AND is_manual = 1", [$posterId, $sepayId]);
        if ($mode !== 'delete') {
            $exists = $db->query("SELECT id FROM {$pl} WHERE poster_transaction_id = ? AND sepay_id = ? AND is_manual = 1 LIMIT 1", [$posterId, $sepayId])->fetch();
            if (!$exists) {
                $db->query(
                    "INSERT INTO {$pl} (poster_transaction_id, sepay_id, link_type, is_manual)
                     VALUES (?, ?, 'manual', 1)
                     ON DUPLICATE KEY UPDATE link_type = 'manual', is_manual = 1",
                    [$posterId, $sepayId]
                );
            }
        }
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$sepayRows = $db->query(
    "SELECT sepay_id, transaction_date, transfer_amount, payment_method, content, reference_code
     FROM {$st}
     WHERE DATE(transaction_date) = ?
       AND transfer_type = 'in'
       AND (payment_method IS NULL OR payment_method IN ('Card','Bybit','Vietnam Company'))
     ORDER BY transaction_date ASC",
    [$date]
)->fetchAll();

$posterRows = $db->query(
    "SELECT transaction_id, date_close, payed_card, tip_sum, payment_method, waiter_name, table_id
     FROM {$pc}
     WHERE day_date = ?
       AND pay_type IN (2,3)
     ORDER BY date_close ASC",
    [$date]
)->fetchAll();

$links = $db->query(
    "SELECT l.poster_transaction_id, l.sepay_id, l.link_type, l.is_manual
     FROM {$pl} l
     JOIN {$pc} p ON p.transaction_id = l.poster_transaction_id
     WHERE p.day_date = ?",
    [$date]
)->fetchAll();

$linkByPoster = [];
$linkBySepay = [];
foreach ($links as $l) {
    $pid = (int)($l['poster_transaction_id'] ?? 0);
    $sid = (int)($l['sepay_id'] ?? 0);
    if ($pid <= 0 || $sid <= 0) continue;
    $t = (string)($l['link_type'] ?? '');
    $m = !empty($l['is_manual']);
    $linkByPoster[$pid] = ['sepay_id' => $sid, 'link_type' => $t, 'is_manual' => $m];
    $linkBySepay[$sid] = ['poster_transaction_id' => $pid, 'link_type' => $t, 'is_manual' => $m];
}

$financeRows = [];
$financeDisplay = [
    'vietnam' => null,
    'tips' => null,
];

try {
    $api2 = new \App\Classes\PosterAPI((string)$token);
    $financeRows = $api2->request('finance.getTransactions', [
        'dateFrom' => str_replace('-', '', $date),
        'dateTo' => str_replace('-', '', $date),
    ]);
    if (!is_array($financeRows)) $financeRows = [];
    foreach ($financeRows as $r) {
        if (!is_array($r)) continue;
        $hay = strtolower(json_encode($r, JSON_UNESCAPED_UNICODE));
        if ($financeDisplay['vietnam'] === null && strpos($hay, 'vietnam company') !== false && strpos($hay, 'card') !== false) {
            $financeDisplay['vietnam'] = $r;
        }
        if ($financeDisplay['tips'] === null && strpos($hay, 'tip') !== false && strpos($hay, 'shift') !== false) {
            $financeDisplay['tips'] = $r;
        }
    }
} catch (\Throwable $e) {
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
        .btn { padding: 10px 14px; border-radius: 10px; border: 1px solid #d0d5dd; background: #fff; font-weight: 800; cursor: pointer; }
        .btn.primary { background: #1a73e8; border-color: #1a73e8; color: #fff; }
        .btn:disabled { opacity: 0.6; cursor: default; }
        .card { background: #fff; border: 1px solid #e0e0e0; border-radius: 14px; padding: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .grid { display:grid; grid-template-columns: 1fr 1fr; gap: 12px; align-items:start; }
        @media (max-width: 1050px) { .grid { grid-template-columns: 1fr; } }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px; border-bottom: 1px solid #e0e0e0; vertical-align: top; }
        th { background: #f8f9fa; color: #65676b; font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em; }
        tr.row-green { background: rgba(46, 125, 50, 0.08); }
        tr.row-yellow { background: rgba(255, 193, 7, 0.16); }
        tr.row-red { background: rgba(211, 47, 47, 0.08); }
        tr.row-blue { background: rgba(26, 115, 232, 0.10); }
        tr.row-selected { outline: 2px solid #1a73e8; outline-offset: -2px; }
        .muted { color: #777; font-size: 12px; }
        .sum { font-weight: 900; white-space: nowrap; }
        .nowrap { white-space: nowrap; }
        .anchor { display:inline-block; width: 10px; height: 10px; border-radius: 50%; background: #9aa4b2; vertical-align: middle; }
        tr.row-green .anchor { background: #2e7d32; }
        tr.row-yellow .anchor { background: #f6c026; }
        tr.row-blue .anchor { background: #1a73e8; }
        tr.row-red .anchor { background: #d32f2f; }
        .actions { display:flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-top: 10px; }
        .divider { height: 1px; background: #e0e0e0; margin: 12px 0; }
        .finance-row { display:flex; align-items:center; justify-content: space-between; gap: 12px; padding: 10px; border: 1px solid #e5e7eb; border-radius: 12px; }
        .finance-row + .finance-row { margin-top: 10px; }
        .finance-left { display:flex; flex-direction: column; gap: 4px; }
        .badge { display:inline-flex; align-items:center; gap: 6px; padding: 4px 10px; border-radius: 999px; font-weight: 800; font-size: 12px; border: 1px solid #e5e7eb; background: #fff; }
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

    <?php if ($message !== ''): ?><div class="success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="card">
        <form method="GET" class="toolbar" style="margin-bottom: 10px;">
            <input type="date" name="date" value="<?= htmlspecialchars($date) ?>" class="btn" style="padding: 8px 10px;">
            <button class="btn" type="submit">Открыть</button>
        </form>

        <form method="POST" class="toolbar">
            <input type="hidden" name="action" value="load_poster_checks">
            <input type="hidden" name="date" value="<?= htmlspecialchars($date) ?>">
            <button class="btn primary" type="submit">Загрузить чеки из Poster</button>
        </form>

        <div class="divider"></div>

        <div class="grid" id="tablesRoot">
            <div class="card" style="padding: 0;">
                <div style="padding: 12px 12px 6px;">
                    <div style="font-weight:900;">SePay</div>
                    <div class="muted">Приходы за день</div>
                </div>
                <div style="max-height: 56vh; overflow:auto;">
                    <table id="sepayTable">
                        <thead>
                            <tr>
                                <th></th>
                                <th class="nowrap">Время</th>
                                <th class="nowrap">Сумма</th>
                                <th>Метод</th>
                                <th>Content</th>
                                <th class="nowrap">Ref</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($sepayRows as $r): ?>
                            <?php
                                $sid = (int)$r['sepay_id'];
                                $link = $linkBySepay[$sid] ?? null;
                                $cls = 'row-red';
                                if ($link) {
                                    $cls = ($link['link_type'] === 'auto_green') ? 'row-green' : (($link['link_type'] === 'auto_yellow') ? 'row-yellow' : 'row-green');
                                }
                                $pm = (string)($r['payment_method'] ?? '');
                                if (strtolower($pm) === 'vietnam company') {
                                    $cls = 'row-blue';
                                }
                            ?>
                            <tr class="<?= $cls ?>" data-sepay-id="<?= $sid ?>">
                                <td><span class="anchor" id="sepay-<?= $sid ?>"></span></td>
                                <td class="nowrap"><?= date('H:i:s', strtotime($r['transaction_date'])) ?></td>
                                <td class="sum"><?= htmlspecialchars($fmtVnd((int)$r['transfer_amount'])) ?></td>
                                <td class="nowrap"><?= htmlspecialchars($pm !== '' ? $pm : '—') ?></td>
                                <td><?= htmlspecialchars((string)($r['content'] ?? '')) ?></td>
                                <td class="nowrap"><?= htmlspecialchars((string)($r['reference_code'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card" style="padding: 0;">
                <div style="padding: 12px 12px 6px;">
                    <div style="font-weight:900;">Poster</div>
                    <div class="muted">Безнал / смешанная (за выбранный день)</div>
                </div>
                <div style="max-height: 56vh; overflow:auto;">
                    <table id="posterTable">
                        <thead>
                            <tr>
                                <th></th>
                                <th class="nowrap">Время</th>
                                <th class="nowrap">Card</th>
                                <th class="nowrap">Tips</th>
                                <th class="nowrap">Card+Tips</th>
                                <th>Метод</th>
                                <th>Официант</th>
                                <th class="nowrap">Стол</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($posterRows as $r): ?>
                            <?php
                                $pid = (int)$r['transaction_id'];
                                $link = $linkByPoster[$pid] ?? null;
                                $cls = 'row-red';
                                if ($link) {
                                    $cls = ($link['link_type'] === 'auto_green') ? 'row-green' : (($link['link_type'] === 'auto_yellow') ? 'row-yellow' : 'row-green');
                                }
                                $pm = (string)($r['payment_method'] ?? '');
                                if (strtolower($pm) === 'vietnam company') {
                                    $cls = 'row-blue';
                                }
                                $cardVnd = $toVnd((int)$r['payed_card']);
                                $tipVnd = $toVnd((int)$r['tip_sum']);
                            ?>
                            <tr class="<?= $cls ?>" data-poster-id="<?= $pid ?>">
                                <td><span class="anchor" id="poster-<?= $pid ?>"></span></td>
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
            </div>
        </div>

        <div class="actions">
            <form method="POST" style="display:flex; gap: 10px; align-items:center; flex-wrap: wrap;">
                <input type="hidden" name="action" value="auto_link">
                <button class="btn" type="submit">Найти связи</button>
                <label style="display:inline-flex; gap: 8px; align-items:center; font-weight:800;">
                    <input type="checkbox" name="preserve_manual" value="1" checked>
                    Сохранять ручные связи
                </label>
            </form>
            <div class="muted">Ручная связь: клик строка слева → клик строка справа (или наоборот)</div>
        </div>

        <div class="divider"></div>

        <div class="card" style="background:#fbfbfd;">
            <div style="font-weight: 900; margin-bottom: 10px;">Финансовые транзакции</div>

            <?php
                $vietnamSum = $financeDisplay['vietnam'] ? $toVnd((int)($financeDisplay['vietnam']['sum'] ?? $financeDisplay['vietnam']['amount'] ?? 0)) : null;
                $tipsSum = $financeDisplay['tips'] ? $toVnd((int)($financeDisplay['tips']['sum'] ?? $financeDisplay['tips']['amount'] ?? 0)) : null;
            ?>

            <div class="finance-row">
                <div class="finance-left">
                    <div style="font-weight:900;">Vietnam Company — Card payments</div>
                    <div class="muted"><?= $vietnamSum !== null ? htmlspecialchars($fmtVnd($vietnamSum)) : '—' ?></div>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="create_transfer">
                    <input type="hidden" name="kind" value="vietnam">
                    <button class="btn" type="submit" <?= $vietnamSum === null ? 'disabled' : '' ?>>Создать перевод</button>
                </form>
            </div>

            <div class="finance-row">
                <div class="finance-left">
                    <div style="font-weight:900;">Card tips per shift</div>
                    <div class="muted"><?= $tipsSum !== null ? htmlspecialchars($fmtVnd($tipsSum)) : '—' ?></div>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="create_transfer">
                    <input type="hidden" name="kind" value="tips">
                    <button class="btn" type="submit" <?= $tipsSum === null ? 'disabled' : '' ?>>Создать перевод</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/leader-line/1.0.3/leader-line.min.js"></script>
<script>
(() => {
    const links = <?= json_encode(array_values(array_map(function ($l) {
        return [
            'poster_transaction_id' => (int)$l['poster_transaction_id'],
            'sepay_id' => (int)$l['sepay_id'],
            'link_type' => (string)$l['link_type'],
            'is_manual' => !empty($l['is_manual']),
        ];
    }, $links)), JSON_UNESCAPED_UNICODE) ?>;

    const lines = [];

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
        while (lines.length) {
            try { lines.pop().remove(); } catch (_) { lines.pop(); }
        }
    };

    const drawLines = () => {
        clearLines();
        links.forEach((l) => {
            const s = document.getElementById('sepay-' + l.sepay_id);
            const p = document.getElementById('poster-' + l.poster_transaction_id);
            if (!s || !p) return;
            const line = new LeaderLine(s, p, {
                color: colorFor(l.link_type, l.is_manual),
                size: l.is_manual ? 3 : 2,
                outline: true,
                outlineColor: 'rgba(255,255,255,0.65)',
                startPlug: 'disc',
                endPlug: endPlugFor(l.link_type, l.is_manual),
                path: 'fluid',
                startSocket: 'right',
                endSocket: 'left',
            });
            lines.push(line);
        });
    };

    const positionLines = () => {
        lines.forEach((l) => {
            try { l.position(); } catch (_) {}
        });
    };

    const tablesRoot = document.getElementById('tablesRoot');
    if (tablesRoot) {
        tablesRoot.addEventListener('scroll', () => positionLines(), { passive: true, capture: true });
    }
    window.addEventListener('resize', () => positionLines(), { passive: true });
    window.addEventListener('load', () => {
        drawLines();
        setTimeout(positionLines, 200);
        setTimeout(positionLines, 800);
    });
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            drawLines();
            setTimeout(positionLines, 200);
        });
    } else {
        drawLines();
        setTimeout(positionLines, 200);
    }

    const sepayTable = document.getElementById('sepayTable');
    const posterTable = document.getElementById('posterTable');
    if (!sepayTable || !posterTable) return;

    let selected = null;

    const clearSelected = () => {
        sepayTable.querySelectorAll('tr.row-selected').forEach((tr) => tr.classList.remove('row-selected'));
        posterTable.querySelectorAll('tr.row-selected').forEach((tr) => tr.classList.remove('row-selected'));
        selected = null;
    };

    const onRowClick = (tr, side) => {
        const idAttr = side === 'sepay' ? 'data-sepay-id' : 'data-poster-id';
        const id = Number(tr.getAttribute(idAttr) || 0);
        if (!id) return;

        if (!selected) {
            clearSelected();
            tr.classList.add('row-selected');
            selected = { side, id };
            return;
        }

        if (selected.side === side) {
            clearSelected();
            tr.classList.add('row-selected');
            selected = { side, id };
            return;
        }

        const posterId = side === 'poster' ? id : selected.id;
        const sepayId = side === 'sepay' ? id : selected.id;

        fetch('index.php?<?= htmlspecialchars(http_build_query(['date' => $date, 'ajax' => 'manual_link'])) ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ poster_transaction_id: posterId, sepay_id: sepayId, mode: 'toggle' }),
        })
        .then((r) => r.json())
        .then((j) => {
            if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
            location.reload();
        })
        .catch((e) => {
            alert(e && e.message ? e.message : 'Ошибка');
            clearSelected();
        });
    };

    sepayTable.addEventListener('click', (e) => {
        const tr = e.target.closest('tr[data-sepay-id]');
        if (!tr) return;
        onRowClick(tr, 'sepay');
    });
    posterTable.addEventListener('click', (e) => {
        const tr = e.target.closest('tr[data-poster-id]');
        if (!tr) return;
        onRowClick(tr, 'poster');
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') clearSelected();
    });
})();
</script>
</body>
</html>


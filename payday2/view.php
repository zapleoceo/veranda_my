<?php
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

$transferVietnamFoundList = [];
$transferTipsFoundList = [];
if (isset($findFinanceTransfers) && is_callable($findFinanceTransfers)) {
    try {
        $foundTransfers = $findFinanceTransfers($dateFrom, $dateTo);
        if (is_array($foundTransfers['vietnam'] ?? null)) {
            $transferVietnamFoundList = $foundTransfers['vietnam'];
        }
        if (is_array($foundTransfers['tips'] ?? null)) {
            $transferTipsFoundList = $foundTransfers['tips'];
        }
    } catch (\Throwable $e) {
    }
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
$payday2AssetVersion = '20260417_4000';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="/links/favicon.svg">
    <title>Payday2</title>
    <link rel="stylesheet" href="/assets/app.css">
    <script src="/assets/app.js" defer></script>
    <script src="/assets/user_menu.js" defer></script>
      <?php include $_SERVER['DOCUMENT_ROOT'] . '/analytics.php'; ?>
  <link rel="stylesheet" href="/assets/css/common.css?v=20260412_0171">
  <link rel="stylesheet" href="/assets/css/payday_index.css?v=20260417_4000">
  <link rel="stylesheet" href="/payday2/assets/css/payday2.css?v=<?= htmlspecialchars($payday2AssetVersion) ?>">
 </head>
<body>
<div class="container">
    <div class="top-nav">
        <div class="nav-left">
            <div class="nav-title" id="payday2BetaInfoBtn" style="cursor:pointer;">Payda2beta</div>
            <div class="tabs">
                <button type="button" class="tab active" id="tabIn">IN</button>
                <button type="button" class="tab" id="tabOut">OUT</button>
                <div style="margin-left: auto; display: flex;">
                    <button type="button" class="tab" id="btnKashShift" style="background: rgba(184,135,70,0.15); color: #B88746;">KashShift</button>
                    <button type="button" class="tab" id="btnSupplies" style="margin-left: 5px; background: rgba(184,135,70,0.15); color: #B88746;">Supplies</button>
                </div>
            </div>
            <div id="topFormsWrap" style="display: flex; gap: 10px; margin-left: 10px; align-items: center;">
                <form method="GET" id="dateForm" style="display: flex; gap: 10px; margin: 0; align-items: center;">
                    <input type="date" name="dateFrom" value="<?= htmlspecialchars($dateFrom) ?>" class="btn" style="padding: 8px 10px; width: 102px;">
                    <input type="date" name="dateTo" value="<?= htmlspecialchars($dateTo) ?>" class="btn" style="padding: 8px 10px; width: 102px;">
                    <button class="btn" type="submit">Открыть</button>
                </form>
                <form method="POST" id="clearDayForm" style="margin: 0;">
                    <input type="hidden" name="action" value="clear_day">
                    <input type="hidden" name="dateFrom" value="<?= htmlspecialchars($dateFrom) ?>">
                    <input type="hidden" name="dateTo" value="<?= htmlspecialchars($dateTo) ?>">
                    <button class="btn" id="clearDayBtn" type="submit" onclick="return confirm('Очистить все данные за выбранный день (Poster, SePay, связи)?')">Обнулить</button>
                </form>
            </div>
        </div>
        <?php require __DIR__ . '/../partials/user_menu.php'; ?>
    </div>

    <?php if ($error !== ''): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="card">
        <div class="toolbar toolbar-line" style="margin-bottom: 10px; display:none;">
            <button class="btn" id="outMailBtn" type="button" style="display:none;">Обновить Платежи Out</button>
            <button class="btn" id="outFinanceBtn" type="button" style="display:none;">Обновить транзакции</button>
        </div>

        <div class="divider"></div>

        <div id="outSection" style="display:none;">
            <div class="grid" id="outGrid" style="grid-template-columns: 1fr 70px 1fr; gap:12px; position: relative;">
                <div id="outLineLayer"></div>
                <div class="card" style="padding:0; position:relative;">
                    <div class="table-card-header" style="display: flex; align-items: center; justify-content: space-between; padding-right: 40px;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <div>Деньги 📧</div>
                            <button class="btn primary" id="outMailBtn" type="button" style="padding: 4px 8px; font-size: 11px;">Загрузить</button>
                        </div>
                        <div class="muted vc-subtitle">
                            <button type="button" class="vc-toggle" id="toggleOutMailHiddenBtn" title="Показать/скрыть скрытые">👁</button>
                        </div>
                    </div>
                    <div id="outSepayScroll" style="max-height: 56vh; overflow:auto;">
                        <table id="outSepayTable">
                            <thead><tr><th class="col-out-hide"></th><th class="col-out-content">Content</th><th class="nowrap col-out-time">Время</th><th class="nowrap col-out-sum">Сумма</th><th class="col-out-select"></th><th class="col-out-anchor"></th></tr></thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
                <div class="mid-col" id="outMidCol">
                    <div class="toggle-wrap" title="Lite/Full" style="margin: 0; transform: scale(0.9); transform-origin: center;">
                        <span class="toggle-text"><span class="tt-full">Lite</span><span class="tt-short">L</span></span>
                        <label class="switch">
                            <input id="modeToggleOut" type="checkbox">
                            <span class="slider"></span>
                        </label>
                        <span class="toggle-text"><span class="tt-full">Full</span><span class="tt-short">F</span></span>
                    </div>
                    <div class="mid-col-glass">
                        <button class="mid-btn primary" id="outLinkMakeBtn" type="button" title="Связать выбранные" disabled>🎯</button>
                        <button class="mid-btn eye-toggle" id="outHideLinkedBtn" type="button" title="Скрыть связанные">👁</button>
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
                </div>
                <div class="card" style="padding:0; position:relative;">
                    <div class="table-card-header" style="display: flex; align-items: center; justify-content: space-between; padding-right: 40px;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <div>Poster тр-ии</div>
                            <button class="btn primary" id="outFinanceBtn" type="button" style="padding: 4px 8px; font-size: 11px;">Загрузить</button>
                        </div>
                    </div>
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
            <div id="lineLayer"></div>
            <div class="card" style="padding: 0; position: relative;">
                <div class="table-card-header" style="display: flex; align-items: center; justify-content: space-between; padding-right: 40px;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <div>Деньги</div>
                        <form method="POST" id="sepaySyncForm" style="margin: 0;">
                            <input type="hidden" name="action" value="reload_sepay_api">
                            <input type="hidden" name="dateFrom" value="<?= htmlspecialchars($dateFrom) ?>">
                            <input type="hidden" name="dateTo" value="<?= htmlspecialchars($dateTo) ?>">
                            <button class="btn primary" id="sepaySyncBtn" type="submit" style="padding: 4px 8px; font-size: 11px;">Загрузить</button>
                        </form>
                    </div>
                    <div class="muted vc-subtitle">
                        <button type="button" class="vc-toggle" id="toggleSepayHiddenBtn" title="Показать/скрыть скрытые транзакции">👁</button>
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

            <div class="mid-col" id="midCol">
                <div class="toggle-wrap" title="Lite/Full" style="margin: 0; transform: scale(0.9); transform-origin: center;">
                    <span class="toggle-text"><span class="tt-full">Lite</span><span class="tt-short">L</span></span>
                    <label class="switch">
                        <input id="modeToggle" type="checkbox">
                        <span class="slider"></span>
                    </label>
                    <span class="toggle-text"><span class="tt-full">Full</span><span class="tt-short">F</span></span>
                </div>
                <div class="mid-col-glass">
                    <button class="mid-btn primary" id="linkMakeBtn" type="button" title="Связать выбранные">🎯</button>
                    <button class="mid-btn eye-toggle" id="hideLinkedBtn" type="button" title="Скрыть связанные">👁</button>
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
            </div>

            <div class="card" style="padding: 0; position: relative;">
                <div class="table-card-header" style="display: flex; align-items: center; justify-content: space-between; padding-right: 40px;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <div>Poster чеки</div>
                        <form method="POST" id="posterSyncForm" style="margin: 0;">
                            <input type="hidden" name="action" value="load_poster_checks">
                            <input type="hidden" name="dateFrom" value="<?= htmlspecialchars($dateFrom) ?>">
                            <input type="hidden" name="dateTo" value="<?= htmlspecialchars($dateTo) ?>">
                            <button class="btn primary" id="posterSyncBtn" type="submit" style="padding: 4px 8px; font-size: 11px;">Загрузить</button>
                        </form>
                    </div>
                    <div class="muted vc-subtitle">
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
                <button class="btn tiny" id="finance-refresh-all" type="button" title="Обновить">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#FBBF24" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle;">
                        <path d="M21 2v6h-6"></path>
                        <path d="M3 12a9 9 0 0 1 15-6.7L21 8"></path>
                        <path d="M3 22v-6h6"></path>
                        <path d="M21 12a9 9 0 0 1-15 6.7L3 16"></path>
                    </svg>
                </button>
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

            $vietnamExists = false;
            if (is_array($transferVietnamFoundList) && $vietnamVnd !== null) {
                foreach ($transferVietnamFoundList as $f) {
                    if ((int)$posterCentsToVnd((int)($f['sum_minor'] ?? 0)) === (int)$vietnamVnd) {
                        $vietnamExists = true;
                        break;
                    }
                }
            }
            
            $tipsExists = false;
            if (is_array($transferTipsFoundList) && $tipsVnd !== null) {
                foreach ($transferTipsFoundList as $f) {
                    if ((int)$posterCentsToVnd((int)($f['sum_minor'] ?? 0)) === (int)$tipsVnd) {
                        $tipsExists = true;
                        break;
                    }
                }
            }
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
                        <button class="btn btn-sm-orange" type="submit" <?= $vietnamDisabled ? 'disabled' : '' ?>>Создать транзакцию</button>
                    </div>
                    <div class="muted finance-status" style="margin-top: 6px;">
                        <?php if (count($transferVietnamFoundList) > 0): ?>
                            <div style="overflow-x:auto; max-width:100%;">
                                <table class="table" style="margin-top:5px; font-size:12px; width:100%;">
                                    <thead><tr><th style="padding:2px 4px;">Дата<br><span style="font-weight:normal;">Время</span></th><th style="padding:2px 4px;">Сумма</th><th style="padding:2px 4px;">Счет</th><th style="padding:2px 4px;">Кто</th><th style="padding:2px 4px;">Комментарий</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($transferVietnamFoundList as $f): ?>
                                        <?php
                                            $ts = (int)($f['ts'] ?? 0);
                                            $sumMinor = (int)($f['sum_minor'] ?? 0);
                                            $sumVnd = (int)$posterCentsToVnd($sumMinor);
                                            $tRaw = (string)($f['type'] ?? '');
                                            $isOut = ($tRaw === '0' || strtoupper($tRaw) === 'O' || strtolower($tRaw) === 'out');
                                            $sumSignedVnd = $isOut ? -$sumVnd : $sumVnd;
                                            $cmt = trim((string)($f['comment'] ?? ''));
                                            $u = trim((string)($f['user'] ?? ''));
                                            $acc = trim((string)($f['account'] ?? ''));
                                            $dateStr = date('d.m.Y', $ts);
                                            $timeStr = date('H:i:s', $ts);
                                        ?>
                                        <tr>
                                            <td style="padding:2px 4px; white-space:nowrap;"><?= htmlspecialchars($dateStr) ?><br><span class="muted"><?= htmlspecialchars($timeStr) ?></span></td>
                                            <td class="sum" style="padding:2px 4px; white-space:nowrap;"><?= htmlspecialchars($fmtVnd((int)$sumSignedVnd)) ?></td>
                                            <td style="padding:2px 4px; white-space:nowrap;"><?= htmlspecialchars($acc) ?></td>
                                            <td style="padding:2px 4px; white-space:nowrap;"><?= htmlspecialchars($u) ?></td>
                                            <td style="padding:2px 4px; line-height:1.2;"><?= htmlspecialchars($cmt) ?></td>
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
                    </div></form>
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
                        <button class="btn btn-sm-orange" type="submit" <?= $tipsDisabled ? 'disabled' : '' ?>>Создать транзакцию</button>
                    </div>
                    <div class="muted finance-status" style="margin-top: 6px;">
                        <?php if (count($transferTipsFoundList) > 0): ?>
                            <div style="overflow-x:auto; max-width:100%;">
                                <table class="table" style="margin-top:5px; font-size:12px; width:100%;">
                                    <thead><tr><th style="padding:2px 4px;">Дата<br><span style="font-weight:normal;">Время</span></th><th style="padding:2px 4px;">Сумма</th><th style="padding:2px 4px;">Счет</th><th style="padding:2px 4px;">Кто</th><th style="padding:2px 4px;">Комментарий</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($transferTipsFoundList as $f): ?>
                                        <?php
                                            $ts = (int)($f['ts'] ?? 0);
                                            $sumMinor = (int)($f['sum_minor'] ?? 0);
                                            $sumVnd = (int)$posterCentsToVnd($sumMinor);
                                            $tRaw = (string)($f['type'] ?? '');
                                            $isOut = ($tRaw === '0' || strtoupper($tRaw) === 'O' || strtolower($tRaw) === 'out');
                                            $sumSignedVnd = $isOut ? -$sumVnd : $sumVnd;
                                            $cmt = trim((string)($f['comment'] ?? ''));
                                            $u = trim((string)($f['user'] ?? ''));
                                            $acc = trim((string)($f['account'] ?? ''));
                                            $dateStr = date('d.m.Y', $ts);
                                            $timeStr = date('H:i:s', $ts);
                                        ?>
                                        <tr>
                                            <td style="padding:2px 4px; white-space:nowrap;"><?= htmlspecialchars($dateStr) ?><br><span class="muted"><?= htmlspecialchars($timeStr) ?></span></td>
                                            <td class="sum" style="padding:2px 4px; white-space:nowrap;"><?= htmlspecialchars($fmtVnd((int)$sumSignedVnd)) ?></td>
                                            <td style="padding:2px 4px; white-space:nowrap;"><?= htmlspecialchars($acc) ?></td>
                                            <td style="padding:2px 4px; white-space:nowrap;"><?= htmlspecialchars($u) ?></td>
                                            <td style="padding:2px 4px; line-height:1.2;"><?= htmlspecialchars($cmt) ?></td>
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
                    </div></form>
            </div>
        </div>
        <div class="card card-balances">
            <div style="display:flex; justify-content:flex-start; align-items:center; gap: 10px; margin-bottom: 10px; flex-wrap: wrap;">
                <div style="font-weight: 900;">Итоговый баланс</div>
                <div style="display:flex; gap: 8px; align-items:center;">
                    <button class="btn tiny" id="balanceSyncBtn" type="button" title="UPLD">UPLD</button>
                    <button class="btn tiny" id="posterAccountsBtn" type="button" title="Обновить балансы" style="padding: 4px 10px;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#FBBF24" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle;">
                            <path d="M21 2v6h-6"></path>
                            <path d="M3 12a9 9 0 0 1 15-6.7L21 8"></path>
                            <path d="M3 22v-6h6"></path>
                            <path d="M21 12a9 9 0 0 1-15 6.7L3 16"></path>
                        </svg>
                    </button>
                    <button class="btn tiny" id="posterBalancesTelegramBtn" type="button" title="Отправить в Telegram" style="padding: 4px 10px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="#0088cc" style="vertical-align: middle; margin-top: -2px;"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.64 6.8c-.15 1.58-.8 5.42-1.13 7.19-.14.75-.42 1-.68 1.03-.58.05-1.02-.38-1.58-.75-.88-.58-1.38-.94-2.23-1.5-.99-.65-.35-1.01.22-1.59.15-.15 2.71-2.48 2.76-2.69a.2.2 0 00-.05-.18c-.06-.05-.14-.03-.21-.02-.09.02-1.42.91-4.01 2.66-.38.26-.72.39-1.03.38-.34-.01-1-.19-1.48-.35-.59-.19-1.05-.29-1.01-.61.02-.17.29-.35.81-.54 3.17-1.38 5.28-2.29 6.33-2.73 3.01-1.26 3.63-1.48 4.04-1.48.09 0 .29.02.4.11.09.07.12.16.13.25.01.12.02.26.01.37z"/></svg></button>
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
            <div class="confirm-backdrop" id="payday2BetaModal">
                <div class="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="payday2BetaModalTitle" style="max-width: 440px;">
                    <h3 id="payday2BetaModalTitle">Payda2beta</h3>
                    <div class="body" style="text-align:center; line-height:1.35;">
                        Это обновленная и оптимизированная версия payday.
                        <br><br>
                        Если что-то не работает, надо сообщить Диме.
                    </div>
                    <div class="actions" style="justify-content:center;">
                        <a href="/payday/" class="btn2 primary" style="display:inline-flex; text-decoration:none; min-width: 140px; justify-content:center;">Payday</a>
                        <button type="button" class="btn2" id="payday2BetaModalClose" style="min-width: 140px;">Закрыть</button>
                    </div>
                </div>
            </div>
            
<script>
window.PAYDAY_CONFIG = {
    userEmail: <?= json_encode((string)($_SESSION['user_email'] ?? ''), JSON_UNESCAPED_UNICODE) ?>,
    dateFrom: <?= json_encode($dateFrom, JSON_UNESCAPED_UNICODE) ?>,
    dateTo: <?= json_encode($dateTo, JSON_UNESCAPED_UNICODE) ?>,
    links: <?= json_encode(array_values(array_map(function ($l) {
        return [
            'poster_transaction_id' => (int)$l['poster_transaction_id'],
            'sepay_id' => (int)$l['sepay_id'],
            'link_type' => (string)$l['link_type'],
            'is_manual' => !empty($l['is_manual']),
        ];
    }, $links)), JSON_UNESCAPED_UNICODE) ?>
};
</script>
<script src="/payday2/assets/js/payday2_telegram.js?v=<?= htmlspecialchars($payday2AssetVersion) ?>"></script>
<script src="/payday2/assets/js/payday2.js?v=<?= htmlspecialchars($payday2AssetVersion) ?>"></script>
<script src="/assets/payday.js?v=20260414_0100" defer></script>
</body>
</html>

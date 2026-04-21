<?php
require_once __DIR__ . '/config.php';
use App\Payday2\Config;
use App\Payday2\FinanceHelper;
use App\Payday2\LocalSettings;

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

/** @var array<int, array<int, int>> spot_id => [ table_id => table_num ] */
$posterTableNumsBySpot = [];
$spotIdsForHall = [];
foreach ($posterRows as $pr) {
    $sid = (int)($pr['spot_id'] ?? 0);
    if ($sid > 0) {
        $spotIdsForHall[$sid] = true;
    }
}
if ($spotIdsForHall !== []) {
    try {
        $apiHallTables = new \App\Classes\PosterAPI((string)$token);
        foreach (array_keys($spotIdsForHall) as $spotId) {
            try {
                $hallRows = $apiHallTables->request('spots.getTableHallTables', [
                    'spot_id' => $spotId,
                    'without_deleted' => 0,
                ], 'GET');
                if (!is_array($hallRows)) {
                    $hallRows = [];
                }
                $tidMap = [];
                foreach ($hallRows as $t) {
                    if (!is_array($t)) {
                        continue;
                    }
                    $tid = (int)($t['table_id'] ?? 0);
                    $tnum = (int)($t['table_num'] ?? 0);
                    if ($tid > 0 && $tnum > 0) {
                        $tidMap[$tid] = $tnum;
                    }
                }
                $posterTableNumsBySpot[$spotId] = $tidMap;
            } catch (\Throwable $e) {
                $posterTableNumsBySpot[$spotId] = [];
            }
        }
    } catch (\Throwable $e) {
    }
}

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
           AND poster_payment_method_id = " . Config::METHOD_BYBIT,
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
           AND poster_payment_method_id = " . Config::METHOD_VIETNAM,
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
           AND COALESCE(p.poster_payment_method_id, 0) <> " . Config::METHOD_VIETNAM,
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
if (isset($posterAccountsById[LocalSettings::accountAndreyId()]) || isset($posterAccountsById[LocalSettings::accountTipsId()])) {
    $posterBalanceAndrey = (int)($posterAccountsById[LocalSettings::accountAndreyId()]['balance'] ?? 0) + (int)($posterAccountsById[LocalSettings::accountTipsId()]['balance'] ?? 0);
}
if (isset($posterAccountsById[LocalSettings::accountVietnamId()])) {
    $posterBalanceVietnam = (int)($posterAccountsById[LocalSettings::accountVietnamId()]['balance'] ?? 0);
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

$fmtVnd = function (int $val): string { return FinanceHelper::fmtVnd($val); };
$fmtVndCents = function (int $cents): string { return FinanceHelper::fmtVndCents($cents); };
$payday2CsrfToken = payday2_ensure_csrf();
$payday2AssetVersion = '20260421_0080';
$payday2ClientConfig = [
    'userEmail' => (string)($_SESSION['user_email'] ?? ''),
    'csrfToken' => $payday2CsrfToken,
    'dateFrom' => $dateFrom,
    'dateTo' => $dateTo,
    'localSettings' => LocalSettings::toClientPayload(),
    'links' => array_values(array_map(static function ($l) {
        return [
            'poster_transaction_id' => (int)$l['poster_transaction_id'],
            'sepay_id' => (int)$l['sepay_id'],
            'link_type' => (string)$l['link_type'],
            'is_manual' => (int)$l['is_manual'],
        ];
    }, $links)),
];
$payday2ConfigJsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
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
  <link rel="stylesheet" href="/payday2/assets/css/payday2.css?v=<?= htmlspecialchars($payday2AssetVersion) ?>">
 </head>
<body>
<div class="container">
    <div class="top-nav">
        <div class="nav-left">
            <div class="pd2-nav-title-row pd2-d-flex pd2-align-center pd2-gap-8">
                <div class="nav-title pd2-pointer" id="payday2InfoBtn">Payday2</div>
                <button type="button" class="btn pd2-p-4-10 pd2-settings-gear" id="payday2HelpToggleBtn" title="Справка">❓</button>
                <button type="button" class="btn pd2-p-4-10 pd2-settings-gear" id="payday2SettingsBtn" title="Настройки Payday2" data-help-abs="Настройки интеграции с Telegram и счетами Poster.">⚙</button>
                <div class="pd2-d-flex">
                    <button type="button" class="pd2-icon-btn" id="btnKashShift" title="KashShift" data-help-abs="Просмотр кассовых смен из Poster.">
                        <img src="/payday2/img/Cash.png" alt="KashShift">
                    </button>
                    <button type="button" class="pd2-icon-btn pd2-ml-5" id="btnSupplies" title="Supplies" data-help-abs="Просмотр списка поставок из Poster.">
                        <img src="/payday2/img/Supply.png" alt="Supplies">
                    </button>
                </div>
                <button type="button" class="pd2-icon-btn pd2-ml-5" id="payday2CheckFinderBtn" title="Чек" data-help-abs="Поиск и удаление чека Poster по номеру.">
                    <img src="/payday2/img/receipt.png" alt="Чек">
                </button>
            </div>
            <div class="tabs" data-help="Переключение режимов сверки финансов: приходы (IN) или расходы (OUT).">
                <button type="button" class="tab active" id="tabIn" data-help-abs="Режим IN: Сверка входящих платежей.">IN</button>
                <button type="button" class="tab" id="tabOut" data-help-abs="Режим OUT: Сверка исходящих платежей (затрат).">OUT</button>
            </div>
            <div id="topFormsWrap" class="pd2-top-forms-wrap" data-help="Блок выбора рабочего периода для загрузки транзакций.">
                <form method="GET" id="dateForm" class="pd2-m-0 pd2-d-flex pd2-align-center pd2-gap-10 pd2-pos-relative">
                    <input type="date" name="dateFrom" value="<?= htmlspecialchars($dateFrom) ?>" class="btn pd2-date-input pd2-ws-nowrap" data-help-abs="Начальная дата (или единственный день).">
                    <input type="date" name="dateTo" value="<?= htmlspecialchars($dateTo) ?>" class="btn pd2-date-input pd2-d-none" data-help-abs="Конечная дата.">
                    <div id="dateFormLoader" class="pd2-d-none pd2-align-center pd2-ml-4">
                        <svg class="pd2-loader-spin pd2-v-align-mid" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 12a9 9 0 1 1-6.219-8.56"></path>
                        </svg>
                    </div>
                </form>
                <form method="POST" id="clearDayForm" class="pd2-m-0 pd2-d-none">
                    <input type="hidden" name="payday2_csrf" value="<?= htmlspecialchars($payday2CsrfToken) ?>">
                    <input type="hidden" name="action" value="clear_day">
                    <input type="hidden" name="dateFrom" value="<?= htmlspecialchars($dateFrom) ?>">
                    <input type="hidden" name="dateTo" value="<?= htmlspecialchars($dateTo) ?>">
                </form>
                <div class="pd2-d-flex pd2-align-center pd2-gap-10 pd2-ws-nowrap pd2-d-none">
                    <button class="btn pd2-ws-nowrap" type="submit" form="dateForm">Открыть</button>
                </div>
            </div>
        </div>
        <?php require __DIR__ . '/../partials/user_menu.php'; ?>
    </div>

    <?php if ($error !== ''): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="card">
        <div id="outSection" class="pd2-d-none">
            <div class="grid pd2-pos-relative" id="outGrid">
                <div id="outLineLayer"></div>
                <div class="card pd2-p-0 pd2-pos-relative" data-help="Таблица фактических денежных транзакций (списаний), полученных из банка или почты. Отражает реальное движение средств.">
                    <div class="table-card-header pd2-card-header">
                        <div class="pd2-card-header-title">
                            <div class="pd2-ws-nowrap">Деньги 📧</div>
                            <button class="btn tiny" id="outMailBtn" type="button" title="Загрузить из почты" data-help-abs="Загрузить свежие банковские транзакции из почты.">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#FBBF24" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="pd2-v-align-mid">
                                    <path d="M21 2v6h-6"></path>
                                    <path d="M3 12a9 9 0 0 1 15-6.7L21 8"></path>
                                    <path d="M3 22v-6h6"></path>
                                    <path d="M21 12a9 9 0 0 1-15 6.7L3 16"></path>
                                </svg>
                            </button>
                        </div>
                        <div class="muted vc-subtitle">
                            <button type="button" class="vc-toggle" id="toggleOutMailHiddenBtn" title="Показать/скрыть скрытые">👁</button>
                        </div>
                    </div>
                    <div id="outSepayScroll" class="pd2-scroll-container">
                        <table id="outSepayTable" class="pd2-data-table">
                            <thead><tr><th class="col-out-hide"></th><th class="col-out-content">Content</th><th class="nowrap col-out-time">Время</th><th class="nowrap col-out-sum">Сумма</th><th class="col-out-select"></th><th class="col-out-anchor"></th></tr></thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
                <div class="mid-col" id="outMidCol" data-help="Блок управления связями. Позволяет выбрать строки из обеих таблиц и сопоставить их друг с другом.">
                    <div class="toggle-wrap pd2-toggle-wrap" title="Lite/Full">
                        <span class="toggle-text"><span class="tt-full">Lite</span><span class="tt-short">L</span></span>
                        <label class="switch">
                            <input id="modeToggleOut" type="checkbox">
                            <span class="slider"></span>
                        </label>
                        <span class="toggle-text"><span class="tt-full">Full</span><span class="tt-short">F</span></span>
                    </div>
                    <div class="mid-col-glass">
                        <button class="mid-btn primary" id="outLinkMakeBtn" type="button" title="Связать выбранные" data-help-abs="Связать выбранные банковские переводы с транзакциями Poster." disabled>🎯</button>
                        <button class="mid-btn eye-toggle" id="outHideLinkedBtn" type="button" title="Скрыть связанные">👁</button>
                        <button class="mid-btn" id="outLinkAutoBtn" type="button" title="Автосвязи">🧩</button>
                        <button class="mid-btn" id="outLinkClearBtn" type="button" title="Разорвать связи">⛓️‍💥</button>
                        <div class="muted pd2-text-center pd2-fw-900 pd2-lh-135">
                            <div>←</div>
                            <div id="outSelSepaySum">0</div>
                            <div class="pd2-h-10"></div>
                            <div>→</div>
                            <div id="outSelPosterSum">0</div>
                            <div class="pd2-h-10"></div>
                            <div id="outSelMatch" class="pd2-fs-16 pd2-color-green">✅</div>
                            <div id="outSelDiff" class="pd2-fw-900">0</div>
                        </div>
                    </div>
                </div>
                <div class="card pd2-p-0 pd2-pos-relative" data-help="Таблица транзакций (расходов), созданных кассирами или системой в Poster. Отражает учетные данные.">
                    <div class="table-card-header pd2-card-header">
                        <div class="pd2-card-header-title">
                            <div class="pd2-ws-nowrap">Poster тр-ии</div>
                            <button class="btn tiny" id="outFinanceBtn" type="button" title="Загрузить" data-help-abs="Загрузить актуальные транзакции из системы Poster.">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#FBBF24" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="pd2-v-align-mid">
                                    <path d="M21 2v6h-6"></path>
                                    <path d="M3 12a9 9 0 0 1 15-6.7L21 8"></path>
                                    <path d="M3 22v-6h6"></path>
                                    <path d="M21 12a9 9 0 0 1-15 6.7L3 16"></path>
                                </svg>
                            </button>
                        </div>
                    </div>
                    <div id="outPosterScroll" class="pd2-scroll-container">
                        <table id="outPosterTable" class="pd2-data-table">
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

        <div class="grid pd2-pos-relative" id="tablesRoot">
            <div id="lineLayer"></div>
            <div class="card pd2-p-0 pd2-pos-relative" data-help="Таблица фактических денежных транзакций (приходов), полученных из банка или почты. Отражает реальные поступления.">
                <div class="table-card-header pd2-card-header">
                    <div class="pd2-card-header-title">
                        <div class="pd2-ws-nowrap">Деньги</div>
                        <form method="POST" id="sepaySyncForm" class="pd2-m-0 pd2-ws-nowrap">
                            <input type="hidden" name="payday2_csrf" value="<?= htmlspecialchars($payday2CsrfToken) ?>">
                            <input type="hidden" name="action" value="reload_sepay_api">
                            <input type="hidden" name="dateFrom" value="<?= htmlspecialchars($dateFrom) ?>">
                            <input type="hidden" name="dateTo" value="<?= htmlspecialchars($dateTo) ?>">
                            <button class="btn tiny" id="sepaySyncBtn" type="submit" title="Загрузить" data-help-abs="Загрузить свежие банковские приходы из почты.">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#FBBF24" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="pd2-v-align-mid">
                                    <path d="M21 2v6h-6"></path>
                                    <path d="M3 12a9 9 0 0 1 15-6.7L21 8"></path>
                                    <path d="M3 22v-6h6"></path>
                                    <path d="M21 12a9 9 0 0 1-15 6.7L3 16"></path>
                                </svg>
                            </button>
                        </form>
                    </div>
                    <div class="muted vc-subtitle">
                        <button type="button" class="vc-toggle" id="toggleSepayHiddenBtn" title="Показать/скрыть скрытые транзакции">👁</button>
                    </div>
                </div>
                <div id="sepayScroll" class="pd2-scroll-container">
                    <table id="sepayTable" class="pd2-data-table">
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
                                <td class="nowrap col-sepay-hide"><button type="button" class="sepay-hide" data-sepay-id="<?= $sid ?>" title="Скрыть (не чек)" data-help-abs="Скрыть транзакцию, если она не относится к Poster.">−</button></td>
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
                                <td class="nowrap col-sepay-hide"><button type="button" class="sepay-hide" data-sepay-id="<?= $sid ?>" title="Изменить комментарий скрытия" data-help-abs="Восстановить скрытую транзакцию или изменить ее комментарий.">−</button></td>
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
                <div class="muted table-footer-stats">
                    <span>Итого: <span id="sepayTotal"><?= htmlspecialchars($fmtVnd((int)$sepayTotalVnd)) ?></span></span>
                    <span>• связанные: <span id="sepayLinked">—</span></span>
                    <span>• несвязанные: <span id="sepayUnlinked">—</span></span>
                </div>
            </div>

            <div class="mid-col" id="midCol" data-help="Блок управления связями (приходы). Выбирайте чекбоксы и сопоставляйте банковские поступления с чеками Poster.">
                <div class="toggle-wrap pd2-toggle-wrap" title="Lite/Full">
                    <span class="toggle-text"><span class="tt-full">Lite</span><span class="tt-short">L</span></span>
                    <label class="switch">
                        <input id="modeToggle" type="checkbox">
                        <span class="slider"></span>
                    </label>
                    <span class="toggle-text"><span class="tt-full">Full</span><span class="tt-short">F</span></span>
                </div>
                <div class="mid-col-glass">
                    <button class="mid-btn primary" id="linkMakeBtn" type="button" title="Связать выбранные" data-help-abs="Ручное связывание выбранных банковских приходов с чеками Poster.">🎯</button>
                    <button class="mid-btn eye-toggle" id="hideLinkedBtn" type="button" title="Скрыть связанные" data-help-abs="Скрыть/показать строки, которые уже связаны.">👁</button>
                    <button class="mid-btn" id="linkAutoBtn" type="button" title="Автосвязи за день" data-help-abs="Автоматически связать совпадающие по сумме и времени транзакции.">🧩</button>
                    <button class="mid-btn" id="linkClearBtn" type="button" title="Разорвать связи" data-help-abs="Разорвать ошибочную связь у выбранных строк.">⛓️‍💥</button>
                    <div class="muted pd2-text-center pd2-fw-900 pd2-lh-135">
                        <div>←</div>
                        <div id="selSepaySum">0</div>
                        <div class="pd2-h-10"></div>
                        <div>→</div>
                        <div id="selPosterSum">0</div>
                        <div class="pd2-h-10"></div>
                        <div id="selMatch" class="pd2-fs-16">❗</div>
                        <div id="selDiff" class="pd2-fw-900">0</div>
                    </div>
                    <div class="muted mid-legend pd2-text-center pd2-fw-900 pd2-lh-135">
                        <div><span class="pd2-legend-line pd2-legend-line--green" aria-hidden="true"></span>Авто 1</div>
                        <div><span class="pd2-legend-line pd2-legend-line--yellow" aria-hidden="true"></span>Авто 2</div>
                        <div><span class="pd2-legend-line pd2-legend-line--gray" aria-hidden="true"></span>Ручная связь</div>
                    </div>
                    <div class="muted pd2-text-center pd2-fw-900 pd2-mt-6">
                        <span id="totalsDiff">—</span>
                    </div>
                </div>
                <button class="pd2-soft-reset-btn" id="clearDayBtn" type="submit" form="clearDayForm" title="Soft reset: Poster/SePay за дату помечаются was_deleted; без физического удаления; после синка записи восстанавливаются.">
                    <img src="/payday2/img/reset.png" alt="SoftReset">
                </button>
            </div>

            <div class="card pd2-p-0 pd2-pos-relative" data-help="Таблица чеков из Poster, оплаченных картой или чаевыми. Отражает приходы по учету.">
                <div class="table-card-header pd2-card-header">
                    <div class="pd2-card-header-title">
                        <div class="pd2-ws-nowrap">Poster чеки</div>
                        <form method="POST" id="posterSyncForm" class="pd2-m-0 pd2-ws-nowrap">
                            <input type="hidden" name="payday2_csrf" value="<?= htmlspecialchars($payday2CsrfToken) ?>">
                            <input type="hidden" name="action" value="load_poster_checks">
                            <input type="hidden" name="dateFrom" value="<?= htmlspecialchars($dateFrom) ?>">
                            <input type="hidden" name="dateTo" value="<?= htmlspecialchars($dateTo) ?>">
                            <button class="btn tiny" id="posterSyncBtn" type="submit" title="Загрузить" data-help-abs="Загрузить чеки (безнал) из системы Poster.">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#FBBF24" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="pd2-v-align-mid">
                                    <path d="M21 2v6h-6"></path>
                                    <path d="M3 12a9 9 0 0 1 15-6.7L21 8"></path>
                                    <path d="M3 22v-6h6"></path>
                                    <path d="M21 12a9 9 0 0 1-15 6.7L3 16"></path>
                                </svg>
                            </button>
                        </form>
                    </div>
                    <div class="muted vc-subtitle">
                        <button type="button" class="vc-toggle" id="toggleVietnamBtn" title="Показать/скрыть Vietnam Company">👁</button>
                    </div>
                </div>
                <div id="posterScroll" class="pd2-scroll-container">
                    <table id="posterTable" class="pd2-data-table">
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
                                $tableNum = null;
                                if ($spotIdRow > 0 && $tableIdRow > 0) {
                                    $hallMap = $posterTableNumsBySpot[$spotIdRow] ?? [];
                                    if (isset($hallMap[$tableIdRow])) {
                                        $tableNum = (int)$hallMap[$tableIdRow];
                                    }
                                }
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
                <div class="muted table-footer-stats">
                    <span>Итого: <span id="posterTotal"><?= htmlspecialchars($fmtVnd((int)$posterTotalVnd)) ?></span></span>
                    <span>• Tips: <span id="posterTipsLinked">—</span></span>
                    <span>• в таблице связи: <span id="posterLinked">—</span></span>
                    <span>• несвязи: <span id="posterUnlinked">—</span></span>
                    <span>• BB: <span><?= htmlspecialchars($fmtVnd((int)$posterBybitVnd)) ?></span></span>
                    <span>• VC: <span><?= htmlspecialchars($fmtVnd((int)$posterVietVnd)) ?></span></span>
                </div>
            </div>
        </div>

        <div class="divider"></div>

        <div class="bottom-two" data-help="Блок дополнительных операций: создание сводных финансовых транзакций по итогам дня и проверка текущих балансов счетов.">
            <div class="card card-finance" data-help="Автоматическое создание финансовых транзакций (переводов) в Poster на основе загруженных чеков.">
                <div class="pd2-justify-between pd2-align-center pd2-d-flex pd2-mb-10">
                    <div class="pd2-fw-900">Финансовые транзакции</div>
                    <button class="btn tiny" id="finance-refresh-all" type="button" title="Обновить" data-help-abs="Обновить статусы созданных финансовых транзакций.">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#FBBF24" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="pd2-v-align-mid">
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
                : 'Сумма = 0: нет чеков Vietnam Company (payment_method_id=' . Config::METHOD_VIETNAM . ') за выбранный период.';
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
                <form method="POST" class="finance-transfer pd2-finance-transfer-full"
                      data-kind="vietnam"
                      data-date-from="<?= htmlspecialchars($dateFrom) ?>"
                      data-date-to="<?= htmlspecialchars($dateTo) ?>"
                      data-account-from-id="<?= (int)LocalSettings::accountAndreyId() ?>"
                      data-account-to-id="<?= (int)LocalSettings::accountVietnamId() ?>"
                      data-account-from-name="<?= htmlspecialchars((string)($posterAccountsById[LocalSettings::accountAndreyId()]['name'] ?? '#' . LocalSettings::accountAndreyId())) ?>"
                      data-account-to-name="<?= htmlspecialchars((string)($posterAccountsById[LocalSettings::accountVietnamId()]['name'] ?? '#' . LocalSettings::accountVietnamId())) ?>"
                      data-sum-vnd="<?= htmlspecialchars((string)($vietnamVnd !== null ? (int)$vietnamVnd : 0)) ?>">
                    <input type="hidden" name="payday2_csrf" value="<?= htmlspecialchars($payday2CsrfToken) ?>">
                    <input type="hidden" name="action" value="create_transfer">
                    <input type="hidden" name="kind" value="vietnam">
                    <input type="hidden" name="dateFrom" value="<?= htmlspecialchars($dateFrom) ?>">
                    <input type="hidden" name="dateTo" value="<?= htmlspecialchars($dateTo) ?>">
                    <div class="pd2-finance-head">
                        <div class="pd2-finance-head-title">Vietnam Company</div>
                        <div class="pd2-finance-head-total"><?= $vietnamVnd !== null ? htmlspecialchars($fmtVnd((int)$vietnamVnd)) : '—' ?></div>
                        <button class="btn btn-sm-orange" type="submit" <?= $vietnamDisabled ? 'disabled' : '' ?>>Создать транзакцию</button>
                    </div>
                    <div class="muted finance-status pd2-finance-status-mt">
                        <?php if (count($transferVietnamFoundList) > 0): ?>
                            <div class="pd2-finance-scroll-x">
                                <table class="table pd2-finance-mini-table">
                                    <thead><tr><th class="pd2-finance-th">Дата<br><span class="pd2-fw-normal">Время</span></th><th class="pd2-finance-th">Сумма</th><th class="pd2-finance-th">Счет</th><th class="pd2-finance-th">Кто</th><th class="pd2-finance-th">Комментарий</th></tr></thead>
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
                                            <td class="pd2-finance-td"><?= htmlspecialchars($dateStr) ?><br><span class="muted"><?= htmlspecialchars($timeStr) ?></span></td>
                                            <td class="sum pd2-finance-td"><?= htmlspecialchars($fmtVnd((int)$sumSignedVnd)) ?></td>
                                            <td class="pd2-finance-td"><?= htmlspecialchars($acc) ?></td>
                                            <td class="pd2-finance-td"><?= htmlspecialchars($u) ?></td>
                                            <td class="pd2-finance-td-comment"><?= htmlspecialchars($cmt) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php elseif ($vietnamDisabled): ?>
                            <?= htmlspecialchars($vietnamDisabledReason) ?>
                        <?php else: ?>
                            <span class="pd2-finance-empty">Транзакция не найдена</span>
                        <?php endif; ?>
                    </div></form>
            </div>

            <div class="finance-row">
                <form method="POST" class="finance-transfer pd2-finance-transfer-full"
                      data-kind="tips"
                      data-date-from="<?= htmlspecialchars($dateFrom) ?>"
                      data-date-to="<?= htmlspecialchars($dateTo) ?>"
                      data-account-from-id="<?= (int)LocalSettings::accountAndreyId() ?>"
                      data-account-to-id="<?= (int)LocalSettings::accountTipsId() ?>"
                      data-account-from-name="<?= htmlspecialchars((string)($posterAccountsById[LocalSettings::accountAndreyId()]['name'] ?? '#' . LocalSettings::accountAndreyId())) ?>"
                      data-account-to-name="<?= htmlspecialchars((string)($posterAccountsById[LocalSettings::accountTipsId()]['name'] ?? '#' . LocalSettings::accountTipsId())) ?>"
                      data-sum-vnd="<?= htmlspecialchars((string)($tipsVnd !== null ? (int)$tipsVnd : 0)) ?>">
                    <input type="hidden" name="payday2_csrf" value="<?= htmlspecialchars($payday2CsrfToken) ?>">
                    <input type="hidden" name="action" value="create_transfer">
                    <input type="hidden" name="kind" value="tips">
                    <input type="hidden" name="dateFrom" value="<?= htmlspecialchars($dateFrom) ?>">
                    <input type="hidden" name="dateTo" value="<?= htmlspecialchars($dateTo) ?>">
                    <div class="pd2-finance-head">
                        <div class="pd2-finance-head-title">Tips</div>
                        <div class="pd2-finance-head-total"><?= $tipsVnd !== null ? htmlspecialchars($fmtVnd((int)$tipsVnd)) : '—' ?></div>
                        <button class="btn btn-sm-orange" type="submit" <?= $tipsDisabled ? 'disabled' : '' ?>>Создать транзакцию</button>
                    </div>
                    <div class="muted finance-status pd2-finance-status-mt">
                        <?php if (count($transferTipsFoundList) > 0): ?>
                            <div class="pd2-finance-scroll-x">
                                <table class="table pd2-finance-mini-table">
                                    <thead><tr><th class="pd2-finance-th">Дата<br><span class="pd2-fw-normal">Время</span></th><th class="pd2-finance-th">Сумма</th><th class="pd2-finance-th">Счет</th><th class="pd2-finance-th">Кто</th><th class="pd2-finance-th">Комментарий</th></tr></thead>
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
                                            <td class="pd2-finance-td"><?= htmlspecialchars($dateStr) ?><br><span class="muted"><?= htmlspecialchars($timeStr) ?></span></td>
                                            <td class="sum pd2-finance-td"><?= htmlspecialchars($fmtVnd((int)$sumSignedVnd)) ?></td>
                                            <td class="pd2-finance-td"><?= htmlspecialchars($acc) ?></td>
                                            <td class="pd2-finance-td"><?= htmlspecialchars($u) ?></td>
                                            <td class="pd2-finance-td-comment"><?= htmlspecialchars($cmt) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php elseif ($tipsDisabled): ?>
                            <?= htmlspecialchars($tipsDisabledReason) ?>
                        <?php else: ?>
                            <span class="pd2-finance-empty">Транзакция не найдена</span>
                        <?php endif; ?>
                    </div></form>
            </div>
        </div>
        <div class="card card-balances" data-help="Сводка текущих балансов по счетам в Poster. Позволяет контролировать расхождения между расчетным и фактическим балансом.">
            <div class="pd2-justify-between pd2-align-center pd2-d-flex pd2-gap-10 pd2-mb-10 pd2-flex-wrap">
                <div class="pd2-fw-900">Итоговый баланс</div>
                <div class="pd2-d-flex pd2-gap-8 pd2-align-center pd2-ml-auto">
                    <button class="btn tiny" id="balanceSyncBtn" type="button" title="UPLD" data-help-abs="Сохранить/загрузить фактические балансы (если предусмотрено API).">UPLD</button>
                    <button class="btn tiny pd2-p-4-10" id="posterAccountsBtn" type="button" title="Обновить балансы" data-help-abs="Обновить текущие балансы счетов из Poster.">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#FBBF24" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="pd2-v-align-mid">
                            <path d="M21 2v6h-6"></path>
                            <path d="M3 12a9 9 0 0 1 15-6.7L21 8"></path>
                            <path d="M3 22v-6h6"></path>
                            <path d="M21 12a9 9 0 0 1-15 6.7L3 16"></path>
                        </svg>
                    </button>
                    <button class="btn tiny pd2-p-4-10" id="posterBalancesTelegramBtn" type="button" title="Отправить в Telegram" data-help-abs-right="Отправить итоговый отчет по балансам в привязанный Telegram-чат.">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="#0088cc" class="pd2-v-align-mid pd2-mt-n2"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.64 6.8c-.15 1.58-.8 5.42-1.13 7.19-.14.75-.42 1-.68 1.03-.58.05-1.02-.38-1.58-.75-.88-.58-1.38-.94-2.23-1.5-.99-.65-.35-1.01.22-1.59.15-.15 2.71-2.48 2.76-2.69a.2.2 0 00-.05-.18c-.06-.05-.14-.03-.21-.02-.09.02-1.42.91-4.01 2.66-.38.26-.72.39-1.03.38-.34-.01-1-.19-1.48-.35-.59-.19-1.05-.29-1.01-.61.02-.17.29-.35.81-.54 3.17-1.38 5.28-2.29 6.33-2.73 3.01-1.26 3.63-1.48 4.04-1.48.09 0 .29.02.4.11.09.07.12.16.13.25.01.12.02.26.01.37z"/></svg></button>
                </div>
            </div>

            <div class="bal-grid pd2-mb-10">
                <table class="pd2-w-100 pd2-collapse">
                    <thead>
                    <tr>
                        <th class="pd2-text-left">Показатель</th>
                        <th class="pd2-text-right">Poster</th>
                        <th class="pd2-text-right">Факт.</th>
                        <th class="pd2-text-right">Разница</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr data-key="andrey">
                        <td class="pd2-fw-900">Счет Андрей</td>
                        <td class="pd2-text-right">
                            <span id="balAndrey" data-cents="<?= $posterBalanceAndrey !== null ? (int)$posterBalanceAndrey : '' ?>"><?= $posterBalanceAndrey !== null ? htmlspecialchars($fmtVndCents((int)$posterBalanceAndrey)) : '—' ?></span>
                        </td>
                        <td class="pd2-text-right"><input id="balAndreyActual" type="text" inputmode="numeric" placeholder="0" class="pd2-text-right"></td>
                        <td class="pd2-text-right"><span id="balAndreyDiff">—</span></td>
                    </tr>
                    <tr data-key="vietnam">
                        <td class="pd2-fw-900">Вьет. счет</td>
                        <td class="pd2-text-right">
                            <span id="balVietnam" data-cents="<?= $posterBalanceVietnam !== null ? (int)$posterBalanceVietnam : '' ?>"><?= $posterBalanceVietnam !== null ? htmlspecialchars($fmtVndCents((int)$posterBalanceVietnam)) : '—' ?></span>
                        </td>
                        <td class="pd2-text-right"><input id="balVietnamActual" type="text" inputmode="numeric" placeholder="0" class="pd2-text-right"></td>
                        <td class="pd2-text-right"><span id="balVietnamDiff">—</span></td>
                    </tr>
                    <tr data-key="cash">
                        <td class="pd2-fw-900">Касса</td>
                        <td class="pd2-text-right">
                            <span id="balCash" data-cents="<?= $posterBalanceCash !== null ? (int)$posterBalanceCash : '' ?>"><?= $posterBalanceCash !== null ? htmlspecialchars($fmtVndCents((int)$posterBalanceCash)) : '—' ?></span>
                        </td>
                        <td class="pd2-text-right"><input id="balCashActual" type="text" inputmode="numeric" placeholder="0" class="pd2-text-right"></td>
                        <td class="pd2-text-right"><span id="balCashDiff">—</span></td>
                    </tr>
                    <tr data-key="total">
                        <td class="pd2-fw-900">Total</td>
                        <td class="pd2-text-right">
                            <span id="balTotal" data-cents="<?= $posterBalanceTotal !== null ? (int)$posterBalanceTotal : '' ?>"><?= $posterBalanceTotal !== null ? htmlspecialchars($fmtVndCents((int)$posterBalanceTotal)) : '—' ?></span>
                        </td>
                        <td class="pd2-text-right"><input id="balTotalActual" type="text" inputmode="numeric" placeholder="0" class="pd2-text-right" readonly></td>
                        <td class="pd2-text-right"><span id="balTotalDiff">—</span></td>
                    </tr>
                    </tbody>
                </table>
            </div>

            <div class="bal-grid pd2-max-h-260">
                <table class="pd2-w-100 pd2-collapse">
                    <thead>
                    <tr>
                        <th class="pd2-acct-th">ID</th>
                        <th class="pd2-acct-th">Счёт</th>
                        <th class="pd2-acct-th-num">Баланс</th>
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
                            <td class="pd2-acct-td"><?= htmlspecialchars((string)$aid) ?></td>
                            <td class="pd2-acct-td"><?= htmlspecialchars($an) ?></td>
                            <td class="pd2-acct-td-num"><?= htmlspecialchars($fmtVndCents($bal)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (count($posterAccounts) === 0): ?>
                        <tr class="pd2-acct-empty-row"><td colspan="3">Нет данных: нажми 🔄</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        </div>
    </div>
</div>

<div class="confirm-backdrop pd2-modal-backdrop" id="kashshiftModal">
    <div class="confirm-modal pd2-modal-content" role="dialog">
        <div class="pd2-modal-header">
            <h3 class="pd2-m-0">KashShift</h3>
            <button type="button" class="pd2-modal-close" id="kashshiftClose">✕</button>
        </div>
        <div class="body pd2-modal-body" id="kashshiftBody">
            <div class="pd2-text-center">Загрузка...</div>
        </div>
    </div>
</div>

<div class="confirm-backdrop pd2-modal-backdrop" id="suppliesModal">
    <div class="confirm-modal pd2-modal-content" role="dialog">
        <div class="pd2-modal-header">
            <h3 class="pd2-m-0">Supplies</h3>
            <button type="button" class="pd2-modal-close" id="suppliesClose">✕</button>
        </div>
        <div class="body pd2-modal-body" id="suppliesBody">
            <div class="pd2-text-center">Загрузка...</div>
        </div>
    </div>
</div>

            <!-- Модалка создания транзакции -->
            <div class="confirm-backdrop pd2-modal-backdrop" id="createTxModal">
                <div class="confirm-modal pd2-modal-content" role="dialog" style="max-width: 400px;">
                    <div class="pd2-modal-header">
                        <h3 class="pd2-m-0">Новая транзакция</h3>
                        <button type="button" class="pd2-modal-close" id="createTxClose">✕</button>
                    </div>
                    <div class="body pd2-modal-body pd2-p-15">
                        <form id="createTxForm">
                            <div class="pd2-mb-10">
                                <label class="pd2-d-block pd2-mb-4 pd2-fw-900 muted">Дата и время</label>
                                <div class="pd2-d-flex pd2-gap-10">
                                    <input type="date" id="createTxDate" class="btn pd2-w-100" required>
                                    <input type="time" id="createTxTime" class="btn pd2-w-100" required>
                                </div>
                            </div>
                            <div class="pd2-mb-10">
                                <label class="pd2-d-block pd2-mb-4 pd2-fw-900 muted">Тип</label>
                                <select id="createTxType" class="btn pd2-w-100">
                                    <option value="2">Расход</option>
                                    <option value="1">Приход</option>
                                    <option value="3">Перевод</option>
                                </select>
                            </div>
                            <div class="pd2-mb-10" id="createTxAccountFromWrap">
                                <label class="pd2-d-block pd2-mb-4 pd2-fw-900 muted">Счет списания</label>
                                <select id="createTxAccountFrom" class="btn pd2-w-100"></select>
                            </div>
                            <div class="pd2-mb-10 pd2-d-none" id="createTxAccountToWrap">
                                <label class="pd2-d-block pd2-mb-4 pd2-fw-900 muted">Счет пополнения</label>
                                <select id="createTxAccountTo" class="btn pd2-w-100"></select>
                            </div>
                            <div class="pd2-mb-10">
                                <label class="pd2-d-block pd2-mb-4 pd2-fw-900 muted">Сумма (VND)</label>
                                <input type="number" id="createTxAmount" class="btn pd2-w-100" step="1" min="1" required>
                            </div>
                            <div class="pd2-mb-10">
                                <label class="pd2-d-block pd2-mb-4 pd2-fw-900 muted">Категория</label>
                                <select id="createTxCategory" class="btn pd2-w-100"></select>
                            </div>
                            <div class="pd2-mb-10">
                                <label class="pd2-d-block pd2-mb-4 pd2-fw-900 muted">Комментарий</label>
                                <input type="text" id="createTxComment" class="btn pd2-w-100">
                            </div>
                            <div id="createTxError" class="error pd2-d-none pd2-mb-10"></div>
                            <button type="submit" class="btn primary pd2-w-100 pd2-fw-900" id="createTxSubmitBtn">Создать</button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Модалка успешного создания транзакции -->
            <div class="confirm-backdrop pd2-modal-backdrop" id="createTxSuccessModal">
                <div class="confirm-modal pd2-modal-content" role="dialog" style="max-width: 400px;">
                    <div class="pd2-modal-header">
                        <h3 class="pd2-m-0">Успешно</h3>
                        <button type="button" class="pd2-modal-close" id="createTxSuccessClose">✕</button>
                    </div>
                    <div class="body pd2-modal-body pd2-p-15 pd2-text-center">
                        <div class="pd2-mb-15 pd2-fs-16" id="createTxSuccessTitle">
                            Транзакция успешно создана в Poster!
                        </div>
                        <div id="createTxSuccessDetails" class="muted pd2-mb-15"></div>
                    </div>
                </div>
            </div>

            <div class="confirm-backdrop pd2-modal-backdrop" id="checkFinderModal">
                <div class="confirm-modal pd2-modal-content" role="dialog" style="width: max-content; max-width: 96vw;">
                    <div class="pd2-modal-header">
                        <h3 class="pd2-m-0">Чек</h3>
                        <button type="button" class="pd2-modal-close" id="checkFinderClose">✕</button>
                    </div>
                    <div class="body pd2-modal-body pd2-p-15">
                        <div class="pd2-mb-10">
                            <div class="pd2-d-flex pd2-align-center pd2-justify-center">
                                <input type="text" id="checkFinderNumber" class="btn pd2-check-search-input" autocomplete="off" placeholder="поиск по любому тексту">
                            </div>
                        </div>
                        <div id="checkFinderError" class="error pd2-d-none pd2-mb-10"></div>
                        <div id="checkFinderResult" class="pd2-fs-13"></div>
                        <div class="pd2-mt-10 pd2-d-none" id="checkFinderActions">
                            <button type="button" class="btn2 pd2-btn-danger" id="checkFinderDeleteBtn">Удалить</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="confirm-backdrop" id="financeConfirm">
                <div class="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="financeConfirmTitle">
                    <h3 id="financeConfirmTitle">Подтверждение</h3>
                    <div class="body" id="financeConfirmText"></div>
                    <div class="sub">
                        <label class="pd2-finance-confirm-label">
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
            <div class="confirm-backdrop" id="payday2SettingsModal">
                <div class="confirm-modal pd2-settings-modal" role="dialog" aria-modal="true" aria-labelledby="payday2SettingsTitle">
                    <div class="pd2-modal-header">
                        <h3 id="payday2SettingsTitle" class="pd2-m-0">Настройки Payday2</h3>
                        <button type="button" class="pd2-modal-close" id="payday2SettingsClose">✕</button>
                    </div>
                    <p class="muted pd2-fs-12 pd2-mb-10">Сохраняется в <code>payday2/local_config.json</code> на сервере.</p>
                    <div class="pd2-settings-fields">
                        
                        <details class="pd2-settings-spoiler pd2-mb-10">
                            <summary class="pd2-fw-900 pd2-pointer pd2-p-8 pd2-bg-card pd2-border-radius-10 pd2-border">Телеграм</summary>
                            <div class="pd2-d-flex pd2-gap-10 pd2-mt-10 pd2-align-center">
                                <label class="pd2-settings-label pd2-m-0 pd2-w-100">chat_id
                                    <input type="text" class="btn pd2-w-100" id="pd2sett_tg_chat" autocomplete="off">
                                </label>
                                <label class="pd2-settings-label pd2-m-0 pd2-w-100">message_thread_id
                                    <input type="text" class="btn pd2-w-100" id="pd2sett_tg_thread" autocomplete="off" placeholder="пусто = без темы">
                                </label>
                            </div>
                        </details>

                        <details class="pd2-settings-spoiler pd2-mb-10">
                            <summary class="pd2-fw-900 pd2-pointer pd2-p-8 pd2-bg-card pd2-border-radius-10 pd2-border">Постер</summary>
                            <div class="pd2-settings-grid-5 pd2-mt-10">
                                <label class="pd2-settings-label pd2-m-0"><span>user_id</span>
                                    <input type="number" class="btn pd2-w-100" id="pd2sett_svc_user" min="1" step="1">
                                </label>
                                <label class="pd2-settings-label pd2-m-0"><span>Andrey (ID)</span>
                                    <input type="number" class="btn pd2-w-100" id="pd2sett_acc_andrey" min="1" step="1">
                                </label>
                                <label class="pd2-settings-label pd2-m-0"><span>Tips (ID)</span>
                                    <input type="number" class="btn pd2-w-100" id="pd2sett_acc_tips" min="1" step="1">
                                </label>
                                <label class="pd2-settings-label pd2-m-0"><span>Vietnam (ID)</span>
                                    <input type="number" class="btn pd2-w-100" id="pd2sett_acc_vietnam" min="1" step="1">
                                </label>
                                <label class="pd2-settings-label pd2-m-0"><span>Чай (ID)</span>
                                    <input type="number" class="btn pd2-w-100" id="pd2sett_balance_sinc" min="1" step="1">
                                </label>
                            </div>
                        </details>

                        <details class="pd2-settings-spoiler pd2-mb-10">
                            <summary class="pd2-fw-900 pd2-pointer pd2-p-8 pd2-bg-card pd2-border-radius-10 pd2-border">Poster Admin (Edit check)</summary>
                            <div class="pd2-settings-grid-4 pd2-mt-10">
                                <label class="pd2-settings-label pd2-m-0"><span>account_url</span>
                                    <input type="text" class="btn pd2-w-100" id="pd2sett_padm_account" autocomplete="off" placeholder="restpublica2">
                                </label>
                                <label class="pd2-settings-label pd2-m-0"><span>ssid</span>
                                    <input type="text" class="btn pd2-w-100" id="pd2sett_padm_ssid" autocomplete="off">
                                </label>
                                <label class="pd2-settings-label pd2-m-0"><span>csrf_cookie_poster</span>
                                    <input type="text" class="btn pd2-w-100" id="pd2sett_padm_csrf" autocomplete="off">
                                </label>
                                <label class="pd2-settings-label pd2-m-0"><span>pos_session</span>
                                    <input type="text" class="btn pd2-w-100" id="pd2sett_padm_pos_session" autocomplete="off">
                                </label>
                                <label class="pd2-settings-label pd2-m-0"><span>user_agent</span>
                                    <input type="text" class="btn pd2-w-100" id="pd2sett_padm_ua" autocomplete="off" placeholder="опционально">
                                </label>
                            </div>
                        </details>

                        <details class="pd2-settings-spoiler pd2-mb-10" id="pd2sett_categories_spoiler">
                            <summary class="pd2-fw-900 pd2-pointer pd2-p-8 pd2-bg-card pd2-border-radius-10 pd2-border">Категории</summary>
                            <div id="pd2sett_categories_list" class="pd2-mt-10 pd2-d-flex pd2-flex-column pd2-gap-6" style="max-height: 200px; overflow-y: auto;">
                                <div class="pd2-text-center muted">Загрузка категорий...</div>
                            </div>
                        </details>

                    </div>
                    <div id="payday2SettingsErr" class="error pd2-settings-err pd2-d-none"></div>
                    <div class="actions pd2-justify-between pd2-align-center pd2-mt-6">
                        <p class="pd2-settings-warn pd2-m-0">НЕ МЕНЯЙ, ЕСЛИ НЕ ЗНАЕШЬ ЧТО ЭТО</p>
                        <button type="button" class="btn2 primary" id="payday2SettingsSave">Сохранить</button>
                    </div>
                </div>
            </div>
            <div class="confirm-backdrop" id="payday2InfoModal">
                <div class="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="payday2InfoModalTitle">
                    <h3 id="payday2InfoModalTitle" class="pd2-m-0">Payday2</h3>
                    <div class="body pd2-text-center pd2-lh-135">
                        Это обновленная и оптимизированная версия payday.
                        <br><br>
                        Если что-то не работает, надо сообщить Диме.
                    </div>
                    <div class="actions pd2-justify-center">
                        <a href="/payday/" class="btn2 primary pd2-inline-flex pd2-text-dec-none pd2-min-w-140 pd2-justify-center">Payday</a>
                        <button type="button" class="btn2 pd2-min-w-140" id="payday2InfoModalClose">Закрыть</button>
                    </div>
                </div>
            </div>
            
<script type="application/json" id="payday2-config-json"><?= json_encode($payday2ClientConfig, $payday2ConfigJsonFlags) ?></script>
<script src="/payday2/assets/js/payday2_telegram.js?v=<?= htmlspecialchars($payday2AssetVersion) ?>"></script>
<script src="/payday2/assets/js/payday2_create_tx.js?v=<?= htmlspecialchars($payday2AssetVersion) ?>"></script>
<script src="/payday2/assets/js/payday2_help_tour.js?v=<?= htmlspecialchars($payday2AssetVersion) ?>"></script>
<script src="/payday2/assets/js/paytypeedit.js?v=<?= htmlspecialchars($payday2AssetVersion) ?>"></script>
<script src="/payday2/assets/js/payday2.js?v=<?= htmlspecialchars($payday2AssetVersion) ?>"></script>
</body>
</html>

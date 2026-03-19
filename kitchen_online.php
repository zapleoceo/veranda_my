<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/src/classes/Database.php';
require_once __DIR__ . '/src/classes/PosterAPI.php';
date_default_timezone_set('Asia/Ho_Chi_Minh');

if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $t = trim($line);
        if ($t === '' || $t[0] === '#' || strpos($t, '=') === false) continue;
        [$name, $value] = explode('=', $line, 2);
        $_ENV[$name] = trim($value);
    }
}

$dbHost = $_ENV['DB_HOST'] ?? 'localhost';
$dbName = $_ENV['DB_NAME'] ?? 'veranda_my';
$dbUser = $_ENV['DB_USER'] ?? 'veranda_my';
$dbPass = $_ENV['DB_PASS'] ?? '';
$token = $_ENV['POSTER_API_TOKEN'] ?? '';

$stationFilter = $_GET['station'] ?? 'all'; // all|kitchen|bar
$isAjax = (($_GET['ajax'] ?? '') === '1');
$action = $_GET['action'] ?? 'list'; // list|sync

$today = date('Y-m-d');
$lastSyncLabel = '—';

try {
    $db = new \App\Classes\Database($dbHost, $dbName, $dbUser, $dbPass);
    $api = new \App\Classes\PosterAPI($token);
    $meta = $db->query("SELECT meta_value FROM system_meta WHERE meta_key = 'poster_last_sync_at' LIMIT 1")->fetch();
    if (!empty($meta['meta_value'])) {
        $lastSyncLabel = date('d.m.Y H:i:s', strtotime($meta['meta_value']));
    } else {
        $fallback = $db->query("SELECT MAX(created_at) AS last_sync_at FROM kitchen_stats")->fetch();
        if (!empty($fallback['last_sync_at'])) {
            $lastSyncLabel = date('d.m.Y H:i:s', strtotime($fallback['last_sync_at']));
        }
    }
} catch (\Exception $e) {
}

$stationSql = '';
$stationParams = [];
if ($stationFilter === 'kitchen') {
    $stationSql = " AND (station = '2' OR station = 2 OR station = 'Kitchen' OR station = 'Main')";
} elseif ($stationFilter === 'bar') {
    $stationSql = " AND (station = '3' OR station = 3 OR station = 'Bar Veranda')";
}

$renderCards = function (array $rows): string {
    $cards = [];
    foreach ($rows as $r) {
        $txId = (int)($r['transaction_id'] ?? 0);
        if ($txId <= 0) continue;
        if (!isset($cards[$txId])) {
            $cards[$txId] = [
                'transaction_id' => $txId,
                'receipt_number' => (string)($r['receipt_number'] ?? ''),
                'table_number' => (string)($r['table_number'] ?? ''),
                'waiter_name' => (string)($r['waiter_name'] ?? ''),
                'min_sent_ts' => 0,
                'items' => []
            ];
        }
        $sentAt = (string)($r['ticket_sent_at'] ?? '');
        $sentTs = $sentAt !== '' ? strtotime($sentAt) : 0;
        if ($sentTs > 0 && ($cards[$txId]['min_sent_ts'] === 0 || $sentTs < $cards[$txId]['min_sent_ts'])) {
            $cards[$txId]['min_sent_ts'] = $sentTs;
        }
        $cards[$txId]['items'][] = [
            'item_id' => (int)($r['id'] ?? 0),
            'dish_name' => (string)($r['dish_name'] ?? ''),
            'dish_id' => (int)($r['dish_id'] ?? 0),
            'sent_at' => $sentAt,
            'sent_ts' => $sentTs,
            'station' => (string)($r['station'] ?? ''),
        ];
    }

    usort($cards, function ($a, $b) {
        $aTs = (int)($a['min_sent_ts'] ?? 0);
        $bTs = (int)($b['min_sent_ts'] ?? 0);
        if ($aTs === $bTs) {
            return ($a['transaction_id'] ?? 0) <=> ($b['transaction_id'] ?? 0);
        }
        return $aTs <=> $bTs;
    });

    ob_start();
    foreach ($cards as $c) {
        if (empty($c['items'])) continue;
        $receipt = trim((string)$c['receipt_number']);
        $receiptLabel = $receipt !== '' ? $receipt : ('TX #' . (int)$c['transaction_id']);
        $table = trim((string)$c['table_number']);
        $waiter = trim((string)$c['waiter_name']);
        if ($waiter === '') $waiter = '—';
        ?>
        <div class="ko-card">
            <div class="ko-card-header">
                <div class="ko-title">Чек <?= htmlspecialchars($receiptLabel) ?></div>
                <div class="ko-meta">
                    <span>Стол: <?= htmlspecialchars($table !== '' ? $table : '—') ?></span>
                    <span>Официант: <?= htmlspecialchars($waiter) ?></span>
                </div>
            </div>
            <div class="ko-items">
                <?php foreach ($c['items'] as $it): ?>
                    <?php
                        $sentTs = (int)($it['sent_ts'] ?? 0);
                        $sentLabel = ($sentTs > 0) ? date('H:i:s', $sentTs) : '—';
                    ?>
                    <div class="ko-item">
                        <div class="ko-item-name"><?= htmlspecialchars($it['dish_name'] ?: ('Dish #' . (int)$it['dish_id'])) ?></div>
                        <div class="ko-item-row">
                            <span class="ko-item-sent">Пришло: <?= htmlspecialchars($sentLabel) ?></span>
                            <?php if ($sentTs > 0): ?>
                                <span class="ko-item-wait live-wait" data-sent-ts="<?= $sentTs ?>"><span class="wait-spinner" aria-hidden="true"></span><span class="live-time">00:00</span></span>
                            <?php else: ?>
                                <span class="ko-item-wait">—</span>
                            <?php endif; ?>
                            <?php if (!empty($it['item_id'])): ?>
                                <button type="button" class="ko-ack" title="Принято" aria-label="Принято" data-item-id="<?= (int)$it['item_id'] ?>">✕</button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }
    return ob_get_clean();
};

if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $db = $db ?? new \App\Classes\Database($dbHost, $dbName, $dbUser, $dbPass);
        if ($action === 'exclude' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $itemId = (int)($_POST['toggle_exclude_item'] ?? 0);
            if ($itemId > 0) {
                $db->query("UPDATE kitchen_stats SET exclude_from_dashboard = 1, exclude_auto = 0 WHERE id = ?", [$itemId]);
            }
            echo json_encode(['ok' => true, 'item_id' => $itemId], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($action === 'sync') {
            require_once __DIR__ . '/scripts/kitchen/resync_lib.php';
            veranda_resync_range_period($today, $today);
            $meta = $db->query("SELECT meta_value FROM system_meta WHERE meta_key = 'poster_last_sync_at' LIMIT 1")->fetch();
            if (!empty($meta['meta_value'])) {
                $lastSyncLabel = date('d.m.Y H:i:s', strtotime($meta['meta_value']));
            }
        }

        $rows = $db->query(
            "SELECT id, transaction_id, receipt_number, table_number, waiter_name, dish_id, dish_name, station, ticket_sent_at
             FROM kitchen_stats
             WHERE transaction_date = ?
               AND status = 1
               AND ready_pressed_at IS NULL
               AND ready_chass_at IS NULL
               AND ticket_sent_at IS NOT NULL
               AND COALESCE(was_deleted, 0) = 0
               AND COALESCE(exclude_from_dashboard, 0) = 0
               AND NOT (COALESCE(dish_category_id, 0) = 47 OR COALESCE(dish_sub_category_id, 0) = 47)
               {$stationSql}
             ORDER BY ticket_sent_at ASC",
            array_merge([$today], $stationParams)
        )->fetchAll();

        $html = $renderCards($rows);
        echo json_encode(['ok' => true, 'html' => $html, 'last_sync' => $lastSyncLabel], JSON_UNESCAPED_UNICODE);
    } catch (\Exception $e) {
        echo json_encode(['ok' => false, 'error' => 'error'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$dashboardQuery = http_build_query([
    'dateFrom' => $today,
    'dateTo' => $today,
    'hourStart' => 0,
    'hourEnd' => 23
]);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>КухняOnline</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f5f6fa; margin: 0; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; }
        .nav-links { text-align: center; margin-bottom: 20px; }
        .nav-links a { color: #1a73e8; text-decoration: none; margin: 0 10px; font-weight: 500; }
        .nav-links a:hover { text-decoration: underline; }
        h1 { text-align: center; color: #2c3e50; margin-bottom: 10px; }
        .topbar { display: flex; justify-content: center; align-items: center; gap: 16px; flex-wrap: wrap; margin-bottom: 16px; color: #546e7a; }
        .topbar select { padding: 8px 12px; border-radius: 6px; border: 1px solid #d0d5dd; background: #fff; }
        .wait-spinner { display: inline-block; width: 10px; height: 10px; border: 2px solid rgba(245, 124, 0, 0.3); border-top-color: #f57c00; border-radius: 50%; margin-right: 6px; animation: waitSpin 0.9s linear infinite; vertical-align: -1px; }
        @keyframes waitSpin { to { transform: rotate(360deg); } }
        .cards { display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-start; }
        .ko-card { width: 320px; background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.06); overflow: hidden; }
        .ko-card-header { padding: 12px 14px; border-bottom: 1px solid #eee; background: #fafafa; }
        .ko-title { font-weight: 800; color: #2c3e50; }
        .ko-meta { margin-top: 6px; display: flex; flex-direction: column; gap: 4px; font-size: 13px; color: #607d8b; }
        .ko-items { padding: 12px 14px; display: flex; flex-direction: column; gap: 10px; }
        .ko-item { border: 1px solid #eef2f6; border-radius: 10px; padding: 10px; background: #fff; }
        .ko-item-name { font-weight: 700; color: #263238; }
        .ko-item-row { margin-top: 8px; display: flex; justify-content: space-between; gap: 10px; font-size: 13px; color: #546e7a; }
        .ko-item-wait { font-weight: 700; color: #d32f2f; white-space: nowrap; }
        .ko-ack { border: 0; background: transparent; color: #9aa0a6; font-size: 18px; line-height: 1; cursor: pointer; padding: 0 4px; }
        .ko-ack:hover { color: #5f6368; }
        .ko-ack:disabled { opacity: 0.4; cursor: default; }
        .empty { text-align: center; color: #65676b; margin-top: 18px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="nav-links">
            <a href="dashboard.php?<?= htmlspecialchars($dashboardQuery) ?>">Дашборд</a>
            <a href="rawdata.php?<?= htmlspecialchars($dashboardQuery) ?>">Сырые данные</a>
            <a href="admin.php">УПРАВЛЕНИЕ</a>
            <a href="kitchen_online.php">КухняOnline</a>
            <a href="logout.php">Выйти (<?= htmlspecialchars($_SESSION['user_email']) ?>)</a>
        </div>

        <h1>КухняOnline</h1>

        <div class="topbar">
            <span>Последнее обновление из Poster: <span id="lastSync"><?= htmlspecialchars($lastSyncLabel) ?></span></span>
            <span>Следующее обновление: <span id="refreshIn">10</span> сек</span>
            <label>
                Станция:
                <select id="station">
                    <option value="all">Все</option>
                    <option value="kitchen">Кухня</option>
                    <option value="bar">Бар</option>
                </select>
            </label>
        </div>

        <div id="cards" class="cards"></div>
        <div id="empty" class="empty" style="display:none;">Нет активных блюд</div>
    </div>

    <script>
        const cardsEl = document.getElementById('cards');
        const emptyEl = document.getElementById('empty');
        const stationEl = document.getElementById('station');
        const lastSyncEl = document.getElementById('lastSync');
        const refreshInEl = document.getElementById('refreshIn');
        let loading = false;
        const refreshIntervalSec = 10;
        let refreshRemaining = refreshIntervalSec;

        const updateLive = () => {
            const els = Array.from(document.getElementsByClassName('live-wait'));
            const nowSec = Math.floor(Date.now() / 1000);
            for (const el of els) {
                const sentTs = parseInt(el.dataset.sentTs || '0', 10);
                if (!sentTs) continue;
                const diffSec = Math.max(0, nowSec - sentTs);
                const mm = Math.floor(diffSec / 60);
                const ss = diffSec % 60;
                const out = String(mm).padStart(2, '0') + ':' + String(ss).padStart(2, '0');
                const t = el.querySelector('.live-time');
                if (t) t.textContent = out;
            }
        };

        const loadCards = async (action = 'list') => {
            if (loading) return;
            loading = true;
            try {
                const params = new URLSearchParams();
                params.set('ajax', '1');
                params.set('action', action);
                params.set('station', stationEl.value);
                const res = await fetch(`kitchen_online.php?${params.toString()}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                const data = await res.json();
                if (!data || !data.ok) throw new Error('bad');
                cardsEl.innerHTML = data.html || '';
                emptyEl.style.display = (cardsEl.children.length === 0) ? 'block' : 'none';
                if (data.last_sync) lastSyncEl.textContent = data.last_sync;
                updateLive();
            } catch (e) {
            } finally {
                loading = false;
            }
        };

        stationEl.addEventListener('change', () => loadCards('list'));

        cardsEl.addEventListener('click', async (e) => {
            const btn = e.target.closest('button.ko-ack');
            if (!btn) return;
            const itemId = parseInt(btn.dataset.itemId || '0', 10);
            if (!itemId) return;
            if (btn.disabled) return;
            btn.disabled = true;
            try {
                const payload = new FormData();
                payload.set('toggle_exclude_item', String(itemId));
                payload.set('exclude_from_dashboard', '1');
                const params = new URLSearchParams();
                params.set('ajax', '1');
                params.set('action', 'exclude');
                const res = await fetch(`kitchen_online.php?${params.toString()}`, { method: 'POST', body: payload, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                const data = await res.json();
                if (!data || !data.ok) throw new Error('bad');
                const itemEl = btn.closest('.ko-item');
                if (itemEl) itemEl.remove();
                const cardEl = btn.closest('.ko-card');
                if (cardEl && cardEl.querySelectorAll('.ko-item').length === 0) {
                    cardEl.remove();
                }
                emptyEl.style.display = (cardsEl.children.length === 0) ? 'block' : 'none';
            } catch (err) {
                btn.disabled = false;
            }
        });

        loadCards('list');
        setInterval(updateLive, 1000);
        setInterval(() => {
            refreshRemaining = Math.max(0, refreshRemaining - 1);
            if (refreshInEl) refreshInEl.textContent = String(refreshRemaining);
        }, 1000);
        setInterval(() => {
            refreshRemaining = refreshIntervalSec;
            if (refreshInEl) refreshInEl.textContent = String(refreshRemaining);
            loadCards('list');
        }, refreshIntervalSec * 1000);
    </script>
</body>
</html>

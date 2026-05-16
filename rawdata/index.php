<?php

if (empty($GLOBALS['_VERANDA_SLIM_RUNNING'])) {
    require __DIR__ . '/../public/index.php';
    return;
}

require_once __DIR__ . '/../auth_check.php';
veranda_require('rawdata');
date_default_timezone_set('Asia/Ho_Chi_Minh');

if (($_GET['ajax'] ?? '') === '1' || ($_GET['ajax'] ?? '') === 'list') {
    require __DIR__ . '/../api/sql/rawdata/index.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_exclude_item'])) {
    require __DIR__ . '/../api/sql/rawdata/index.php';
    exit;
}

$selectedStatus = $_GET['status'] ?? 'all';
$dateFrom = $_GET['dateFrom'] ?? date('Y-m-d');
$dateTo = $_GET['dateTo'] ?? date('Y-m-d');
$hourStart = (int)($_GET['hourStart'] ?? 0);
$hourEnd = (int)($_GET['hourEnd'] ?? 23);
$stationFilter = (string)($_GET['station'] ?? 'all');
$lastSyncLabel = '—';

$dashboardQuery = http_build_query([
    'dateFrom' => $dateFrom,
    'dateTo' => $dateTo,
    'hourStart' => $hourStart,
    'hourEnd' => $hourEnd,
    'station' => $stationFilter
]);

try {
    $ks = $db->t('kitchen_stats');
    $metaTable = $db->t('system_meta');
    try {
        $meta = $db->query("SELECT meta_value FROM {$metaTable} WHERE meta_key = 'poster_last_sync_at' LIMIT 1")->fetch();
        if (!empty($meta['meta_value'])) {
            $lastSyncLabel = date('d.m.Y H:i:s', strtotime($meta['meta_value']));
        }
    } catch (\Exception $e) {
    }
    if ($lastSyncLabel === '—') {
        $fallback = $db->query("SELECT MAX(created_at) AS last_sync_at FROM {$ks}")->fetch();
        if (!empty($fallback['last_sync_at'])) {
            $lastSyncLabel = date('d.m.Y H:i:s', strtotime($fallback['last_sync_at']));
        }
    }
} catch (\Throwable $e) {
}

$doResync = (($_GET['resync'] ?? '') === '1');
if ($doResync) {
    $metaTable = $db->t('system_meta');
    $pidVal = 0;
    $statusRow = $db->query(
        "SELECT meta_key, meta_value
         FROM {$metaTable}
         WHERE meta_key IN ('kitchen_resync_job_pid','kitchen_resync_job_status')"
    )->fetchAll();
    $meta = [];
    foreach ($statusRow as $r) $meta[(string)$r['meta_key']] = (string)$r['meta_value'];
    $existingPid = (int)($meta['kitchen_resync_job_pid'] ?? 0);
    $existingStatus = (string)($meta['kitchen_resync_job_status'] ?? '');
    $isRunning = false;
    if ($existingPid > 0 && $existingStatus === 'running') {
        if (function_exists('posix_kill')) {
            $isRunning = @posix_kill($existingPid, 0);
        } else {
            $isRunning = is_dir('/proc/' . $existingPid);
        }
    }
    if (!$isRunning) {
        $jobId = date('Ymd_His');
        $cmd = PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/../scripts/kitchen/resync_range.php') . ' ' . escapeshellarg($dateFrom) . ' ' . escapeshellarg($dateTo) . ' ' . escapeshellarg($jobId);
        $logFile = __DIR__ . '/../resync_range.log';
        $out = [];
        @exec($cmd . ' >> ' . escapeshellarg($logFile) . ' 2>&1 & echo $!', $out);
        $pidVal = (int)trim((string)end($out));
        if ($pidVal > 0) {
            $db->query(
                "INSERT INTO {$metaTable} (meta_key, meta_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = CURRENT_TIMESTAMP",
                ['kitchen_resync_job_pid', (string)$pidVal]
            );
        }
    }
    $redirectQuery = $_GET;
    unset($redirectQuery['resync']);
    $redirectQuery['resync_started'] = '1';
    $qs = http_build_query($redirectQuery);
    header('Location: /rawdata/' . ($qs ? ('?' . $qs) : ''));
    exit;
}

require __DIR__ . '/view.php';


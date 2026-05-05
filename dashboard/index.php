<?php
require_once __DIR__ . '/../auth_check.php';
veranda_require('dashboard');

$dateFrom = $_GET['dateFrom'] ?? date('Y-m-d');
$dateTo = $_GET['dateTo'] ?? date('Y-m-d');
$hourStart = (int)($_GET['hourStart'] ?? 0);
$hourEnd = (int)($_GET['hourEnd'] ?? 23);
$doResync = isset($_GET['resync']) && $_GET['resync'] === '1';
$lastSyncLabel = '—';
if ($hourStart < 0) $hourStart = 0;
if ($hourStart > 23) $hourStart = 23;
if ($hourEnd === 24) $hourEnd = 23;
if ($hourEnd < 0) $hourEnd = 0;
if ($hourEnd > 23) $hourEnd = 23;
if ($hourEnd < $hourStart) $hourEnd = $hourStart;
$rawParams = [
    'dateFrom' => $dateFrom,
    'dateTo' => $dateTo,
    'hourStart' => $hourStart,
    'hourEnd' => $hourEnd
];

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
    $redirectParams = $rawParams;
    $redirectParams['resync_started'] = '1';
    $qs = http_build_query($redirectParams);
    header('Location: /dashboard/' . ($qs ? ('?' . $qs) : ''));
    exit;
}

$rawDataQuery = http_build_query([
    'dateFrom' => $dateFrom,
    'dateTo' => $dateTo,
    'hourStart' => $hourStart,
    'hourEnd' => $hourEnd
]);
$dashboardQuery = http_build_query($rawParams);

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

    $hours = [];
    $slotDates = [];
    $slotHours = [];
    $dateRangeSingleDay = $dateFrom === $dateTo;

    if ($dateRangeSingleDay) {
        for ($h = $hourStart; $h <= $hourEnd; $h++) {
            $hours[] = sprintf("%02d:00", $h);
            $slotDates[] = $dateFrom;
            $slotHours[] = $h;
        }
    } else {
        $dt = new DateTime($dateFrom);
        $dtEnd = new DateTime($dateTo);
        $dtEnd->setTime(0, 0, 0);
        $dt->setTime(0, 0, 0);

        while ($dt <= $dtEnd) {
            $dIso = $dt->format('Y-m-d');
            $dLabel = $dt->format('d.m');
            for ($h = $hourStart; $h <= $hourEnd; $h++) {
                $hours[] = $dLabel . ' ' . sprintf("%02d:00", $h);
                $slotDates[] = $dIso;
                $slotHours[] = $h;
            }
            $dt->modify('+1 day');
        }
    }

    $slotCount = count($hours);
    $chartData = [
        '2' => ['label' => 'KITCHEN', 'avg' => array_fill(0, $slotCount, 0), 'max' => array_fill(0, $slotCount, 0), 'counts' => array_fill(0, $slotCount, 0)],
        '3' => ['label' => 'BAR VERANDA', 'avg' => array_fill(0, $slotCount, 0), 'max' => array_fill(0, $slotCount, 0), 'counts' => array_fill(0, $slotCount, 0)]
    ];

    $slotIndex = [];
    for ($i = 0; $i < $slotCount; $i++) {
        $d = $slotDates[$i] ?? null;
        $h = $slotHours[$i] ?? null;
        if ($d === null || $h === null) continue;
        if (!isset($slotIndex[$d])) $slotIndex[$d] = [];
        $slotIndex[$d][(int)$h] = $i;
    }

    $rows = $db->query(
        "SELECT sid, d_iso, h_int,
                ROUND(AVG(wait_min), 1) AS avg_wait,
                ROUND(MAX(wait_min), 1) AS max_wait,
                COUNT(*) AS cnt
         FROM (
              SELECT
                CASE
                  WHEN station = '2' OR station = 2 OR station = 'Kitchen' OR station = 'Main' THEN '2'
                  WHEN station = '3' OR station = 3 OR station = 'Bar Veranda' THEN '3'
                  ELSE NULL
                END AS sid,
                DATE(transaction_opened_at) AS d_iso,
                HOUR(transaction_opened_at) AS h_int,
                (TIMESTAMPDIFF(SECOND, ticket_sent_at,
                    CASE
                      WHEN ready_pressed_at IS NOT NULL THEN ready_pressed_at
                      WHEN prob_close_at IS NOT NULL
                       AND status > 1
                       AND transaction_closed_at IS NOT NULL
                       AND transaction_closed_at <> '0000-00-00 00:00:00'
                        THEN CASE WHEN prob_close_at < transaction_closed_at THEN prob_close_at ELSE transaction_closed_at END
                      WHEN prob_close_at IS NOT NULL THEN prob_close_at
                      WHEN status > 1 AND transaction_closed_at IS NOT NULL AND transaction_closed_at <> '0000-00-00 00:00:00' THEN transaction_closed_at
                      ELSE NULL
                    END
                ) / 60) AS wait_min
              FROM {$ks}
              WHERE transaction_date BETWEEN ? AND ?
                AND COALESCE(exclude_from_dashboard, 0) = 0
                AND COALESCE(was_deleted, 0) = 0
                AND ticket_sent_at IS NOT NULL
                AND transaction_opened_at IS NOT NULL
                AND HOUR(transaction_opened_at) BETWEEN ? AND ?
                AND NOT (COALESCE(dish_category_id, 0) = 47 OR COALESCE(dish_sub_category_id, 0) = 47)
                AND (
                    ready_pressed_at IS NOT NULL
                 OR prob_close_at IS NOT NULL
                 OR (status > 1 AND transaction_closed_at IS NOT NULL AND transaction_closed_at <> '0000-00-00 00:00:00')
                )
         ) x
         WHERE sid IS NOT NULL AND wait_min IS NOT NULL AND wait_min >= 0
         GROUP BY sid, d_iso, h_int",
        [$dateFrom, $dateTo, $hourStart, $hourEnd]
    )->fetchAll();

    if (empty($rows)) {
        $error = "Нет данных для построения дашборда за выбранный период.";
    } else {
        foreach ($rows as $r) {
            $sid = (string)($r['sid'] ?? '');
            $dIso = (string)($r['d_iso'] ?? '');
            $hInt = (int)($r['h_int'] ?? -1);
            if ($sid === '' || $dIso === '' || $hInt < 0) continue;
            if (!isset($slotIndex[$dIso][$hInt])) continue;
            $idx = (int)$slotIndex[$dIso][$hInt];
            if (!isset($chartData[$sid])) continue;
            $chartData[$sid]['avg'][$idx] = (float)($r['avg_wait'] ?? 0);
            $chartData[$sid]['max'][$idx] = (float)($r['max_wait'] ?? 0);
            $chartData[$sid]['counts'][$idx] = (int)($r['cnt'] ?? 0);
        }
    }

} catch (\Exception $e) {
    $error = $e->getMessage();
}

require __DIR__ . '/view.php';


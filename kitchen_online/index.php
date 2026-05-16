<?php

if (empty($GLOBALS['_VERANDA_SLIM_RUNNING'])) {
    require __DIR__ . '/../public/index.php';
    return;
}

require_once __DIR__ . '/../auth_check.php';
veranda_require('kitchen_online');
date_default_timezone_set('Asia/Ho_Chi_Minh');

if (($_GET['ajax'] ?? '') === '1') {
    require __DIR__ . '/../api/sql/kitchen_online/index.php';
    exit;
}

$today = date('Y-m-d');
$lastSyncLabel = '—';
$useLogicalClose = true;

try {
    $ks = $db->t('kitchen_stats');
    $metaTable = $db->t('system_meta');
    try {
        $m = $db->query("SELECT meta_value FROM {$metaTable} WHERE meta_key='ko_use_logical_close' LIMIT 1")->fetch();
        $useLogicalClose = !isset($m['meta_value']) || (string)$m['meta_value'] !== '0';
    } catch (\Throwable $e) {
    }
    try {
        $meta = $db->query("SELECT meta_value FROM {$metaTable} WHERE meta_key = 'poster_last_sync_at' LIMIT 1")->fetch();
        if (!empty($meta['meta_value'])) {
            $lastSyncLabel = date('d.m.Y H:i:s', strtotime($meta['meta_value']));
        } else {
            $fallback = $db->query("SELECT MAX(created_at) AS last_sync_at FROM {$ks}")->fetch();
            if (!empty($fallback['last_sync_at'])) {
                $lastSyncLabel = date('d.m.Y H:i:s', strtotime($fallback['last_sync_at']));
            }
        }
    } catch (\Throwable $e) {
    }
} catch (\Throwable $e) {
}

$dashboardQuery = http_build_query([
    'dateFrom' => $today,
    'dateTo' => $today,
    'hourStart' => 0,
    'hourEnd' => 23
]);

// HTML rendering removed: KitchenOnlineController renders
// src/Views/kitchen_online_content.php inside src/Views/layout.php.


<?php
$usersTable = $db->t('users');
$metaTable = $db->t('system_meta');
$resHallId = max(1, (int)($_GET['hall_id'] ?? 2));
$resSpotId = max(1, (int)($_GET['spot_id'] ?? 1));
$resMetaKey = 'reservations_allowed_scheme_nums_hall_' . $resHallId;
$resCapsMetaKey = 'reservations_table_caps_hall_' . $resHallId;
$resSoonKey = 'reservations_soon_booking_hours';
$resWorkdayKey = 'reservations_latest_workday';
$resWeekendKey = 'reservations_latest_weekend';
$resAllowedNums = [];
$resCapsByNum = [];
$resTables = [];

if ($tab === 'reservations') {
    $metaRepo = new \App\Classes\MetaRepository($db);
    $resSoonHours = 2;
    $resLatestWorkday = '21:00';
    $resLatestWeekend = '22:00';
    
    if (isset($_POST['save_reservation_soon_hours'])) {
        $h = (int)($_POST['soon_hours'] ?? 2);
        if ($h < 0) $h = 0;
        if ($h > 24) $h = 24;
        
        $wDay = trim((string)($_POST['latest_workday'] ?? '21:00'));
        $wEnd = trim((string)($_POST['latest_weekend'] ?? '22:00'));
        if (!preg_match('/^\d{1,2}:\d{2}$/', $wDay)) $wDay = '21:00';
        if (!preg_match('/^\d{1,2}:\d{2}$/', $wEnd)) $wEnd = '22:00';

        $db->query(
            "INSERT INTO {$metaTable} (meta_key, meta_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = CURRENT_TIMESTAMP",
            [$resSoonKey, (string)$h]
        );
        $db->query(
            "INSERT INTO {$metaTable} (meta_key, meta_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = CURRENT_TIMESTAMP",
            [$resWorkdayKey, $wDay]
        );
        $db->query(
            "INSERT INTO {$metaTable} (meta_key, meta_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = CURRENT_TIMESTAMP",
            [$resWeekendKey, $wEnd]
        );
        $message = 'Настройки времени и запаса часов сохранены.';
        $resSoonHours = $h;
        $resLatestWorkday = $wDay;
        $resLatestWeekend = $wEnd;
    }

    $saved = $metaRepo->getMany([$resMetaKey, $resCapsMetaKey, $resSoonKey, $resWorkdayKey, $resWeekendKey]);
    $stored = array_key_exists($resMetaKey, $saved) ? trim((string)$saved[$resMetaKey]) : '';
    if ($stored !== '') {
        $decoded = json_decode($stored, true);
        if (is_array($decoded)) {
            foreach ($decoded as $v) {
                $n = (int)$v;
                if ($n >= 1 && $n <= 500) $resAllowedNums[(string)$n] = true;
            }
        } else {
            foreach (explode(',', $stored) as $part) {
                $part = trim($part);
                if ($part === '' || !preg_match('/^\d+$/', $part)) continue;
                $n = (int)$part;
                if ($n >= 1 && $n <= 500) $resAllowedNums[(string)$n] = true;
            }
        }
    }

    $capsStored = array_key_exists($resCapsMetaKey, $saved) ? trim((string)$saved[$resCapsMetaKey]) : '';
    $capsDecoded = $capsStored !== '' ? json_decode($capsStored, true) : null;
    if (is_array($capsDecoded)) {
        foreach ($capsDecoded as $k => $v) {
            $k = trim((string)$k);
            if (!preg_match('/^\d+$/', $k)) continue;
            $n = (int)$k;
            if ($n < 1 || $n > 500) continue;
            $c = (int)$v;
            if ($c < 0) $c = 0;
            if ($c > 999) $c = 999;
            $resCapsByNum[(string)$n] = $c;
        }
    } else {
        $resCapsByNum = $defaultCaps;
    }

    $soonStored = array_key_exists($resSoonKey, $saved) ? trim((string)$saved[$resSoonKey]) : '';
    if ($soonStored !== '' && is_numeric($soonStored)) {
        $resSoonHours = max(0, min(24, (int)$soonStored));
    }
    
    if (!isset($_POST['save_reservation_soon_hours'])) {
        $resLatestWorkday = array_key_exists($resWorkdayKey, $saved) ? trim((string)$saved[$resWorkdayKey]) : '21:00';
        $resLatestWeekend = array_key_exists($resWeekendKey, $saved) ? trim((string)$saved[$resWeekendKey]) : '22:00';
        if ($resLatestWorkday === '') $resLatestWorkday = '21:00';
        if ($resLatestWeekend === '') $resLatestWeekend = '22:00';
    }

}


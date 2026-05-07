<?php
declare(strict_types=1);

function reservations_sync_legacy_meta(\App\Classes\Database $db, int $spotId, int $hallId): void {
    if ($spotId <= 0 || $hallId <= 0) return;
    require_once dirname(__DIR__) . '/src/classes/MetaRepository.php';
    $meta = new \App\Classes\MetaRepository($db);

    $tbl = $db->t('reservation_table_settings');
    $rows = $db->query(
        "SELECT scheme_num, capacity, bookable
         FROM {$tbl}
         WHERE spot_id = ? AND hall_id = ?",
        [$spotId, $hallId]
    )->fetchAll();
    $rows = is_array($rows) ? $rows : [];

    $allowed = [];
    $caps = [];
    foreach ($rows as $r) {
        $scheme = $r['scheme_num'] ?? null;
        if ($scheme === null || $scheme === '') continue;
        $n = (int)$scheme;
        if ($n <= 0) continue;
        $caps[(string)$n] = max(0, (int)($r['capacity'] ?? 0));
        $isBookable = (int)($r['bookable'] ?? 0) === 1;
        if ($isBookable) $allowed[(string)$n] = true;
    }

    $allowedList = array_values(array_map('intval', array_keys($allowed)));
    sort($allowedList);
    ksort($caps, SORT_NATURAL);

    $meta->set('reservations_allowed_scheme_nums_hall_' . $hallId, json_encode($allowedList, JSON_UNESCAPED_UNICODE));
    $meta->set('reservations_table_caps_hall_' . $hallId, json_encode($caps, JSON_UNESCAPED_UNICODE));
}


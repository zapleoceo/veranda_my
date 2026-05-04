<?php

function admin_strip_number_prefix(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    $withoutNumber = preg_replace('/^\s*\d+(?:\.\d+)*\s*/u', '', $value);
    $out = trim($withoutNumber ?? $value);
    $withoutDot = preg_replace('/^\s*\.\s*/u', '', $out);
    return trim($withoutDot ?? $out);
}

function admin_menu_normalize_sort_key(string $sort): string
{
    $sort = strtolower(trim($sort));
    if ($sort === 'station') {
        $sort = 'poster_station';
    }
    if ($sort === 'poster_category') {
        $sort = 'poster_station';
    }
    if ($sort === 'poster_subcategory') {
        $sort = 'poster_category';
    }
    if (preg_match('/^adapted_category_(ru|en|vn|ko)$/', $sort, $m)) {
        $sort = 'adapted_workshop_' . $m[1];
    }
    if (preg_match('/^adapted_subcategory_(ru|en|vn|ko)$/', $sort, $m)) {
        $sort = 'adapted_category_' . $m[1];
    }

    return $sort;
}

function admin_menu_normalize_cols_param(string $colsParam): string
{
    $colsParam = trim($colsParam);
    if ($colsParam === '') {
        return '';
    }

    $parts = array_filter(array_map('trim', explode(',', $colsParam)), static fn($v) => $v !== '');
    $mapped = [];
    foreach ($parts as $col) {
        if ($col === 'poster_category') {
            $col = 'poster_station';
        }
        if ($col === 'poster_subcategory') {
            $col = 'poster_category';
        }
        if (preg_match('/^adapted_category_(ru|en|vn|ko)$/', $col, $m)) {
            $col = 'adapted_workshop_' . $m[1];
        }
        if (preg_match('/^adapted_subcategory_(ru|en|vn|ko)$/', $col, $m)) {
            $col = 'adapted_category_' . $m[1];
        }
        $mapped[$col] = true;
    }

    return implode(',', array_keys($mapped));
}

function admin_logs_cron_human(string $expr): string
{
    $expr = trim($expr);
    if ($expr === '*/5 * * * *') return 'каждые 5 минут';
    if ($expr === '*/1 * * * *') return 'каждую минуту';
    if ($expr === '0 * * * *') return 'каждый час (в :00)';
    if ($expr === '5 * * * *') return 'каждый час (в :05)';
    return 'по расписанию cron';
}

function admin_logs_sync_status(array $fileInfo, ?int $nowTs = null): array
{
    $nowTs = $nowTs ?? time();

    if (empty($fileInfo['exists']) || empty($fileInfo['mtime'])) {
        return ['kind' => 'bad', 'label' => 'ПРОБЛЕМА'];
    }
    $age = $nowTs - (int)$fileInfo['mtime'];
    if ($age > 7200) {
        return ['kind' => 'bad', 'label' => 'ПРОБЛЕМА'];
    }
    return ['kind' => 'ok', 'label' => 'есть'];
}


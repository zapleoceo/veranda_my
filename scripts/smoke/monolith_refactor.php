<?php

$baseUrl = rtrim((string)(getenv('BASE_URL') ?: 'https://veranda.my'), '/');
$cookie = (string)(getenv('SMOKE_COOKIE') ?: '');
$timeout = (int)(getenv('SMOKE_TIMEOUT') ?: 25);

if ($cookie === '') {
    fwrite(STDERR, "SMOKE_COOKIE is required\n");
    exit(2);
}

$requests = [
    [
        'name' => 'zapara day',
        'old' => '/zapara.php?ajax=day&date=2026-05-01',
        'new' => '/api/poster/zapara/index.php?ajax=day&date=2026-05-01',
        'type' => 'json',
        'keys' => ['ok', 'date', 'hours', 'counts_by_hour_checks'],
    ],
    [
        'name' => 'rawdata list',
        'old' => '/rawdata.php?ajax=1&dateFrom=2026-05-01&dateTo=2026-05-01&hourStart=0&hourEnd=23&station=all&status=all&offset=0&limit=5',
        'new' => '/api/sql/rawdata/index.php?ajax=list&dateFrom=2026-05-01&dateTo=2026-05-01&hourStart=0&hourEnd=23&station=all&status=all&offset=0&limit=5',
        'type' => 'json',
        'keys' => ['ok', 'html', 'next_offset', 'total_receipts', 'has_more'],
    ],
    [
        'name' => 'kitchen online list',
        'old' => '/kitchen_online.php?ajax=1&action=list&station=all',
        'new' => '/api/sql/kitchen_online/index.php?ajax=1&action=list&station=all',
        'type' => 'json',
        'keys' => ['ok', 'html', 'last_sync', 'wait_limit_minutes'],
    ],
    [
        'name' => 'dashboard redirect',
        'old' => '/dashboard.php',
        'new' => '/dashboard/',
        'type' => 'redirect',
    ],
];

function http_get(string $url, string $cookie, int $timeout): array {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'Cookie: ' . $cookie,
    ]);
    curl_setopt($ch, CURLOPT_HEADER, true);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    if ($err) return ['ok' => false, 'http' => $http, 'error' => $err];
    if (!is_string($raw)) return ['ok' => false, 'http' => $http, 'error' => 'empty response'];
    $headers = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);
    return ['ok' => true, 'http' => $http, 'headers' => $headers, 'body' => $body];
}

function has_keys(array $obj, array $keys): bool {
    foreach ($keys as $k) {
        if (!array_key_exists($k, $obj)) return false;
    }
    return true;
}

$failed = 0;
foreach ($requests as $r) {
    $name = $r['name'];
    $oldUrl = $baseUrl . $r['old'];
    $newUrl = $baseUrl . $r['new'];
    echo "== {$name}\n";

    $old = http_get($oldUrl, $cookie, $timeout);
    $new = http_get($newUrl, $cookie, $timeout);

    if (!$old['ok'] || !$new['ok']) {
        $failed++;
        echo "FAIL request: old_ok=" . ($old['ok'] ? '1' : '0') . " new_ok=" . ($new['ok'] ? '1' : '0') . "\n";
        continue;
    }

    if (($r['type'] ?? '') === 'redirect') {
        $ok = ($old['http'] >= 300 && $old['http'] < 400);
        echo "old_http={$old['http']} new_http={$new['http']}\n";
        if (!$ok) $failed++;
        continue;
    }

    $ok = true;
    $oldJson = json_decode((string)$old['body'], true);
    $newJson = json_decode((string)$new['body'], true);
    if (!is_array($oldJson) || !is_array($newJson)) {
        $ok = false;
    } else {
        $keys = (array)($r['keys'] ?? []);
        if ($keys && (!has_keys($oldJson, $keys) || !has_keys($newJson, $keys))) {
            $ok = false;
        }
        if (($oldJson['ok'] ?? null) !== true || ($newJson['ok'] ?? null) !== true) {
            $ok = false;
        }
    }
    echo "old_http={$old['http']} new_http={$new['http']} ok=" . ($ok ? '1' : '0') . "\n";
    if (!$ok) $failed++;
}

echo "\nRESULT: " . ($failed === 0 ? "OK\n" : ("FAILED={$failed}\n"));
exit($failed === 0 ? 0 : 1);


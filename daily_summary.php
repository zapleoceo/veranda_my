<?php

date_default_timezone_set('Asia/Ho_Chi_Minh');

require_once __DIR__ . '/src/classes/Database.php';

$loadEnv = function (string $path): void {
    if (!is_file($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) return;
    foreach ($lines as $line) {
        $t = trim($line);
        if ($t === '' || $t[0] === '#' || strpos($t, '=') === false) continue;
        [$name, $value] = explode('=', $line, 2);
        $_ENV[trim($name)] = trim($value);
    }
};

$loadEnv(__DIR__ . '/.env');

$tgToken = trim((string)($_ENV['TELEGRAM_BOT_TOKEN'] ?? $_ENV['TG_BOT_TOKEN'] ?? ''));
$tgUserId = '169510539';

$readLastTimestampFromLog = function (string $filePath): string {
    if (!is_file($filePath)) return '—';
    $fh = @fopen($filePath, 'rb');
    if (!$fh) return '—';
    $chunk = '';
    $pos = -1;
    $lines = [];
    while (count($lines) < 50) {
        if (@fseek($fh, $pos, SEEK_END) !== 0) {
            @rewind($fh);
            $chunk = fread($fh, 65536) . $chunk;
            break;
        }
        $c = fgetc($fh);
        if ($c === false) break;
        $chunk = $c . $chunk;
        if ($c === "\n") {
            $tmp = explode("\n", $chunk);
            $chunk = array_shift($tmp);
            foreach (array_reverse($tmp) as $l) {
                $l = trim($l);
                if ($l === '') continue;
                $lines[] = $l;
                if (count($lines) >= 50) break;
            }
        }
        $pos--;
        if ($pos < -1048576) break;
    }
    fclose($fh);
    foreach ($lines as $l) {
        if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $l, $m)) {
            return $m[1];
        }
    }
    return '—';
};

$countLogMatchesForDate = function (string $filePath, string $dateYmd, string $needle): int {
    if (!is_file($filePath)) return 0;
    $count = 0;
    $fh = @fopen($filePath, 'rb');
    if (!$fh) return 0;
    while (!feof($fh)) {
        $line = fgets($fh);
        if ($line === false) break;
        if (strpos($line, '[' . $dateYmd . ' ') !== 0) continue;
        if (strpos($line, $needle) !== false) $count++;
    }
    fclose($fh);
    return $count;
};

$collectErrorsForDate = function (string $filePath, string $dateYmd): array {
    if (!is_file($filePath)) return [];
    $out = [];
    $fh = @fopen($filePath, 'rb');
    if (!$fh) return [];
    while (!feof($fh)) {
        $line = fgets($fh);
        if ($line === false) break;
        if (strpos($line, '[' . $dateYmd . ' ') !== 0) continue;
        if (stripos($line, 'ERROR') !== false) $out[] = rtrim($line, "\r\n");
    }
    fclose($fh);
    return $out;
};

$sendTelegram = function (string $token, string $chatId, string $text): bool {
    if ($token === '' || $chatId === '' || $text === '') return false;
    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $params = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => '1',
    ];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $resp = curl_exec($ch);
    curl_close($ch);
    if ($resp === false) return false;
    $j = json_decode($resp, true);
    return is_array($j) && !empty($j['ok']);
};

$sendTelegramFile = function (string $token, string $chatId, string $filePath, string $caption): bool {
    if ($token === '' || $chatId === '' || !is_file($filePath)) return false;
    $url = "https://api.telegram.org/bot{$token}/sendDocument";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $post = [
        'chat_id' => $chatId,
        'caption' => $caption,
        'document' => curl_file_create($filePath),
    ];
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    $resp = curl_exec($ch);
    curl_close($ch);
    if ($resp === false) return false;
    $j = json_decode($resp, true);
    return is_array($j) && !empty($j['ok']);
};

$root = __DIR__;
$cronLog = $root . '/cron.log';
$menuLog = $root . '/menu_sync.log';
$tgLog = $root . '/telegram.log';

$yesterday = (new DateTimeImmutable('now', new DateTimeZone('Asia/Ho_Chi_Minh')))->modify('-1 day')->format('Y-m-d');
$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Ho_Chi_Minh')))->format('Y-m-d');

$dbHost = $_ENV['DB_HOST'] ?? 'localhost';
$dbName = $_ENV['DB_NAME'] ?? 'veranda_my';
$dbUser = $_ENV['DB_USER'] ?? 'veranda_my';
$dbPass = $_ENV['DB_PASS'] ?? '';
$tableSuffix = (string)($_ENV['DB_TABLE_SUFFIX'] ?? '');

$meta = [];
$metaErr = [];
try {
    $db = new \App\Classes\Database($dbHost, $dbName, $dbUser, $dbPass, $tableSuffix);
    $metaTable = $db->t('system_meta');
    $keys = [
        'menu_last_sync_at',
        'menu_last_sync_error',
        'telegram_last_run_at',
        'telegram_last_run_error',
        'kitchen_last_sync_at',
        'kitchen_last_sync_error',
    ];
    $in = implode(',', array_fill(0, count($keys), '?'));
    $rows = $db->query("SELECT meta_key, meta_value FROM {$metaTable} WHERE meta_key IN ({$in})", $keys)->fetchAll();
    foreach ($rows as $r) {
        $k = (string)($r['meta_key'] ?? '');
        $v = (string)($r['meta_value'] ?? '');
        if ($k !== '') $meta[$k] = $v;
    }
} catch (Throwable $e) {
    $metaErr[] = $e->getMessage();
}

$menuLast = trim((string)($meta['menu_last_sync_at'] ?? '')) ?: $readLastTimestampFromLog($menuLog);
$tgLast = trim((string)($meta['telegram_last_run_at'] ?? '')) ?: $readLastTimestampFromLog($tgLog);
$kitchenLast = trim((string)($meta['kitchen_last_sync_at'] ?? '')) ?: $readLastTimestampFromLog($cronLog);

$menuCount = $countLogMatchesForDate($menuLog, $yesterday, 'Starting menu sync');
$tgCount = $countLogMatchesForDate($tgLog, $yesterday, 'DONE duration_ms');
$kitchenCount = $countLogMatchesForDate($cronLog, $yesterday, 'Updated sync marker');

$menuErr = trim((string)($meta['menu_last_sync_error'] ?? ''));
$tgErr = trim((string)($meta['telegram_last_run_error'] ?? ''));
$kitchenErr = trim((string)($meta['kitchen_last_sync_error'] ?? ''));

$errLines = [];
foreach ($collectErrorsForDate($menuLog, $yesterday) as $l) $errLines[] = 'MENU: ' . $l;
foreach ($collectErrorsForDate($tgLog, $yesterday) as $l) $errLines[] = 'TG: ' . $l;
foreach ($collectErrorsForDate($cronLog, $yesterday) as $l) $errLines[] = 'KITCHEN: ' . $l;
foreach ([$menuErr ? ('MENU_LAST_ERROR: ' . $menuErr) : '', $tgErr ? ('TG_LAST_ERROR: ' . $tgErr) : '', $kitchenErr ? ('KITCHEN_LAST_ERROR: ' . $kitchenErr) : ''] as $l) {
    if ($l !== '') $errLines[] = $l;
}
foreach ($metaErr as $l) $errLines[] = 'META: ' . $l;

$waStatus = 'unknown';
try {
    $out = [];
    $code = 0;
    @exec('/usr/bin/pm2 jlist 2>/dev/null', $out, $code);
    if ($code === 0 && !empty($out)) {
        $j = json_decode(implode("\n", $out), true);
        if (is_array($j)) {
            $byName = [];
            foreach ($j as $p) {
                $name = (string)($p['name'] ?? '');
                if ($name === '') continue;
                $env = $p['pm2_env'] ?? [];
                $mon = $p['monit'] ?? [];
                $byName[$name] = [
                    'status' => (string)($env['status'] ?? ''),
                    'restarts' => (int)($env['restart_time'] ?? 0),
                    'uptime_ms' => (int)((int)($env['pm_uptime'] ?? 0) > 0 ? (round(microtime(true) * 1000) - (int)$env['pm_uptime']) : 0),
                    'mem_mb' => (int)round(((int)($mon['memory'] ?? 0)) / 1024 / 1024),
                ];
            }
            $l = $byName['veranda-wa-listener'] ?? null;
            if (is_array($l)) {
                $uptimeMin = $l['uptime_ms'] > 0 ? (int)floor($l['uptime_ms'] / 60000) : 0;
                $waStatus = ($l['status'] !== '' ? $l['status'] : 'unknown') . ', restarts=' . (int)$l['restarts'] . ', uptime_min=' . $uptimeMin . ', mem_mb=' . (int)$l['mem_mb'];
            }
        }
    }
} catch (Throwable $e) {
}

$fmt = function (string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

$text = '<b>Сводка синков</b>' . "\n"
    . 'Дата: ' . $fmt($today) . ' (Asia/Ho_Chi_Minh)' . "\n\n"
    . '• <b>Menu sync</b>: last=' . $fmt($menuLast) . ', yesterday=' . (int)$menuCount . "\n"
    . '• <b>Telegram alerts</b>: last=' . $fmt($tgLast) . ', yesterday=' . (int)$tgCount . "\n"
    . '• <b>Kitchen online</b>: last=' . $fmt($kitchenLast) . ', yesterday=' . (int)$kitchenCount . "\n"
    . '• <b>WA listener</b>: ' . $fmt($waStatus) . "\n";

$ok = $sendTelegram($tgToken, $tgUserId, $text);
if (!$ok) {
    fwrite(STDERR, "Failed to send Telegram message\n");
}

if (!empty($errLines)) {
    $tmp = '/tmp/veranda_sync_errors_' . preg_replace('/\D+/', '', $yesterday) . '.txt';
    file_put_contents($tmp, implode("\n", $errLines) . "\n");
    $sendTelegramFile($tgToken, $tgUserId, $tmp, 'Ошибки синков за ' . $yesterday);
}


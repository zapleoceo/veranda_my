<?php

require_once __DIR__ . '/../../src/classes/Database.php';
require_once __DIR__ . '/../../src/classes/TelegramBot.php';
require_once __DIR__ . '/../../reservations/tg_config.php';

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

$loadEnv(__DIR__ . '/../../.env');

$spotTzName = trim((string)($_ENV['POSTER_SPOT_TIMEZONE'] ?? ''));
if ($spotTzName === '' || !in_array($spotTzName, timezone_identifiers_list(), true)) {
    $spotTzName = 'Asia/Ho_Chi_Minh';
}
date_default_timezone_set($spotTzName);

$tgToken = reservations_tg_token();
$tgChatId = reservations_tg_chat_id();
$tgThreadId = reservations_tg_thread_id();
if ($tgToken === '' || $tgChatId === '') {
    exit(0);
}

$dbHost = $_ENV['DB_HOST'] ?? 'localhost';
$dbName = $_ENV['DB_NAME'] ?? 'veranda_my';
$dbUser = $_ENV['DB_USER'] ?? 'veranda_my';
$dbPass = $_ENV['DB_PASS'] ?? '';
$tableSuffix = (string)($_ENV['DB_TABLE_SUFFIX'] ?? '');

$today = (new DateTimeImmutable('now', new DateTimeZone($spotTzName)))->format('Y-m-d');
$from = $today . ' 00:00:00';
$to = $today . ' 23:59:59';

$rows = [];
$dbOk = true;
try {
    $db = new \App\Classes\Database($dbHost, $dbName, $dbUser, $dbPass, $tableSuffix);
    $db->createReservationsTable();
    $t = $db->t('reservations');
    $rows = $db->query(
        "SELECT id, qr_code, table_num, guests, start_time, tg_message_id
         FROM {$t}
         WHERE deleted_at IS NULL
           AND start_time BETWEEN ? AND ?
         ORDER BY start_time ASC, id ASC",
        [$from, $to]
    )->fetchAll();
    if (!is_array($rows)) $rows = [];
} catch (Throwable $e) {
    $rows = [];
    $dbOk = false;
}

$esc = function (string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

$tgMsgLink = function (string $chatId, int $messageId) use ($esc): string {
    $chatId = trim($chatId);
    if ($messageId <= 0) return '';
    if (preg_match('/^-100(\d+)$/', $chatId, $m)) {
        $internal = $m[1];
        return 'https://t.me/c/' . $esc($internal) . '/' . $esc((string)$messageId);
    }
    return '';
};

$lines = [];
$lines[] = '<b>Доброе утро, брони на сегодня</b> (' . $esc($today) . ')';
$lines[] = '';

if (!count($rows)) {
    $lines[] = $dbOk ? 'Броней на сегодня нет.' : 'Не удалось проверить брони (ошибка БД).';
} else {
    $i = 0;
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $i++;
        $id = (int)($r['id'] ?? 0);
        $code = trim((string)($r['qr_code'] ?? ''));
        if ($code === '') $code = (string)$id;
        $tableNum = trim((string)($r['table_num'] ?? ''));
        $guests = (int)($r['guests'] ?? 0);
        $startRaw = trim((string)($r['start_time'] ?? ''));
        $time = '';
        try {
            if ($startRaw !== '') {
                $dt = new DateTimeImmutable($startRaw, new DateTimeZone($spotTzName));
                $time = $dt->format('H:i');
            }
        } catch (Throwable $e) {
            $time = '';
        }

        $mid = (int)($r['tg_message_id'] ?? 0);
        $url = $tgMsgLink($tgChatId, $mid);
        $codeHtml = $url !== '' ? ('<a href="' . $url . '">' . $esc($code) . '</a>') : ('<b>' . $esc($code) . '</b>');
        $lines[] = $i . '. ' . $codeHtml . ', стол ' . $esc($tableNum) . ', ' . $esc((string)$guests) . ' чел, ' . $esc($time);
    }
}

$lines[] = '';
$lines[] = 'Я вам каждое утро теперь напоминать буду.';

$text = implode("\n", $lines);

try {
    $bot = new \App\Classes\TelegramBot($tgToken, $tgChatId);
    $bot->sendMessage($text, $tgThreadId > 0 ? $tgThreadId : null);
} catch (Throwable $e) {
    exit(0);
}

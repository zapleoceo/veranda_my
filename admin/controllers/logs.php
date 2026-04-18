<?php
$spotTzName = trim((string)($_ENV['POSTER_SPOT_TIMEZONE'] ?? ''));
if ($spotTzName === '' || !in_array($spotTzName, timezone_identifiers_list(), true)) {
    $spotTzName = 'Asia/Ho_Chi_Minh';
}
$spotTz = new DateTimeZone($spotTzName);

$view = (string)($_REQUEST['view'] ?? 'kitchen');
$lines = (int)($_GET['lines'] ?? 200);
if ($lines < 50) $lines = 50;
if ($lines > 800) $lines = 800;

$baseDir = __DIR__ . '/../..';
$logMap = [
    'kitchen' => $baseDir . '/cron.log',
    'telegram' => $baseDir . '/telegram.log',
    'menu' => $baseDir . '/menu_sync.log',
    'php' => $baseDir . '/php_errors.log',
];
if (!array_key_exists($view, $logMap)) {
    $view = 'kitchen';
}
$path = $logMap[$view];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'run_sync') {
    $key = (string)($_POST['key'] ?? '');
    $jobs = [
        'kitchen' => [
            'cmd' => '/usr/bin/php /var/www/veranda_my_usr/data/www/veranda.my/scripts/kitchen/cron.php >> /var/www/veranda_my_usr/data/www/veranda.my/cron.log 2>&1',
        ],
        'telegram' => [
            'cmd' => '/usr/bin/php /var/www/veranda_my_usr/data/www/veranda.my/scripts/kitchen/telegram_alerts.php >> /var/www/veranda_my_usr/data/www/veranda.my/telegram.log 2>&1',
        ],
        'menu' => [
            'cmd' => '/usr/bin/php /var/www/veranda_my_usr/data/www/veranda.my/scripts/menu/cron.php >> /var/www/veranda_my_usr/data/www/veranda.my/menu_sync.log 2>&1',
        ],
    ];
    if (isset($jobs[$key])) {
        @set_time_limit(30);
        @session_write_close();
        @exec($jobs[$key]['cmd']);
        header('Location: ?tab=logs&view=' . urlencode($view) . '&lines=' . (int)$lines . '&ran=' . urlencode($key));
        exit;
    }
    header('Location: ?tab=logs&view=' . urlencode($view) . '&lines=' . (int)$lines . '&ran=0');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'clear_log') {
    if (array_key_exists($view, $logMap)) {
        $path = $logMap[$view];
        @file_put_contents($path, '');
        header('Location: ?tab=logs&view=' . urlencode($view) . '&lines=' . (int)$lines . '&cleared=1');
        exit;
    }
}
if (!empty($_GET['cleared'])) {
    $message = 'Лог очищен.';
}
if (!empty($_GET['ran'])) {
    $ran = (string)$_GET['ran'];
    $message = $ran !== '0' ? ('Запущен синк: ' . $ran) : 'Неизвестный синк.';
}

$tailFile = function (string $filePath, int $maxLines): string {
    if (!is_file($filePath)) return '';
    $data = @file($filePath, FILE_IGNORE_NEW_LINES);
    if (!is_array($data)) return '';
    if (count($data) > $maxLines) {
        $data = array_slice($data, -$maxLines);
    }
    $data = array_reverse($data);
    return implode("\n", $data);
};

$content = $tailFile($path, $lines);

$cronHuman = function (string $expr): string {
    $expr = trim($expr);
    if ($expr === '*/5 * * * *') return 'каждые 5 минут';
    if ($expr === '*/1 * * * *') return 'каждую минуту';
    if ($expr === '0 * * * *') return 'каждый час (в :00)';
    if ($expr === '5 * * * *') return 'каждый час (в :05)';
    return 'по расписанию cron';
};

$syncJobs = [
    [
        'key' => 'kitchen',
        'label' => 'Kitchen sync',
        'cron' => '*/5 * * * *',
        'log' => basename($logMap['kitchen']),
        'desc' => 'Обновляет данные кухни: забирает открытые/закрытые чеки и позиции из Poster, записывает в базу для Дашборда/Таблицы/КухняОнлайн, рассчитывает ВрЛогЗакр (логическое закрытие) и авто-игнор.',
    ],
    [
        'key' => 'telegram',
        'label' => 'Telegram alerts',
        'cron' => '*/1 * * * *',
        'log' => basename($logMap['telegram']),
        'desc' => 'Отправляет и обновляет сообщения в Telegram, если блюдо готовится дольше лимита. Удаляет сообщения, когда чек закрыт/блюдо готово/позиция в игноре.',
    ],
    [
        'key' => 'menu',
        'label' => 'Menu sync',
        'cron' => '0 * * * *',
        'log' => basename($logMap['menu']),
        'desc' => 'Синхронизирует меню из Poster: обновляет слепок poster_menu_items и справочники (цехи/категории/позиции) по poster_id. Не трогает переводы и ручные привязки/публикацию.',
    ],
];

$fileInfo = function (string $filePath): array {
    if (!is_file($filePath)) return ['exists' => false, 'mtime' => null, 'size' => null];
    $mt = @filemtime($filePath);
    $sz = @filesize($filePath);
    return ['exists' => true, 'mtime' => $mt ? (int)$mt : null, 'size' => $sz !== false ? (int)$sz : null];
};

$fmtSpotMtime = function (?int $ts) use ($spotTz): string {
    if (!$ts) return '—';
    return (new DateTimeImmutable('@' . $ts))->setTimezone($spotTz)->format('d.m.Y H:i:s');
};
$syncStatus = function (array $fi): array {
    if (empty($fi['exists']) || empty($fi['mtime'])) return ['kind' => 'bad', 'label' => 'ПРОБЛЕМА'];
    $age = time() - (int)$fi['mtime'];
    if ($age > 7200) return ['kind' => 'bad', 'label' => 'ПРОБЛЕМА'];
    return ['kind' => 'ok', 'label' => 'есть'];
};

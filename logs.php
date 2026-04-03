<?php

require_once __DIR__ . '/auth_check.php';
veranda_require('admin');

$view = (string)($_REQUEST['view'] ?? 'kitchen');
$lines = (int)($_GET['lines'] ?? 200);
if ($lines < 50) $lines = 50;
if ($lines > 800) $lines = 800;

$baseDir = __DIR__;
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
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'clear_log') {
    if (array_key_exists($view, $logMap)) {
        $path = $logMap[$view];
        @file_put_contents($path, '');
        header('Location: logs.php?view=' . urlencode($view) . '&lines=' . (int)$lines . '&cleared=1');
        exit;
    }
}
if (!empty($_GET['cleared'])) {
    $message = 'Лог очищен.';
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

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="/links/favicon.svg">
    <title>Kitchen Analytics</title>
    <link rel="stylesheet" href="assets/app.css">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f0f2f5; padding: 0; color: #1c1e21; }
        .container { width: 100%; max-width: 1800px; margin: 0 auto; padding: 12px; box-sizing: border-box; }
        .card { background: white; padding: 25px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-bottom: 25px; border: 1px solid #ddd; }
        .muted { color: #777; font-size: 12px; }
        .top-nav { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; gap: 16px; flex-wrap: wrap; }
        .nav-left { display: flex; gap: 14px; flex-wrap: wrap; align-items: center; min-width: 0; }
        .nav-title { font-weight: 800; color: #2c3e50; }
        .nav-right { display: flex; justify-content: flex-end; }
        .user-menu { position: relative; }
        .user-chip { display: inline-flex; align-items: center; gap: 8px; padding: 6px 10px; border: 1px solid #e0e0e0; border-radius: 999px; background: #fff; color: #37474f; font-weight: 600; cursor: default; }
        .user-icon { width: 22px; height: 22px; border-radius: 50%; background: #e3f2fd; display: inline-flex; align-items: center; justify-content: center; color: #1a73e8; font-weight: 800; font-size: 12px; overflow: hidden; }
        .user-icon img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .user-dropdown { position: absolute; right: 0; top: calc(100% + 8px); background: #fff; border: 1px solid #e0e0e0; border-radius: 10px; box-shadow: 0 8px 18px rgba(0,0,0,0.12); padding: 8px; min-width: 160px; display: none; z-index: 1000; }
        .user-menu.open .user-dropdown { display: block; }
        .user-dropdown a { display: block; padding: 8px 10px; border-radius: 8px; color: #37474f; text-decoration: none; font-weight: 600; }
        .user-dropdown a:hover { background: #f4f7f6; }

        .tab-links { text-align: center; margin: -10px 0 24px; }
        .tab-links a { display: inline-block; padding: 8px 14px; border-radius: 999px; margin: 0 6px; text-decoration: none; font-weight: 600; color: #1a73e8; background: rgba(26,115,232,0.08); }
        .tab-links a.active { color: white; background: #1a73e8; }
        .tab-links a:hover { background: rgba(26,115,232,0.14); }

        pre { white-space: pre-wrap; word-break: break-word; background: #0b1020; color: #e5e7eb; padding: 12px; border-radius: 12px; overflow: auto; max-height: 70vh; }
        .filters { display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-bottom: 12px; }
        .filters label { display:block; font-size:12px; font-weight:800; text-transform:uppercase; color:#6b7280; margin-bottom:6px; }
        .in { padding:8px 10px; border:1px solid #d1d5db; border-radius:10px; background:#fff; }
        .btn { padding:9px 16px; border-radius:10px; border:0; background:#1a73e8; color:#fff; font-weight:800; cursor:pointer; }
        .btn-danger { padding:9px 16px; border-radius:10px; border:0; background:#d32f2f; color:#fff; font-weight:800; cursor:pointer; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { padding: 12px 10px; text-align: left; border-bottom: 1px solid #eee; vertical-align: top; }
        th { background: #f8f9fa; color: #65676b; font-size: 13px; text-transform: uppercase; font-weight: 600; }
        .pill { display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; }
        .pill.ok { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }
        .pill.bad { background: #fdecea; color: #d32f2f; border: 1px solid #f5c2c7; }
        .info-icon { position: relative; display:inline-flex; align-items:center; justify-content:center; width:18px; height:18px; border-radius:50%; border:1px solid #cbd5e1; color:#1a73e8; font-weight:800; font-size:12px; cursor:help; background:#fff; }
        .info-icon::after { content: attr(data-tip); position:absolute; left: 50%; top: calc(100% + 8px); transform: translateX(-50%); min-width: 280px; max-width: 520px; width: max-content; padding: 10px 12px; border-radius: 12px; background: rgba(17, 24, 39, 0.96); color: #fff; font-size: 12px; line-height: 1.35; font-weight: 600; text-transform: none; box-shadow: 0 16px 32px rgba(0,0,0,0.22); opacity: 0; pointer-events: none; transition: opacity 0.12s ease-out; z-index: 2000; }
        .info-icon:hover::after { opacity: 1; }
    </style>
</head>
<body>
<div class="container">
    <div class="top-nav">
        <div class="nav-left"><div class="nav-title">Логи</div></div>
        <div class="nav-right">
        <?php require __DIR__ . '/partials/user_menu.php'; ?>
        </div>
    </div>

    <div class="tab-links">
        <a href="admin.php?tab=sync">Синки</a>
        <a href="admin.php?tab=access">Доступы</a>
        <a href="admin.php?tab=telegram">Telegram</a>
        <a href="admin.php?tab=menu">Меню</a>
        <a href="admin.php?tab=categories">Категории</a>
        <a class="active" href="logs.php">Логи</a>
    </div>

    <div class="card">
        <h3 style="margin:0 0 6px;">Синки (cron)</h3>
        <div class="muted">Это регламентные задачи на сервере. Колонка i — что делает синк.</div>
        <table>
            <thead>
                <tr>
                    <th>Синк</th>
                    <th>Cron</th>
                    <th>Лог</th>
                    <th>Статус</th>
                    <th>i</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($syncJobs as $job): ?>
                    <?php $fi = $fileInfo($logMap[$job['key']] ?? ''); ?>
                    <tr>
                        <td style="font-weight:700;"><?= htmlspecialchars($job['label']) ?></td>
                        <td><span class="pill ok" title="<?= htmlspecialchars($cronHuman($job['cron']) . ' (' . $job['cron'] . ')', ENT_QUOTES) ?>"><?= htmlspecialchars($job['cron']) ?></span></td>
                        <td><a href="logs.php?view=<?= urlencode($job['key']) ?>&lines=<?= (int)$lines ?>" style="text-decoration:none; color:#1a73e8; font-weight:600;"><?= htmlspecialchars($job['log']) ?></a></td>
                        <td>
                            <?php if (!empty($fi['exists'])): ?>
                                <span class="pill ok">есть</span>
                                <span class="muted">mtime: <?= htmlspecialchars($fi['mtime'] ? date('d.m.Y H:i:s', (int)$fi['mtime']) : '—') ?></span>
                            <?php else: ?>
                                <span class="pill bad">нет</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="info-icon" data-tip="<?= htmlspecialchars($cronHuman($job['cron']) . ': ' . $job['desc'], ENT_QUOTES) ?>">i</span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <?php if ($message !== ''): ?>
            <div class="pill ok" style="margin-bottom:12px;"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <div class="filters">
        <form method="get" class="filters" style="margin-bottom:0;">
            <div>
                <label for="view">Лог</label>
                <select class="in" id="view" name="view">
                    <option value="kitchen" <?= $view === 'kitchen' ? 'selected' : '' ?>>Kitchen cron</option>
                    <option value="telegram" <?= $view === 'telegram' ? 'selected' : '' ?>>Telegram alerts</option>
                    <option value="menu" <?= $view === 'menu' ? 'selected' : '' ?>>Menu cron</option>
                    <option value="php" <?= $view === 'php' ? 'selected' : '' ?>>PHP errors</option>
                </select>
            </div>
            <div>
                <label for="lines">Строк</label>
                <input class="in" id="lines" type="number" name="lines" min="50" max="800" value="<?= (int)$lines ?>">
            </div>
            <button class="btn" type="submit">Показать</button>
            <div class="muted" style="margin-left:auto; align-self:center;">
                <?= is_file($path) ? htmlspecialchars(basename($path)) : 'Файл не найден' ?>
            </div>
        </form>
        <form method="post" class="filters" style="margin-bottom:0;">
            <input type="hidden" name="action" value="clear_log">
            <input type="hidden" name="view" value="<?= htmlspecialchars($view, ENT_QUOTES) ?>">
            <button class="btn-danger" type="submit">Очистить</button>
        </form>
        </div>

        <pre><?= htmlspecialchars($content !== '' ? $content : '—') ?></pre>
    </div>
</div>
<script src="assets/app.js" defer></script>
<script src="assets/user_menu.js" defer></script>
</body>
</html>

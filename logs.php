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
      <?php include $_SERVER['DOCUMENT_ROOT'] . '/analytics.php'; ?>
  <link rel="stylesheet" href="/assets/css/common.css">
  <link rel="stylesheet" href="/assets/css/logs.css">
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

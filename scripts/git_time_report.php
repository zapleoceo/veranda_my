<?php

$maxGap = 1800;
foreach ($argv as $a) {
    if (preg_match('/^--max-gap=(\d+)$/', (string)$a, $m)) {
        $maxGap = max(1, (int)$m[1]);
    }
}

function section(string $p): string {
    $p = str_replace('\\', '/', trim($p));
    if ($p === '') return '';

    if (preg_match('#^payday2/#', $p)) return 'Payday2';
    if (preg_match('#^payday/#', $p)) return 'Payday';
    if (preg_match('#^reservations/#', $p)) return 'Reservations';
    if (preg_match('#^tr3/#', $p)) return 'TR3 (публичная бронь)';
    if (preg_match('#^banya/#', $p) || $p === 'banya.php' || preg_match('#^assets/(css|js)/banya\.(css|js)$#', $p)) return 'Отчёты: Баня';
    if (preg_match('#^roma/#', $p) || $p === 'roma.php' || preg_match('#^assets/(css|js)/roma\.(css|js)$#', $p)) return 'Отчёты: Roma';
    if (preg_match('#^employees/#', $p) || $p === 'employees.php' || preg_match('#^assets/(css|js)/employees\.(css|js)$#', $p)) return 'Отчёты: ЗП сотрудников';

    if (preg_match('#^admin/controllers/access\.php$|^admin/views/access\.php$|^admin/assets/js/access\.js$#', $p)) return 'Admin/Access';
    if (preg_match('#^admin/controllers/sync\.php$|^admin/views/sync\.php$|^admin/assets/js/sync\.js$#', $p)) return 'Admin/Sync';
    if (preg_match('#^admin/controllers/menu\.php$|^admin/views/menu\.php$|^admin/assets/js/menu\.js$#', $p)) return 'Admin/Menu';
    if (preg_match('#^admin/controllers/categories\.php$|^admin/views/categories\.php$#', $p)) return 'Admin/Categories';
    if (preg_match('#^admin/controllers/telegram\.php$|^admin/views/telegram\.php$#', $p)) return 'Admin/Telegram';
    if (preg_match('#^admin/controllers/logs\.php$|^admin/views/logs\.php$#', $p)) return 'Admin/Logs';
    if (preg_match('#^admin/controllers/reservations\.php$|^admin/views/reservations\.php$|^admin/assets/js/reservations\.js$#', $p)) return 'Admin/Reservations';
    if (preg_match('#^admin/index\.php$|^admin/views/layout\.php$|^admin/assets/(css|js)/common\.js$|^admin/assets/css/admin\.css$#', $p)) return 'Admin/Shell';
    if (preg_match('#^admin/#', $p)) return 'Admin/Other';

    if (preg_match('#^admin_orig\.php$|^admin_dc[0-9a-z_]+\.php$#', $p) || preg_match('#^assets/(css|js)/admin(_\d+)?\.(css|js)$#', $p)) return 'Admin/Legacy';

    if (
        preg_match('#^links/menu-beta\.php$|^assets/css/menu-beta\.css$|^assets/js/menu-beta\.js$|^api/menu/#', $p) ||
        str_contains($p, 'PosterMenuSync') ||
        str_contains($p, 'MenuAutoFill') ||
        str_contains($p, 'MenuCategoryAutoFill') ||
        $p === 'menu_cron.php' ||
        preg_match('#^scripts/menu/#', $p)
    ) return 'Онлайн-меню';

    if (preg_match('#^kitchen_online\.php$|^assets/js/kitchen_online\.js$|^assets/css/kitchen_online\.css$#', $p)) return 'КухняОнлайн';
    if (preg_match('#^dashboard\.php$|^assets/css/dashboard\.css$#', $p)) return 'Дашборд';
    if (preg_match('#^rawdata\.php$|^rawdata_receipts_chunk\.php$|^assets/css/rawdata\.css$#', $p)) return 'Rawdata';
    if (preg_match('#^errors\.php$|^assets/css/errors\.css$#', $p)) return 'Cooked (errors)';
    if (preg_match('#^zapara\.php$|^assets/css/zapara\.css$|^assets/js/zapara\.js$#', $p)) return 'Zapara';
    if (preg_match('#^sepay/#', $p)) return 'SePay webhook';
    if (preg_match('#^neworder/#', $p)) return 'Neworder';

    if (preg_match('#^links/index\.php$|^assets/css/links_index\.css$|^links/favicon\.svg$|^links/(grass_corner_1_7|gray-tiles-texture)\.(png|jpg)$#', $p)) return 'Links (публичные)';
    if (preg_match('#^links/table-reservation\.(js|css)$#', $p) || preg_match('#^assets/js/Tr2\.js$|^assets/css/Tr2\.css$#', $p)) return 'TableReservation (legacy)';

    if ($p === 'cron.php' || $p === 'src/classes/KitchenAnalytics.php' || preg_match('#^scripts/kitchen/#', $p)) return 'Kitchen sync (cron)';
    if ($p === 'telegram_alerts.php' || $p === 'telegram_webhook.php' || $p === 'src/classes/TelegramBot.php' || preg_match('#^scripts/kitchen/telegram_alerts\.php$#', $p)) return 'Telegram alerts/webhook';

    if ($p === 'src/classes/PosterAdminAjax.php') return 'Payday2';
    if ($p === 'src/classes/PosterReservationHelper.php') return 'Reservations';
    if ($p === 'src/classes/EventLogger.php') return 'Логи/Наблюдаемость';
    if ($p === 'src/classes/WhatsAppAPI.php') return 'Интеграции: WhatsApp';
    if ($p === 'src/classes/ZaloAPI.php') return 'Интеграции: Zalo';

    if (in_array($p, ['auth_check.php', 'login.php', 'logout.php', 'auth_callback.php', 'src/classes/Auth.php', 'partials/user_menu.php', 'assets/user_menu.js'], true)) return 'Auth/UI shell';
    if (in_array($p, ['src/classes/Database.php', 'src/classes/PosterAPI.php', 'src/classes/MetaRepository.php'], true)) return 'Core (DB/Poster/Meta)';

    if ($p === 'analytics.php') return 'Инфраструктура/Аналитика';
    if (preg_match('#^(daily_summary|menu_cron|telegram_alerts|cron)\.php$#', $p)) return 'Scripts (entrypoints)';
    if (preg_match('#^[a-z0-9_]+\.php$#', $p)) return 'Страницы (прочее)';

    if (preg_match('#^\.github/workflows/#', $p) || in_array($p, ['.htaccess', '.gitignore', 'DEPLOYMENT.md', 'package.json', 'package-lock.json'], true)) return 'Инфраструктура/Деплой';
    if (preg_match('#^assets/#', $p)) return 'UI/Assets (прочее)';
    if (preg_match('#^scripts/#', $p)) return 'Scripts (прочее)';

    return 'Прочее';
}

function fmt(float $sec): string {
    $s = (int)round($sec);
    if ($s < 0) $s = 0;
    $h = intdiv($s, 3600);
    $m = intdiv($s % 3600, 60);
    if ($h <= 0) return $m . ' мин';
    if ($m <= 0) return $h . ' ч';
    return $h . ' ч ' . $m . ' мин';
}

$cmd = 'git log --no-merges --reverse --pretty=format:"--COMMIT--%H|%ct" --name-only';
$raw = shell_exec($cmd);
if (!is_string($raw) || trim($raw) === '') {
    fwrite(STDERR, "Failed to read git log\n");
    exit(1);
}

$lines = preg_split('/\r?\n/', $raw);
$commits = [];
$cur = null;
foreach ($lines as $line) {
    if (str_starts_with($line, '--COMMIT--')) {
        if (is_array($cur)) $commits[] = $cur;
        $tmp = substr($line, 10);
        $parts = explode('|', $tmp, 2);
        $cur = [
            't' => isset($parts[1]) ? (int)$parts[1] : 0,
            'files' => [],
        ];
        continue;
    }
    if (!is_array($cur)) continue;
    $p = trim($line);
    if ($p !== '') $cur['files'][] = str_replace('\\', '/', $p);
}
if (is_array($cur)) $commits[] = $cur;

$totals = [];
$total = 0.0;
$intervals = 0;

for ($i = 1; $i < count($commits); $i++) {
    $delta = (int)$commits[$i]['t'] - (int)$commits[$i - 1]['t'];
    if ($delta <= 0 || $delta > $maxGap) continue;

    $weights = [];
    foreach ($commits[$i]['files'] as $f) {
        $s = section((string)$f);
        if ($s === '') continue;
        $weights[$s] = ($weights[$s] ?? 0) + 1;
    }
    if (!$weights) $weights = ['Прочее' => 1];

    $sum = array_sum($weights);
    if ($sum <= 0) continue;

    foreach ($weights as $k => $w) {
        $totals[$k] = ($totals[$k] ?? 0) + ((float)$delta) * ((float)$w / (float)$sum);
    }

    $total += $delta;
    $intervals++;
}

arsort($totals);

echo "### Времязатраты по Git (оценка)\n";
echo "- Правило: считаем только интервалы между соседними коммитами <= " . (int)floor($maxGap / 60) . " минут\n";
echo "- Интервал относится к следующему коммиту и распределяется по разделам пропорционально числу изменённых файлов\n";
echo "- Учтённых интервалов: " . (int)$intervals . "\n";
echo "- Итого эффективного времени: " . fmt((float)$total) . "\n\n";
echo "| Раздел | Время |\n";
echo "|---|---:|\n";
foreach ($totals as $k => $sec) {
    echo "| " . $k . " | " . fmt((float)$sec) . " |\n";
}
echo "| **Итого** | **" . fmt((float)$total) . "** |\n";

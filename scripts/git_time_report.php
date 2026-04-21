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
    if (preg_match('#^admin/#', $p)) return 'Admin';

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

    if ($p === 'cron.php' || $p === 'src/classes/KitchenAnalytics.php' || preg_match('#^scripts/kitchen/#', $p)) return 'Kitchen sync (cron)';
    if ($p === 'telegram_alerts.php' || $p === 'telegram_webhook.php' || $p === 'src/classes/TelegramBot.php' || preg_match('#^scripts/kitchen/telegram_alerts\.php$#', $p)) return 'Telegram alerts/webhook';

    if (in_array($p, ['auth_check.php', 'login.php', 'logout.php', 'auth_callback.php', 'src/classes/Auth.php', 'partials/user_menu.php', 'assets/user_menu.js'], true)) return 'Auth/UI shell';
    if (in_array($p, ['src/classes/Database.php', 'src/classes/PosterAPI.php', 'src/classes/MetaRepository.php'], true)) return 'Core (DB/Poster/Meta)';

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

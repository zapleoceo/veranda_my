<?php
declare(strict_types=1);

// Только из консоли. Каталоги scripts/ и cron/ лежат внутри docroot и
// отдаются nginx'ом напрямую (проверено: GET /scripts/kitchen/cron.php
// возвращает 500, то есть файл ИСПОЛНЯЕТСЯ, а не отдаётся как текст).
// Без этой проверки любой мог по обычной ссылке запустить ресинк Poster,
// перезапись kitchen_stats, удаление сообщений или рассылку персоналу.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Forbidden: скрипт запускается только из консоли.\n");
}

$urls = [
    'http://127.0.0.1:8001/tr3/api.php?ajax=bootstrap&lang=ru',
    'https://veranda.my/tr3/api.php?ajax=bootstrap&lang=ru',
];
$ctx = stream_context_create([
    'http' => [
        'method' => 'GET',
        'timeout' => 15,
        'header' => "Accept: application/json\r\n",
    ]
]);

$raw = '';
$lastUrl = '';
foreach ($urls as $url) {
    $lastUrl = $url;
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw !== false && $raw !== '') break;
    $raw = '';
}
if ($raw === '') {
    fwrite(STDOUT, "SKIP: cannot fetch {$lastUrl}\n");
    exit(0);
}

$j = json_decode($raw, true);
if (!is_array($j)) {
    fwrite(STDERR, "FAIL: non-json response\n");
    exit(1);
}
if (empty($j['ok'])) {
    fwrite(STDERR, "FAIL: ok=false\n");
    exit(1);
}
if (!array_key_exists('apiBase', $j)) {
    fwrite(STDERR, "FAIL: missing apiBase\n");
    exit(1);
}

fwrite(STDOUT, "OK\n");
exit(0);

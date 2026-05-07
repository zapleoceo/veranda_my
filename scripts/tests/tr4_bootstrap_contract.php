<?php
declare(strict_types=1);

$urls = [
    'http://127.0.0.1:8001/tr4/api.php?ajax=bootstrap&lang=ru',
    'https://veranda.my/tr4/api.php?ajax=bootstrap&lang=ru',
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

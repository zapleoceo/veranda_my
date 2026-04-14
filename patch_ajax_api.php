<?php
$php = file_get_contents('payday2/ajax.php');

$initApi = <<<PHP
    try {
        \$api = new \App\Classes\PosterAPI((string)\$token);
    } catch (\Throwable \$e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'API init error'], JSON_UNESCAPED_UNICODE);
        exit;
    }
PHP;

$php = str_replace(
    "try {\n        \$amountCents = 0;",
    $initApi . "\n    try {\n        \$amountCents = 0;",
    $php
);

$php = str_replace(
    "try {\n        \$startTs = strtotime(\$dFrom . ' 00:00:00');",
    $initApi . "\n    try {\n        \$startTs = strtotime(\$dFrom . ' 00:00:00');",
    $php
);

file_put_contents('payday2/ajax.php', $php);

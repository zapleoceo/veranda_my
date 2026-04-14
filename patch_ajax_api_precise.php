<?php
$php = file_get_contents('payday2/ajax.php');

$old1 = <<<PHP
    try {
        \$amountCents = 0;
PHP;
$new1 = <<<PHP
    try {
        \$api = new \App\Classes\PosterAPI((string)\$token);
    } catch (\Throwable \$e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'API init error'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        \$amountCents = 0;
PHP;
$php = str_replace($old1, $new1, $php);

$old2 = <<<PHP
    try {
        \$startTs = strtotime(\$dFrom . ' 00:00:00');
PHP;
$new2 = <<<PHP
    try {
        \$api = new \App\Classes\PosterAPI((string)\$token);
    } catch (\Throwable \$e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'API init error'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        \$startTs = strtotime(\$dFrom . ' 00:00:00');
PHP;
$php = str_replace($old2, $new2, $php);

file_put_contents('payday2/ajax.php', $php);

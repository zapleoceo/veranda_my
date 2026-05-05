<?php
require_once __DIR__ . '/auth_check.php';
veranda_require('zapara');

$ajax = (string)($_GET['ajax'] ?? '');
if ($ajax !== '') {
    require __DIR__ . '/api/poster/zapara/index.php';
    exit;
}

$qs = $_GET;
unset($qs['ajax']);
$url = '/zapara/' . (!empty($qs) ? ('?' . http_build_query($qs)) : '');
header('Location: ' . $url, true, 301);
exit;

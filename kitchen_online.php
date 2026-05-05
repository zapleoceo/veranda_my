<?php
require_once __DIR__ . '/auth_check.php';
veranda_require('kitchen_online');

if (($_GET['ajax'] ?? '') === '1') {
    require __DIR__ . '/api/sql/kitchen_online/index.php';
    exit;
}

$qs = $_GET;
unset($qs['ajax']);
$url = '/kitchen_online/' . (!empty($qs) ? ('?' . http_build_query($qs)) : '');
header('Location: ' . $url, true, 301);
exit;


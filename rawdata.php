<?php
require_once __DIR__ . '/auth_check.php';
veranda_require('rawdata');

if (($_GET['ajax'] ?? '') === '1' || ($_GET['ajax'] ?? '') === 'list' || ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_exclude_item']))) {
    require __DIR__ . '/api/sql/rawdata/index.php';
    exit;
}

$qs = $_GET;
unset($qs['ajax']);
$url = '/rawdata/' . (!empty($qs) ? ('?' . http_build_query($qs)) : '');
header('Location: ' . $url, true, 301);
exit;

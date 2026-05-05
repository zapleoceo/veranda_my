<?php
require_once __DIR__ . '/auth_check.php';
veranda_require('dashboard');

$qs = $_GET;
$url = '/dashboard/' . (!empty($qs) ? ('?' . http_build_query($qs)) : '');
header('Location: ' . $url, true, 301);
exit;


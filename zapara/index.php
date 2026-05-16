<?php

if (empty($GLOBALS['_VERANDA_SLIM_RUNNING'])) {
    require __DIR__ . '/../public/index.php';
    return;
}

require_once __DIR__ . '/../auth_check.php';
veranda_require('zapara');
date_default_timezone_set('Asia/Ho_Chi_Minh');

$today = date('Y-m-d');
$defaultFrom = date('Y-m-d', strtotime('-14 days'));
$defaultTo = $today;

require __DIR__ . '/view.php';


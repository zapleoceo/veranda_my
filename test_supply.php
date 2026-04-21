<?php
$env = parse_ini_file(__DIR__ . '/.env');
$token = $env['POSTER_API_TOKEN'] ?? '';
require_once __DIR__ . '/src/classes/PosterAPI.php';
$api = new \App\Classes\PosterAPI($token);
$res = $api->getSupply(1915);
echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

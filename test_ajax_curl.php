<?php
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET['ajax'] = 'refresh_finance_transfers';

// Override auth_check
require_once __DIR__ . '/vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

$db = new PDO('mysql:host=127.0.0.1;dbname=zapleoce_my;charset=utf8mb4', 'zapleoce_my', 'r5sR1c6s', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// Provide payload
$_POST_PAYLOAD = json_encode([
    'kind' => 'vietnam',
    'dateFrom' => '2026-04-13',
    'dateTo' => '2026-04-13',
    'accountFrom' => 1,
    'accountTo' => 9
]);

// Wait, I can't override php://input easily.

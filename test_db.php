<?php
require __DIR__ . '/src/classes/Database.php';
$db = new \App\Classes\Database('localhost', 'veranda_my', 'veranda_my', '');
$res = $db->query("SHOW TABLES")->fetchAll();
print_r($res);

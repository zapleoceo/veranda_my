<?php

declare(strict_types=1);

// Английская версия главной — veranda.my/en/.
// nginx отдаёт директорию через её index.php; рендер — общий (../home/index.php).
$_GET['lang'] = 'en';
require __DIR__ . '/../home/index.php';

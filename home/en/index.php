<?php

declare(strict_types=1);

// Языковой алиас /home/en/ — рендер главной на английском.
// nginx отдаёт директорию через её index.php; .htaccess-rewrite — фолбэк для Apache.
$_GET['lang'] = 'en';
require __DIR__ . '/../index.php';

<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../src/Bootstrap/app.php';
$app->run();

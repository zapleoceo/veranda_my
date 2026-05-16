<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../src/Bootstrap/app.php';

// Flag any legacy entry point (banya/index.php, roma/index.php, ...) can use
// to detect "I'm being required by a Slim controller, not invoked directly
// by Apache .htaccess". Legacy files that find the flag UNSET will delegate
// back here so the request renders through the framework with the sidebar.
$GLOBALS['_VERANDA_SLIM_RUNNING'] = true;

$app->run();

<?php

// Legacy entry kept as a Slim delegator: nginx serves .php files via
// PHP-FPM directly, so a missing /login.php would return 404 instead of
// falling through to public/index.php. This shim is one line — all real
// logic lives in App\Controllers\Auth\LoginController (route /login).
require __DIR__ . '/public/index.php';

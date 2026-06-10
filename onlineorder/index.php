<?php

// /onlineorder shim — same pattern as payday3/neworder: when Apache's
// DirectoryIndex resolves /onlineorder/ to this file directly, boot
// the Slim front controller; when Slim is already running, do nothing
// (the route in src/Bootstrap/routes.php owns the request).
if (empty($GLOBALS['_VERANDA_SLIM_RUNNING'])) {
    require __DIR__ . '/../public/index.php';
    return;
}

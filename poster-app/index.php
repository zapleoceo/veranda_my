<?php

// Slim shim — same pattern as neworder/index.php and payday3/index.php.
// Direct Apache hits to /poster-app/ resolve here (DirectoryIndex),
// which delegates to the Slim front controller. Slim's /poster-app[/]
// route dispatches to PosterAppController.

if (empty($GLOBALS['_VERANDA_SLIM_RUNNING'])) {
    require __DIR__ . '/../public/index.php';
    return;
}

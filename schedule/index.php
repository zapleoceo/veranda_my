<?php

// Same guard pattern as payday3/index.php. The /schedule/ directory now
// exists on disk (it holds the page's CSS/JS at /schedule/assets/), so
// Apache+nginx's DirectoryIndex resolves /schedule/ to THIS file rather
// than falling through to the Slim front controller. The shim re-enters
// Slim, where route /schedule[/] dispatches to ScheduleController.
//
// The framework never requires this file itself.

if (empty($GLOBALS['_VERANDA_SLIM_RUNNING'])) {
    require __DIR__ . '/../public/index.php';
    return;
}

<?php

// Same guard pattern as banya/roma/employees/... Direct Apache hits to
// /payday3/ resolve to this file (via DirectoryIndex), which delegates
// to the Slim front controller. Slim's route /payday3[/] dispatches to
// Payday3Controller. The framework never requires this file itself.

if (empty($GLOBALS['_VERANDA_SLIM_RUNNING'])) {
    require __DIR__ . '/../public/index.php';
    return;
}

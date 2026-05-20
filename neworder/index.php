<?php

// Same guard pattern as payday3/banya/roma/employees/... Direct Apache
// hits to /neworder/ resolve here (DirectoryIndex), which delegates to
// the Slim front controller. Slim's /neworder[/] route dispatches to
// NewOrderController. The framework never requires this file itself.

if (empty($GLOBALS['_VERANDA_SLIM_RUNNING'])) {
    require __DIR__ . '/../public/index.php';
    return;
}

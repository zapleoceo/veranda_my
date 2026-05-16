<?php

// Legacy thin delegator. Direct hits via Apache .htaccess are bounced to
// the Slim front controller where ZaparaController renders the page
// (src/Views/zapara_content.php → src/Views/layout.php). The framework
// does not require this file for any path, so nothing else lives here.

if (empty($GLOBALS['_VERANDA_SLIM_RUNNING'])) {
    require __DIR__ . '/../public/index.php';
    return;
}

<?php

// Legacy thin delegator. Direct hits via Apache .htaccess are bounced to
// the Slim front controller where KitchenOnlineController handles both the
// HTML render (src/Views/kitchen_online_content.php → layout.php) and the
// inline ?ajax=1 endpoint. The framework does not require this file.

if (empty($GLOBALS['_VERANDA_SLIM_RUNNING'])) {
    require __DIR__ . '/../public/index.php';
    return;
}

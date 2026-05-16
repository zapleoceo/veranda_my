<?php

// Legacy thin delegator. Direct hits via Apache .htaccess are bounced to
// the Slim front controller where ReservationsController handles the HTML
// render (src/Views/reservations_content.php → layout.php) and every
// inline ?ajax=* endpoint. The framework does not require this file.

if (empty($GLOBALS['_VERANDA_SLIM_RUNNING'])) {
    require __DIR__ . '/../public/index.php';
    return;
}

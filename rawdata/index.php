<?php

// Legacy thin delegator. Direct hits via Apache .htaccess are bounced to
// the Slim front controller where RawdataController handles the HTML
// render (src/Views/rawdata_content.php → layout.php), the ?ajax=1 / list
// endpoint, the POST toggle_exclude_item handler, and the ?resync=1 job
// launcher inline. The framework does not require this file.

if (empty($GLOBALS['_VERANDA_SLIM_RUNNING'])) {
    require __DIR__ . '/../public/index.php';
    return;
}

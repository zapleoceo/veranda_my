<?php

declare(strict_types=1);

// Root entry point.
//
// nginx try_files funnels every request that doesn't match a static file or a
// real directory index here (e.g. /tr3/api, /menu, /links, /tr3, etc.). We
// delegate to the Slim front controller so those routes resolve correctly.
// Direct .php files (e.g. /login.php, /dashboard.php, /tr3/api.php) are still
// served by nginx as files and never reach this dispatcher.
//
// Slim's "/" route handles the legacy auth-aware redirect (session →
// /admin, otherwise /links) in src/Bootstrap/routes.php.

require __DIR__ . '/public/index.php';

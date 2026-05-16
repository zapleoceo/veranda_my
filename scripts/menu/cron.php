<?php

// Crontab entry-point shim. Production crontab.txt invokes this path for
// backward compatibility; actual sync logic lives in cron/menu_sync.php
// alongside the other Slim-style cron entries.
require __DIR__ . '/../../cron/menu_sync.php';


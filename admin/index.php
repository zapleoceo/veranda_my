<?php

// Legacy admin/ folder kept ONLY for this file. Apache resolves /admin/
// via DirectoryIndex and would 403 (directory listing forbidden) without
// a matching index.php. This shim delegates to Slim — Admin\* controllers
// own every /admin/* route.
require __DIR__ . '/../public/index.php';

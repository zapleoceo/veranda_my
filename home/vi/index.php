<?php

declare(strict_types=1);

// Легаси-путь /home/vi/ → 301 на новый корневой /vi/ (без /home).
header('Location: /vi/', true, 301);
exit;

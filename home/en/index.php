<?php

declare(strict_types=1);

// Легаси-путь /home/en/ → 301 на новый корневой /en/ (без /home).
header('Location: /en/', true, 301);
exit;

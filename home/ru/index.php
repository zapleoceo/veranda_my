<?php

declare(strict_types=1);

// Легаси-путь /home/ru/ → 301 на новый корневой /ru/ (без /home).
header('Location: /ru/', true, 301);
exit;

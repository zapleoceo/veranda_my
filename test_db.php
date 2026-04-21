<?php
$lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($lines as $line) {
    if (strpos(trim($line), '#') === 0) continue;
    if (strpos($line, '=') === false) continue;
    [$name, $value] = explode('=', $line, 2);
    $_ENV[trim($name)] = trim(trim((string)$value), '"\'');
}
echo $_ENV['DB_HOST'] . "\n";
echo $_ENV['DB_NAME'] . "\n";
echo substr($_ENV['POSTER_API_TOKEN'] ?? '', 0, 5) . "...\n";

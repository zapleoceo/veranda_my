<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$envFile = $root . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $t = trim($line);
        if ($t === '' || str_starts_with($t, '#')) continue;
        if (!str_contains($t, '=')) continue;
        [$name, $value] = explode('=', $line, 2);
        $_ENV[trim($name)] = trim($value);
    }
}

$host = trim((string)($_ENV['DB_HOST'] ?? ''));
$name = trim((string)($_ENV['DB_NAME'] ?? ''));
$user = trim((string)($_ENV['DB_USER'] ?? ''));
$pass = (string)($_ENV['DB_PASS'] ?? '');
$suffix = (string)($_ENV['DB_TABLE_SUFFIX'] ?? '');

if ($host === '' || $name === '' || $user === '') {
    fwrite(STDOUT, "SKIP: DB not configured\n");
    exit(0);
}

require_once $root . '/src/classes/Database.php';
require_once $root . '/src/classes/MetaRepository.php';

$db = new \App\Classes\Database($host, $name, $user, $pass, $suffix);
$repo = new \App\Classes\MetaRepository($db);

$halls = [2, 7];
$errors = [];

$isIntInRange = static fn($v, int $min, int $max): bool => is_int($v) && $v >= $min && $v <= $max;

foreach ($halls as $hallId) {
    $allowedKey = 'reservations_allowed_scheme_nums_hall_' . $hallId;
    $capsKey = 'reservations_table_caps_hall_' . $hallId;
    $vals = $repo->getMany([$allowedKey, $capsKey]);

    $allowedRaw = array_key_exists($allowedKey, $vals) ? trim((string)$vals[$allowedKey]) : '';
    if ($allowedRaw !== '') {
        $decoded = json_decode($allowedRaw, true);
        if (!is_array($decoded)) {
            $errors[] = "allowed not json array for hall {$hallId}";
        } else {
            foreach ($decoded as $v) {
                $n = is_numeric($v) ? (int)$v : null;
                if ($n === null || $n < 1 || $n > 500) {
                    $errors[] = "allowed out of range hall {$hallId}: " . json_encode($v, JSON_UNESCAPED_UNICODE);
                    break;
                }
            }
        }
    }

    $capsRaw = array_key_exists($capsKey, $vals) ? trim((string)$vals[$capsKey]) : '';
    if ($capsRaw !== '') {
        $decoded = json_decode($capsRaw, true);
        if (!is_array($decoded)) {
            $errors[] = "caps not json object for hall {$hallId}";
        } else {
            foreach ($decoded as $k => $v) {
                $kStr = trim((string)$k);
                if ($kStr === '' || !preg_match('/^\d+$/', $kStr)) {
                    $errors[] = "caps invalid key hall {$hallId}: " . json_encode($k, JSON_UNESCAPED_UNICODE);
                    break;
                }
                $n = (int)$kStr;
                if ($n < 1 || $n > 500) {
                    $errors[] = "caps key out of range hall {$hallId}: {$kStr}";
                    break;
                }
                $c = is_numeric($v) ? (int)$v : null;
                if ($c === null || $c < 0 || $c > 999) {
                    $errors[] = "caps value out of range hall {$hallId} key {$kStr}: " . json_encode($v, JSON_UNESCAPED_UNICODE);
                    break;
                }
            }
        }
    }
}

if ($errors) {
    fwrite(STDERR, "FAIL:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

fwrite(STDOUT, "OK\n");
exit(0);


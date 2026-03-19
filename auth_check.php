<?php
require_once __DIR__ . '/src/classes/Database.php';
require_once __DIR__ . '/src/classes/Auth.php';

// Load .env
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $_ENV[$name] = trim($value);
    }
}

$dbHost = $_ENV['DB_HOST'] ?? 'localhost';
$dbName = $_ENV['DB_NAME'] ?? 'veranda_my';
$dbUser = $_ENV['DB_USER'] ?? 'veranda_my';
$dbPass = $_ENV['DB_PASS'] ?? '';
$token = $_ENV['POSTER_API_TOKEN'] ?? '';
$googleClientId = $_ENV['GOOGLE_CLIENT_ID'] ?? '';
$googleClientSecret = $_ENV['GOOGLE_CLIENT_SECRET'] ?? '';
$googleRedirectUri = $_ENV['GOOGLE_REDIRECT_URI'] ?? 'https://veranda.my/auth_callback.php';

$db = new \App\Classes\Database($dbHost, $dbName, $dbUser, $dbPass);
$auth = new \App\Classes\Auth($db, $googleClientId, $googleClientSecret, $googleRedirectUri);

$auth->requireAuth();

if (!function_exists('veranda_get_user_permissions')) {
    function veranda_get_user_permissions(\App\Classes\Database $db, string $email): array {
        $defaults = [
            'dashboard' => true,
            'rawdata' => true,
            'kitchen_online' => true,
            'admin' => true,
            'exclude_toggle' => true,
        ];
        if ($email === '') return $defaults;
        try {
            $row = $db->query("SELECT permissions_json FROM users WHERE email = ? LIMIT 1", [$email])->fetch();
            $raw = (string)($row['permissions_json'] ?? '');
            if ($raw === '') return $defaults;
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) return $defaults;
            $out = $defaults;
            foreach ($defaults as $k => $_) {
                if (array_key_exists($k, $decoded)) {
                    $out[$k] = (bool)$decoded[$k];
                }
            }
            return $out;
        } catch (\Exception $e) {
            return $defaults;
        }
    }
}

if (!function_exists('veranda_can')) {
    function veranda_can(string $perm): bool {
        $perms = $_SESSION['user_permissions'] ?? null;
        if (!is_array($perms)) return true;
        return !empty($perms[$perm]);
    }
}

if (!function_exists('veranda_require')) {
    function veranda_require(string $perm): void {
        if (!veranda_can($perm)) {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }
    }
}

$email = (string)($_SESSION['user_email'] ?? '');
$now = time();
$ttl = 30;
$loadedAt = (int)($_SESSION['user_permissions_loaded_at'] ?? 0);
if ($loadedAt <= 0 || ($now - $loadedAt) >= $ttl) {
    $_SESSION['user_permissions'] = veranda_get_user_permissions($db, $email);
    $_SESSION['user_permissions_loaded_at'] = $now;
}

<?php
require_once __DIR__ . '/src/classes/Database.php';
require_once __DIR__ . '/src/classes/Auth.php';
require_once __DIR__ . '/src/classes/PosterAPI.php';

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
$tableSuffix = (string)($_ENV['DB_TABLE_SUFFIX'] ?? '');

$db = new \App\Classes\Database($dbHost, $dbName, $dbUser, $dbPass, $tableSuffix);
$auth = new \App\Classes\Auth($db, $googleClientId, $googleClientSecret, $googleRedirectUri);

$auth->requireAuth();

if (!function_exists('veranda_get_user_permissions')) {
    function veranda_get_user_permissions(\App\Classes\Database $db, string $email): array {
        $defaults = [
            'dashboard' => true,
            'rawdata' => true,
            'kitchen_online' => true,
            'admin' => true,
            'roma' => false,
            'banya' => false,
            'exclude_toggle' => true,
            'telegram_ack' => false,
            'payday' => false,
        ];
        if ($email === '') return $defaults;
        try {
            $users = $db->t('users');
            $row = $db->query("SELECT permissions_json FROM {$users} WHERE email = ? LIMIT 1", [$email])->fetch();
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
            if (!empty($out['exclude_toggle']) || !empty($out['telegram_ack'])) {
                $out['exclude_toggle'] = true;
                $out['telegram_ack'] = true;
            }
            if (!empty($out['admin'])) {
                $out['roma'] = true;
                $out['banya'] = true;
            }
            return $out;
        } catch (\Exception $e) {
            return $defaults;
        }
    }
}

$ensureSystemMeta = function (\App\Classes\Database $db): void {};

if (!function_exists('veranda_get_workshops')) {
    function veranda_get_workshops(\App\Classes\Database $db, string $token, string $dbName): array {
        $ttlSec = 3600;
        $now = time();
        $cached = null;
        $cachedAt = 0;
        try {
            $meta = $db->t('system_meta');
            $row = $db->query("SELECT meta_value FROM {$meta} WHERE meta_key = 'poster_workshops_json' LIMIT 1")->fetch();
            $cached = (string)($row['meta_value'] ?? '');
            $row2 = $db->query("SELECT meta_value FROM {$meta} WHERE meta_key = 'poster_workshops_updated_at' LIMIT 1")->fetch();
            $cachedAt = (int)($row2['meta_value'] ?? 0);
        } catch (\Exception $e) {
        }

        $decoded = null;
        if ($cached !== '' && ($cachedAt > 0 && ($now - $cachedAt) < $ttlSec)) {
            $decoded = json_decode($cached, true);
            if (!is_array($decoded)) $decoded = null;
        }

        if ($decoded === null && $token !== '') {
            try {
                $api = new \App\Classes\PosterAPI($token);
                $workshops = $api->request('menu.getWorkshops');
                $out = [];
                if (is_array($workshops)) {
                    foreach ($workshops as $w) {
                        $id = (string)($w['workshop_id'] ?? '');
                        $name = (string)($w['workshop_name'] ?? '');
                        if ($id === '' || $name === '') continue;
                        $out[] = ['id' => $id, 'name' => $name];
                    }
                }
                $decoded = $out;
                try {
                    $meta = $db->t('system_meta');
                    $db->query(
                        "INSERT INTO {$meta} (meta_key, meta_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
                        ['poster_workshops_json', json_encode($decoded, JSON_UNESCAPED_UNICODE)]
                    );
                    $db->query(
                        "INSERT INTO {$meta} (meta_key, meta_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
                        ['poster_workshops_updated_at', (string)$now]
                    );
                } catch (\Exception $e) {
                }
            } catch (\Exception $e) {
            }
        }

        if (!is_array($decoded)) $decoded = [];
        if (count($decoded) === 0) {
            try {
                $ks = $db->t('kitchen_stats');
                $row = $db->query(
                    "SELECT DISTINCT station AS s FROM {$ks} WHERE transaction_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) AND station IS NOT NULL AND station <> '' ORDER BY station ASC"
                )->fetchAll();
                $out = [];
                foreach ($row as $r) {
                    $s = (string)($r['s'] ?? '');
                    if ($s === '') continue;
                    $out[] = ['id' => 's:' . $s, 'name' => $s];
                }
                $decoded = $out;
            } catch (\Exception $e) {
                $decoded = [];
            }
        }
        return $decoded;
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

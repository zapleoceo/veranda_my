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
$googleClientId = $_ENV['GOOGLE_CLIENT_ID'] ?? '';
$googleClientSecret = $_ENV['GOOGLE_CLIENT_SECRET'] ?? '';
$googleRedirectUri = $_ENV['GOOGLE_REDIRECT_URI'] ?? 'https://veranda.my/auth_callback.php';

$db = new \App\Classes\Database($dbHost, $dbName, $dbUser, $dbPass);
$auth = new \App\Classes\Auth($db, $googleClientId, $googleClientSecret, $googleRedirectUri);

if ($auth->isLoggedIn()) {
    $next = (string)($_SESSION['auth_next'] ?? '');
    unset($_SESSION['auth_next']);
    if ($next === '' || $next[0] !== '/' || str_starts_with($next, '//') || str_contains($next, "\n") || str_contains($next, "\r")) {
        $next = '/dashboard.php';
    }
    header('Location: ' . $next);
    exit;
}

$loginUrl = $auth->getGoogleLoginUrl();

header('X-Robots-Tag: noindex, nofollow', true);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="/links/favicon.svg">
    <meta name="robots" content="noindex, nofollow">
    <title>Вход - Kitchen Analytics</title>
        <?php include $_SERVER['DOCUMENT_ROOT'] . '/analytics.php'; ?>
  <link rel="stylesheet" href="/assets/css/common.css">
  <link rel="stylesheet" href="/assets/css/login.css?v=2">
</head>
<body>
    <div class="login-card">
        <h1>Kitchen Analytics</h1>
        <p>Авторизуйтесь через ваш Gmail аккаунт для доступа к статистике</p>
        
        <?php if (isset($_GET['error'])): ?>
            <div class="error">
                <?php 
                    $err = $_GET['error'];
                    if ($err === 'access_denied') echo "Ваш email не найден в списке разрешенных.";
                    else if ($err === 'auth_failed') echo "Ошибка авторизации Google. Попробуйте еще раз.";
                    else echo "Произошла ошибка при входе.";
                ?>
            </div>
        <?php endif; ?>

        <a href="<?= $loginUrl ?>" class="google-btn">
            <img src="https://www.gstatic.com/images/branding/product/1x/gsa_512dp.png" alt="Google Logo">
            Войти через Google
        </a>
    </div>
    <script src="/assets/js/login.js"></script>
</body>
</html>

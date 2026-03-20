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
    header('Location: dashboard.php');
    exit;
}

$loginUrl = $auth->getGoogleLoginUrl();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="favicon.png">
    <title>Вход - Kitchen Analytics</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f0f2f5; display: flex; justify-content: center; align-items: center; min-height: 100vh; min-height: 100svh; margin: 0; padding: 16px; box-sizing: border-box; }
        .login-card { background: white; padding: 40px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); text-align: center; max-width: 400px; width: 100%; }
        h1 { color: #1a73e8; margin-bottom: 20px; font-size: 24px; }
        p { color: #65676b; margin-bottom: 30px; }
        .google-btn { display: inline-flex; align-items: center; background: white; border: 1px solid #dadce0; color: #3c4043; padding: 10px 24px; border-radius: 4px; font-weight: 500; text-decoration: none; transition: background 0.2s; }
        .google-btn:hover { background: #f8f9fa; border-color: #d2d4d7; }
        .google-btn img { width: 18px; height: 18px; margin-right: 12px; }
        .error { color: #d32f2f; background: #fdecea; padding: 10px; border-radius: 4px; margin-bottom: 20px; font-size: 14px; }
    </style>
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
</body>
</html>

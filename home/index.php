<?php

declare(strict_types=1);

/*
 * /home — публичная главная veranda.my (RU/EN/VI).
 *
 * Тонкая точка входа. Как /links и /tr3, публичная страница НЕ идёт через
 * Slim/layout.php — сама бутстрапит автозагрузчик. URL языков:
 *   /home/en/, /home/ru/, /home/vi/  → рендер на этом языке (.htaccess → ?lang=).
 *   /home/                           → определить язык браузера и 302 на /home/{lang}/.
 */

if (!class_exists(\App\Infrastructure\Config::class, false)) {
    require_once __DIR__ . '/../vendor/autoload.php';
    \App\Infrastructure\Config::load(__DIR__ . '/../.env');
}

$lang = \App\Home\I18n\Locale::normalize($_GET['lang'] ?? null);

if ($lang === null) {
    // Нет кода языка в URL — определяем по cookie/браузеру и редиректим.
    header('Location: /home/' . \App\Home\I18n\Locale::detect() . '/', true, 302);
    exit;
}

// Запоминаем явный выбор языка (для /home/ и переключателя).
setcookie(\App\Home\I18n\Locale::COOKIE, $lang, [
    'expires' => time() + 31536000,
    'path' => '/',
    'samesite' => 'Lax',
]);

echo (new \App\Home\HomeController())->render($lang);

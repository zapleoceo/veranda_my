<?php

declare(strict_types=1);

/*
 * Общий рендерер публичной главной (RU/EN/VI).
 *
 * Языковые версии живут в корне домена: /en/, /ru/, /vi/ — каждая директория
 * с тонким index.php выставляет $_GET['lang'] и подключает этот файл.
 * Заход без кода языка (легаси /home/) — определяем язык и 302 на /{lang}/.
 *
 * Как /links и /tr3, страница не идёт через Slim/layout — сама бутстрапит автозагрузчик.
 */

if (!class_exists(\App\Infrastructure\Config::class, false)) {
    require_once __DIR__ . '/../vendor/autoload.php';
    \App\Infrastructure\Config::load(__DIR__ . '/../.env');
}

$lang = \App\Home\I18n\Locale::normalize($_GET['lang'] ?? null);

if ($lang === null) {
    // Нет кода языка — определяем по cookie/браузеру и редиректим на корневую /{lang}/.
    header('Location: /' . \App\Home\I18n\Locale::detect() . '/', true, 302);
    exit;
}

// Запоминаем явный выбор языка (для переключателя).
setcookie(\App\Home\I18n\Locale::COOKIE, $lang, [
    'expires' => time() + 31536000,
    'path' => '/',
    'samesite' => 'Lax',
]);

echo (new \App\Home\HomeController())->render($lang);

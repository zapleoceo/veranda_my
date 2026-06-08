<?php

declare(strict_types=1);

/*
 * /home — публичная главная страница veranda.my.
 *
 * Тонкая точка входа. Как /links и /tr3, публичная страница НЕ идёт через
 * Slim/layout.php (у неё своя вёрстка, не админский сайдбар) — поэтому здесь
 * самостоятельно бутстрапим Composer-автозагрузчик и делегируем рендер
 * модулю App\Home. Вся логика — в src/Home/*, вся разметка — в src/Views/home/*.
 */

if (!class_exists(\App\Infrastructure\Config::class, false)) {
    require_once __DIR__ . '/../vendor/autoload.php';
    \App\Infrastructure\Config::load(__DIR__ . '/../.env');
}

echo (new \App\Home\HomeController())->render();

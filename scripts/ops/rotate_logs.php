<?php

declare(strict_types=1);

// Только из консоли: scripts/ лежит в корне сайта, nginx отдал бы его по HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Ротация журналов крона (cron.log, telegram.log, menu_sync.log, …).
 *
 * Крон дописывает в них через `>>`, системного logrotate у пользователя нет,
 * поэтому они росли без ограничений: к 2026-10-03 cron.log — 710 МБ,
 * telegram.log — 150 МБ. Журналы приложения (app-*.log) не трогаем — их
 * ротирует сам Monolog.
 *
 * Файл больше LIMIT переименовывается в .1 (прежний .1 удаляется), следующий
 * запуск крона откроет новый файл. Итого на журнал не больше 2 × LIMIT.
 * Переименование безопасно: каждый запуск крона открывает файл заново.
 *
 * Запуск: php scripts/ops/rotate_logs.php /путь/к/папке/журналов
 * Без зависимостей — composer не нужен.
 */

const LIMIT = 20 * 1024 * 1024;

$dir = rtrim((string) ($argv[1] ?? ''), '/');
if ($dir === '' || !is_dir($dir)) {
    fwrite(STDERR, "usage: php rotate_logs.php <logs-dir>\n");
    exit(2);
}

foreach (glob($dir . '/*.log') ?: [] as $file) {
    if (str_starts_with(basename($file), 'app-')) {
        continue;
    }
    clearstatcache(true, $file);
    $size = (int) @filesize($file);
    if ($size <= LIMIT) {
        continue;
    }
    if (@rename($file, $file . '.1')) {
        printf("%s rotated %s (%.1f MB)\n", date('c'), basename($file), $size / 1048576);
    } else {
        fwrite(STDERR, "cannot rotate {$file}\n");
    }
}

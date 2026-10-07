<?php

declare(strict_types=1);

namespace App\Services;

use App\Infrastructure\AiBrokerClient;
use App\Infrastructure\Database;
use App\Infrastructure\Logger;

/**
 * Свежесть публичного меню. Страница всегда рендерится из БД (быстро), а
 * состав, цены и переводы подтягиваются из Poster/ИИ по просмотрам:
 *
 *  - beforeRender(): меню долго никто не открывал (> SYNC_NOW_AFTER) —
 *    синк прямо сейчас, ~1 с, чтобы гость не увидел устаревшие цены;
 *  - afterResponse(): после отдачи страницы — синк, если старше
 *    BACKGROUND_AFTER, и ИИ-перевод новинок без перевода.
 *
 * Общая точка для MenuPublicController и легаси links/menu.php — какой из
 * них отдаёт страницу, зависит от того, как nginx разрулил URL.
 */
final class MenuFreshness
{
    private const SYNC_NOW_AFTER = 600;
    private const BACKGROUND_AFTER = 60;

    private static bool $scheduled = false;

    public static function beforeRender(): void
    {
        try {
            $sync = new MenuLiveSync(Database::getInstance(), Logger::get());
            $age = $sync->ageSeconds();
            if ($age === null || $age >= self::SYNC_NOW_AFTER) {
                $sync->refreshIfStale(self::SYNC_NOW_AFTER);
            }
        } catch (\Throwable $e) {
            Logger::get()->warning('menu.freshness_before_failed', ['err' => $e->getMessage()]);
        }
    }

    public static function afterResponse(): void
    {
        if (self::$scheduled) {
            return;
        }
        self::$scheduled = true;

        register_shutdown_function(static function (): void {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            ignore_user_abort(true);
            set_time_limit(150);
            $logger = Logger::get();
            try {
                $db = Database::getInstance();
                (new MenuLiveSync($db, $logger))->refreshIfStale(self::BACKGROUND_AFTER);
                (new MenuTranslationService($db, AiBrokerClient::fromConfig(), $logger))->translateMissing();
            } catch (\Throwable $e) {
                $logger->error('menu.freshness_after_failed', ['err' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
            }
        });
    }
}

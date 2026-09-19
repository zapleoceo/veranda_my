<?php
declare(strict_types=1);

namespace App\Infrastructure;

/**
 * DNS-обход для api.telegram.org.
 *
 * С 2026-09-19 резолвер хостинга (127.0.0.1) перестал резолвить
 * api.telegram.org (Poster/GitHub резолвятся), при этом сам Telegram по IP
 * доступен. Все curl-вызовы к Telegram резолвят имя через DNS-over-HTTPS.
 * Файл без зависимостей: подключается и автолоадером, и require_once из
 * легаси-скриптов без composer.
 */
final class TelegramDns
{
    public const DOH_URL = 'https://1.1.1.1/dns-query';

    public static function apply(\CurlHandle $ch): void
    {
        if (defined('CURLOPT_DOH_URL')) {
            curl_setopt($ch, CURLOPT_DOH_URL, self::DOH_URL);
        }
    }

    public static function applyIfTelegram(\CurlHandle $ch, string $url): void
    {
        if (str_contains($url, 'api.telegram.org')) {
            self::apply($ch);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Home\I18n;

/**
 * Языки главной страницы. По умолчанию — язык браузера (en/ru/vi), иначе EN.
 * URL-схема: /home/en/, /home/ru/, /home/vi/ (path — лучший SEO + hreflang).
 */
final class Locale
{
    /** @var string[] Поддерживаемые языки. Порядок = приоритет в hreflang/sitemap. */
    public const SUPPORTED = ['en', 'ru', 'vi'];

    /** Язык по умолчанию, если браузер не на одном из поддерживаемых. */
    public const FALLBACK = 'en';

    public const COOKIE = 'home_lang';

    public static function isSupported(string $lang): bool
    {
        return in_array($lang, self::SUPPORTED, true);
    }

    /** Нормализовать произвольную строку к поддерживаемому коду или вернуть null. */
    public static function normalize(?string $lang): ?string
    {
        $lang = strtolower(trim((string) $lang));

        return self::isSupported($lang) ? $lang : null;
    }

    /**
     * Определить язык для /home/ (без явного кода в URL):
     * cookie выбора → язык браузера (Accept-Language) → FALLBACK.
     */
    public static function detect(): string
    {
        $cookie = self::normalize($_COOKIE[self::COOKIE] ?? null);
        if ($cookie !== null) {
            return $cookie;
        }

        $accept = (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
        foreach (preg_split('/\s*,\s*/', $accept) ?: [] as $part) {
            if ($part === '') {
                continue;
            }
            $code = strtolower(trim(explode(';', $part, 2)[0]));
            $base = explode('-', $code, 2)[0];
            if (self::isSupported($base)) {
                return $base;
            }
        }

        return self::FALLBACK;
    }

    /** Языковой код для атрибута <html lang> (BCP-47). */
    public static function htmlLang(string $lang): string
    {
        return ['en' => 'en', 'ru' => 'ru', 'vi' => 'vi'][$lang] ?? 'en';
    }
}

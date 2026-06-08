<?php

declare(strict_types=1);

namespace App\Home\View;

/**
 * Чистые HTML-билдеры без состояния. Единственное место, где собирается
 * responsive <img> (срсет 700w/1400w + lazy/eager) — раньше это была функция
 * v_img() внутри монолита, теперь переиспользуемый хелпер.
 */
final class Html
{
    /** Экранирование для вывода в HTML. */
    public static function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Responsive WebP-картинка из /assets/img/home/.
     *
     * @param string $name  базовое имя файла без размера и расширения (напр. 'hero-terrace')
     * @param bool   $eager true для hero (LCP) — eager + fetchpriority=high
     * @param string $attrs дополнительные атрибуты как готовая строка (напр. 'class="x"')
     */
    public static function img(
        string $name,
        string $alt,
        string $sizes = '100vw',
        bool $eager = false,
        string $attrs = '',
    ): string {
        $base = '/assets/img/home/' . $name;

        return sprintf(
            '<img src="%1$s-700.webp" srcset="%1$s-700.webp 700w, %1$s-1400.webp 1400w"'
            . ' sizes="%2$s" loading="%3$s" fetchpriority="%4$s" decoding="async" alt="%5$s"%6$s>',
            $base,
            self::e($sizes),
            $eager ? 'eager' : 'lazy',
            $eager ? 'high' : 'auto',
            self::e($alt),
            $attrs !== '' ? ' ' . $attrs : '',
        );
    }
}

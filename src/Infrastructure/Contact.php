<?php

declare(strict_types=1);

namespace App\Infrastructure;

/**
 * Единый источник правды по контактному телефону комплекса.
 *
 * Номер был захардкожен в семи местах (главная, /links, меню, сообщения
 * бронирования) и рассинхронизировался. Теперь литерал ровно один — PHONE,
 * всё остальное (формат для показа, tel:, wa.me) выводится из него.
 *
 * Класс намеренно без зависимостей: файл можно подключить напрямую через
 * require_once там, где composer-автозагрузчик недоступен — например,
 * в AJAX-цепочке tr3/api.php (она подключает классы вручную).
 */
final class Contact
{
    /** Канонический номер ресторана (E.164). ЕДИНСТВЕННЫЙ литерал номера. */
    public const PHONE = '+84396314226';

    public static function phone(): string
    {
        return self::PHONE;
    }

    /** Только цифры — для wa.me и внешних API. */
    public static function digits(): string
    {
        return (string) preg_replace('/\D+/', '', self::PHONE);
    }

    /** Формат для показа в интерфейсе: +84 396 314 226. */
    public static function display(): string
    {
        $digits = self::digits();
        if (str_starts_with($digits, '84') && strlen($digits) === 11) {
            return '+84 ' . implode(' ', str_split(substr($digits, 2), 3));
        }

        return self::PHONE; // незнакомый формат — отдаём как есть
    }

    public static function tel(): string
    {
        return 'tel:' . self::PHONE;
    }

    public static function whatsApp(): string
    {
        return 'https://wa.me/' . self::digits();
    }
}

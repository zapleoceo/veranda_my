<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Легаси-класс App\Classes\Database обязан работать БЕЗ автозагрузчика.
 *
 * Его подключают обычным require_once 12 автономных точек входа, у которых
 * composer-автозагрузчика нет вовсе: cron/menu_sync.php,
 * scripts/reservations/daily_booking_reminder.php, scripts/menu/ko_fill.php,
 * daily_summary.php, auth_check.php, tr3/api_context.php и другие.
 *
 * Реальная поломка: в конструктор добавили вызов
 * \App\Infrastructure\Database::spotTimeZoneOffset(). Под Slim всё работало
 * (автозагрузчик на месте), а в кроне падало «Class not found» уже ПОСЛЕ
 * успешного коннекта — наружу это выглядело как «ошибка БД», и утреннее
 * напоминание о бронях пришло с ошибкой вместо списка.
 *
 * Тест структурный: обычный юнит-тест такую регрессию не поймает, потому что
 * в PHPUnit автозагрузчик всегда загружен.
 */
final class LegacyDatabaseStandaloneTest extends TestCase
{
    private function source(): string
    {
        $path = dirname(__DIR__, 2) . '/src/classes/Database.php';
        $this->assertFileExists($path);
        return (string) file_get_contents($path);
    }

    /**
     * Никаких обращений к классам из App\Infrastructure — они недоступны
     * без автозагрузчика.
     */
    public function test_does_not_depend_on_autoloaded_namespaces(): void
    {
        $src = $this->source();

        $this->assertStringNotContainsString(
            'App\\Infrastructure\\',
            $src,
            'Легаси-класс подключают без автозагрузчика — ссылка на App\\Infrastructure\\* упадёт '
            . 'с «Class not found» уже после коннекта к БД и будет выглядеть как «ошибка БД».'
        );
    }

    /** Смещение таймзоны считается своими силами и в формате для SET time_zone. */
    public function test_offset_helper_is_self_contained_and_valid(): void
    {
        $this->assertTrue(
            method_exists(\App\Classes\Database::class, 'spotTimeZoneOffset'),
            'помощник должен жить в самом легаси-классе'
        );

        $this->assertMatchesRegularExpression(
            '/^[+-]\d{2}:\d{2}$/',
            \App\Classes\Database::spotTimeZoneOffset()
        );
    }

    /** Оба класса подключения обязаны давать одинаковое смещение. */
    public function test_matches_infrastructure_database_offset(): void
    {
        $this->assertSame(
            \App\Infrastructure\Database::spotTimeZoneOffset(),
            \App\Classes\Database::spotTimeZoneOffset(),
            'разъехавшиеся смещения = приложение и часть скриптов пишут время по разным часам'
        );
    }
}

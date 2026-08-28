<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Infrastructure\Database;
use App\Repositories\MetaRepository;
use PHPUnit\Framework\TestCase;

/**
 * Суточные счётчики прогонов для «Сводки синков».
 *
 * Фон: сводка считала прогоны грепом по лог-файлам, ища строки «Starting menu
 * sync», «DONE duration_ms», «Updated sync marker». После перехода сервисов на
 * структурное логирование две последние строки писаться перестали — и отчёт
 * месяцами показывал «yesterday=0» при полностью рабочих кронах. Проверено на
 * проде: telegram.log не обновлялся с 16 мая, маркера «Updated sync marker» в
 * cron.log нет вовсе, при этом оба крона отрабатывали каждые сутки.
 *
 * Счётчик в БД от формата логов и от их ротации не зависит.
 */
final class DailyRunCounterTest extends TestCase
{
    /** Подменяет хранилище на массив в памяти — БД для этих тестов не нужна. */
    private function repo(array &$store): MetaRepository
    {
        $db = $this->createMock(Database::class);

        return new class($db, $store) extends MetaRepository {
            private array $mem;
            public function __construct(Database $db, array &$store)
            {
                parent::__construct($db);
                $this->mem = &$store;
            }
            public function get(string $key, string $default = ''): string
            {
                return $this->mem[$key] ?? $default;
            }
            public function set(string $key, string $value): void
            {
                $this->mem[$key] = $value;
            }
        };
    }

    public function test_counts_runs_per_day(): void
    {
        $store = [];
        $repo  = $this->repo($store);

        foreach (range(1, 5) as $ignored) {
            $repo->bumpDailyRun('kitchen_runs_json', '2026-08-27');
        }

        $this->assertSame(5, $repo->dailyRunCount('kitchen_runs_json', '2026-08-27'));
    }

    public function test_days_are_counted_separately(): void
    {
        $store = [];
        $repo  = $this->repo($store);

        $repo->bumpDailyRun('menu_runs_json', '2026-08-27');
        $repo->bumpDailyRun('menu_runs_json', '2026-08-28');
        $repo->bumpDailyRun('menu_runs_json', '2026-08-28');

        $this->assertSame(1, $repo->dailyRunCount('menu_runs_json', '2026-08-27'));
        $this->assertSame(2, $repo->dailyRunCount('menu_runs_json', '2026-08-28'));
    }

    /** Вчерашнее число обязано пережить сегодняшние прогоны — сводка читает именно его. */
    public function test_yesterday_survives_today_runs(): void
    {
        $store = [];
        $repo  = $this->repo($store);

        foreach (range(1, 288) as $ignored) {
            $repo->bumpDailyRun('kitchen_runs_json', '2026-08-27');
        }
        foreach (range(1, 40) as $ignored) {
            $repo->bumpDailyRun('kitchen_runs_json', '2026-08-28');
        }

        $this->assertSame(288, $repo->dailyRunCount('kitchen_runs_json', '2026-08-27'));
    }

    /** Ключ не должен расти бесконечно: держим окно в несколько суток. */
    public function test_old_days_are_pruned(): void
    {
        $store = [];
        $repo  = $this->repo($store);

        foreach (range(1, 20) as $d) {
            $repo->bumpDailyRun('telegram_runs_json', sprintf('2026-08-%02d', $d), 7);
        }

        $decoded = json_decode($store['telegram_runs_json'], true);
        $this->assertCount(7, $decoded, 'окно должно обрезаться до 7 суток');
        $this->assertArrayHasKey('2026-08-20', $decoded, 'свежие сутки обязаны остаться');
        $this->assertArrayNotHasKey('2026-08-01', $decoded, 'старые сутки должны уйти');
    }

    /** Битое значение в meta не должно ронять крон. */
    public function test_corrupted_value_is_recovered(): void
    {
        $store = ['kitchen_runs_json' => 'не json'];
        $repo  = $this->repo($store);

        $repo->bumpDailyRun('kitchen_runs_json', '2026-08-28');

        $this->assertSame(1, $repo->dailyRunCount('kitchen_runs_json', '2026-08-28'));
    }

    /** Нет данных — ноль, а не ошибка. */
    public function test_missing_key_returns_zero(): void
    {
        $store = [];
        $this->assertSame(0, $this->repo($store)->dailyRunCount('menu_runs_json', '2026-08-27'));
    }
}

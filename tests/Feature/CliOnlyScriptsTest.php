<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Скрипты из scripts/ и cron/ не должны запускаться по HTTP.
 *
 * Эти каталоги лежат ВНУТРИ docroot, а nginx отдаёт .php напрямую в PHP-FPM,
 * минуя .htaccess. Проверено на проде: GET /scripts/kitchen/cron.php вернул
 * 500 (файл исполнился и упал), а несуществующий файл в том же каталоге —
 * 404. То есть каталог не закрыт, и обычной ссылкой можно было запустить:
 *   • cron/kitchen_sync.php                        — полный ресинк Poster
 *   • cron/telegram_alerts.php                     — рассылка в рабочую группу
 *   • scripts/kitchen/resync_range.php             — массовая перезапись kitchen_stats
 *   • scripts/kitchen/backfill_prob_close_at.php   — обнуление колонки
 *   • scripts/kitchen/refresh_poster_closed_...php — NULL по месяцу данных
 *   • scripts/kitchen/cleanup_orphan_alerts.php    — удаление сообщений и строк
 *
 * Тест структурный: он не даёт добавить новый скрипт без guard'а.
 * Правильный барьер — запрет каталога на уровне nginx; guard в коде это
 * второй рубеж, который работает даже если конфиг веб-сервера подменят.
 */
final class CliOnlyScriptsTest extends TestCase
{
    private const GUARDED_DIRS = ['scripts', 'cron'];

    /** Cron-скрипты, лежащие в корне docroot. */
    private const GUARDED_ROOT_FILES = ['daily_summary.php', 'set_tg_webhook.php'];

    /** @return list<string> */
    private function phpFiles(): array
    {
        $root  = dirname(__DIR__, 2);
        $found = [];
        foreach (self::GUARDED_DIRS as $dir) {
            $path = $root . '/' . $dir;
            if (!is_dir($path)) continue;
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));
            foreach ($it as $file) {
                if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                    $found[] = str_replace('\\', '/', $file->getPathname());
                }
            }
        }
        // Cron-скрипты в корне docroot: они отдаются по HTTP наравне с
        // публичными страницами. daily_summary.php по GET запускал
        // exec('pm2 jlist') и слал выдержки из серверных логов в Telegram.
        foreach (self::GUARDED_ROOT_FILES as $name) {
            $path = $root . '/' . $name;
            if (is_file($path)) {
                $found[] = str_replace('\\', '/', $path);
            }
        }

        sort($found);
        return $found;
    }

    public function test_there_are_scripts_to_check(): void
    {
        $this->assertNotEmpty($this->phpFiles(), 'не нашли ни одного скрипта — тест перестал бы что-либо проверять');
    }

    public function test_every_script_refuses_non_cli_execution(): void
    {
        $unguarded = [];
        foreach ($this->phpFiles() as $file) {
            $src = (string) file_get_contents($file);
            $hasGuard = str_contains($src, "PHP_SAPI !== 'cli'")
                     || str_contains($src, 'php_sapi_name() !== \'cli\'');
            if (!$hasGuard) {
                $unguarded[] = basename(dirname($file)) . '/' . basename($file);
            }
        }

        $this->assertSame(
            [],
            $unguarded,
            "Эти скрипты запустятся обычным GET-запросом: " . implode(', ', $unguarded)
        );
    }

    /** Guard обязан стоять ДО подключения автозагрузчика и любой работы. */
    public function test_guard_precedes_any_require(): void
    {
        $late = [];
        foreach ($this->phpFiles() as $file) {
            $src        = (string) file_get_contents($file);
            $guardPos   = strpos($src, "PHP_SAPI !== 'cli'");
            $requirePos = strpos($src, 'require');
            if ($guardPos === false) continue;                 // ловится тестом выше
            if ($requirePos !== false && $requirePos < $guardPos) {
                $late[] = basename(dirname($file)) . '/' . basename($file);
            }
        }

        $this->assertSame([], $late, 'guard стоит после require — часть работы успеет выполниться: ' . implode(', ', $late));
    }
}

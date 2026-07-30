<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Infrastructure\PhpCli;
use PHPUnit\Framework\TestCase;

/**
 * Выбор бинаря PHP для фоновых задач.
 *
 * Фон: RawdataService запускал пересинк через PHP_BINARY. В CLI это верно,
 * но под PHP-FPM PHP_BINARY указывает на /opt/php82/sbin/php-fpm — а он не
 * принимает путь к скрипту: печатает свой usage и выходит с кодом 0. Из-за
 * этого «фоновая задача» месяцами стартовала вхолостую, в лог падала
 * справка php-fpm, и никакой ошибки никто не видел (exit code 0!).
 */
final class PhpCliTest extends TestCase
{
    public function test_never_returns_the_fpm_binary(): void
    {
        $binary = PhpCli::binary();

        $this->assertStringNotContainsString(
            'fpm',
            basename($binary),
            'php-fpm не умеет запускать скрипт — он напечатает usage и молча выйдет с кодом 0'
        );
    }

    public function test_returns_non_empty_path(): void
    {
        $this->assertNotSame('', PhpCli::binary());
    }

    /** В CLI-контексте (так гоняются тесты и крон) PHP_BINARY уже корректен. */
    public function test_uses_php_binary_when_running_under_cli(): void
    {
        if (PHP_SAPI !== 'cli') {
            $this->markTestSkipped('тест осмыслен только под CLI SAPI');
        }

        $this->assertSame(PHP_BINARY, PhpCli::binary());
    }

    /** PHP_BINDIR/php — тот путь, которым воспользуется FPM-ветка. */
    public function test_bindir_php_is_a_real_cli_binary_on_this_host(): void
    {
        $candidate = PHP_BINDIR . '/php';
        if (!@is_executable($candidate) && !@is_executable($candidate . '.exe')) {
            $this->markTestSkipped('в этом окружении нет ' . $candidate);
        }

        $this->assertStringNotContainsString('fpm', basename($candidate));
    }
}

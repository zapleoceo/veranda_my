<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Infrastructure\Config;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset singleton state via reflection
        $ref = new \ReflectionClass(Config::class);

        $data = $ref->getProperty('_data');
        $data->setAccessible(true);
        $data->setValue(null, []);

        $loaded = $ref->getProperty('_loaded');
        $loaded->setAccessible(true);
        $loaded->setValue(null, false);
    }

    public function test_get_returns_default_when_key_missing(): void
    {
        $this->assertSame('fallback', Config::get('NON_EXISTENT', 'fallback'));
    }

    public function test_load_parses_env_file(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'env_');
        file_put_contents($tmp, "APP_NAME=Veranda\nDEBUG=true\n# comment\n");

        Config::load($tmp);

        $this->assertSame('Veranda', Config::get('APP_NAME'));
        $this->assertSame('true', Config::get('DEBUG'));

        unlink($tmp);
    }

    public function test_load_skips_comments_and_empty_lines(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'env_');
        file_put_contents($tmp, "\n# this is a comment\nKEY=value\n");

        Config::load($tmp);

        $this->assertSame('value', Config::get('KEY'));

        unlink($tmp);
    }

    public function test_bool_returns_true_for_truthy_values(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'env_');
        file_put_contents($tmp, "FLAG_TRUE=true\nFLAG_ONE=1\nFLAG_YES=yes\n");

        Config::load($tmp);

        $this->assertTrue(Config::bool('FLAG_TRUE'));
        $this->assertTrue(Config::bool('FLAG_ONE'));
        $this->assertTrue(Config::bool('FLAG_YES'));

        unlink($tmp);
    }

    public function test_require_throws_when_key_missing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/MISSING_KEY/');

        Config::require('MISSING_KEY');
    }

    public function test_int_returns_integer(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'env_');
        file_put_contents($tmp, "PORT=8080\n");

        Config::load($tmp);

        $this->assertSame(8080, Config::int('PORT'));

        unlink($tmp);
    }
}

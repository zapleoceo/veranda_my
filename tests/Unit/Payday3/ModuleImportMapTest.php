<?php

declare(strict_types=1);

namespace Tests\Unit\Payday3;

use App\Payday3\Domain\DateRange;
use App\Payday3\Http\ModuleImportMap;
use PHPUnit\Framework\TestCase;

/**
 * Import map that versions payday3's static ES-module imports
 * (replacement for the top-level-await cacheBust cascade that hung iOS).
 */
final class ModuleImportMapTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pd3map_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/ui/modal', 0777, true);
        file_put_contents($this->dir . '/index.js', '');
        file_put_contents($this->dir . '/ui/a.js', '');
        file_put_contents($this->dir . '/ui/modal/host.js', '');
        file_put_contents($this->dir . '/ui/readme.txt', '');
        touch($this->dir . '/ui/a.js', 1700000000);
    }

    protected function tearDown(): void
    {
        foreach (['/ui/modal/host.js', '/ui/a.js', '/ui/readme.txt', '/index.js'] as $f) @unlink($this->dir . $f);
        @rmdir($this->dir . '/ui/modal');
        @rmdir($this->dir . '/ui');
        @rmdir($this->dir);
    }

    public function test_keys_absolute_without_query_values_versioned_nested_dirs(): void
    {
        $map = ModuleImportMap::imports($this->dir);

        $this->assertSame([
            '/payday3/assets/js/index.js',
            '/payday3/assets/js/ui/a.js',
            '/payday3/assets/js/ui/modal/host.js',
        ], array_keys($map), 'only .js, nested dirs, forward slashes, sorted');

        foreach ($map as $key => $value) {
            $this->assertStringStartsWith('/payday3/assets/js/', $key);
            $this->assertStringNotContainsString('?', $key);
            $this->assertMatchesRegularExpression('#^' . preg_quote($key, '#') . '\?v=\d+$#', $value);
        }
        $this->assertSame('/payday3/assets/js/ui/a.js?v=1700000000', $map['/payday3/assets/js/ui/a.js']);
    }

    public function test_json_is_valid_import_map(): void
    {
        $json = ModuleImportMap::json($this->dir);
        $this->assertStringNotContainsString('\/', $json, 'unescaped slashes');
        $this->assertStringNotContainsString('<', $json);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['imports'], array_keys($decoded));
        $this->assertSame(ModuleImportMap::imports($this->dir), $decoded['imports']);
    }

    public function test_real_tree_covers_every_module(): void
    {
        $root = __DIR__ . '/../../../payday3/assets/js';
        $map  = ModuleImportMap::imports();
        $this->assertArrayHasKey('/payday3/assets/js/index.js', $map);
        $this->assertArrayHasKey('/payday3/assets/js/ui/modal/host.js', $map);
        $this->assertArrayNotHasKey('/payday3/assets/js/ui/cacheBust.js', $map);
        $count = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) if ($f->getExtension() === 'js') $count++;
        $this->assertCount($count, $map);
    }

    public function test_view_emits_one_importmap_before_the_module_script(): void
    {
        $range            = DateRange::of('2026-09-18', '2026-09-18');
        $sepayOpen        = [];
        $sepayHidden      = [];
        $poster           = [];
        $links            = [];
        $linksJson        = [];
        $linkBySepay      = [];
        $linkByPoster     = [];
        $rowStateBySepay  = [];
        $rowStateByPoster = [];
        ob_start();
        // content.php's partials declare constants; SinglePageLayoutTest
        // may already have rendered it in this process.
        @require __DIR__ . '/../../../src/Views/payday3/content.php';
        $html = (string)ob_get_clean();

        $this->assertSame(1, substr_count($html, '<script type="importmap">'));
        $map    = strpos($html, '<script type="importmap">');
        $module = strpos($html, '<script type="module"');
        $this->assertNotFalse($module);
        $this->assertLessThan($module, $map, 'import map must precede the module script');
        $this->assertSame(1, substr_count($html, '<script type="module"'));
        $this->assertMatchesRegularExpression('#<script type="module" src="/payday3/assets/js/index\.js\?v=\d+">#', $html);

        preg_match('#<script type="importmap">(.*?)</script>#s', $html, $m);
        $decoded = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('/payday3/assets/js/api.js', $decoded['imports']);
    }
}

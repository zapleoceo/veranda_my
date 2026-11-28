<?php

declare(strict_types=1);

namespace Tests\Unit\Payday3;

use App\Payday3\Domain\LocalSettings;
use App\Payday3\Services\BalanceShotStore;
use App\Payday3\Services\LocalSettingsCodec;
use PHPUnit\Framework\TestCase;

final class BalanceShotStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pd3_shots_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            foreach (scandir($this->dir) ?: [] as $f) {
                if ($f !== '.' && $f !== '..') @unlink($this->dir . DIRECTORY_SEPARATOR . $f);
            }
            @rmdir($this->dir);
        }
    }

    public function test_save_keeps_every_shot_and_latest_is_the_newest(): void
    {
        $store = new BalanceShotStore($this->dir);
        $a = $store->save('png-one', 'image/png');
        $b = $store->save('jpg-two', 'image/jpeg');

        $list = $store->list();
        $this->assertCount(2, $list);
        $this->assertNotSame($a['name'], $b['name']);
        $this->assertSame($list[0]['name'], $store->latest()['name']);
        $this->assertSame($b['name'], $store->latest()['name']);
        $this->assertSame('png-one', $store->read($a['name']));
        $this->assertSame('jpg-two', $store->read($b['name']));
    }

    public function test_read_rejects_path_tricks_and_unknown_names(): void
    {
        $store = new BalanceShotStore($this->dir);
        $store->save('x', 'image/png');
        $this->assertNull($store->read('../local_config.json'));
        $this->assertNull($store->read('..\\..\\secret.png'));
        $this->assertNull($store->read('20260101_000000_abcdef.png'));
    }

    public function test_legacy_shots_are_still_listed_and_readable(): void
    {
        $store = new BalanceShotStore($this->dir);
        $new = $store->save('new', 'image/jpeg');
        $legacy = '20200101_000000_abcdef.png';
        file_put_contents($this->dir . DIRECTORY_SEPARATOR . $legacy, 'old');
        $this->assertSame([$new['name'], $legacy], array_column($store->list(), 'name'));
        $this->assertSame('old', $store->read($legacy));
    }

    public function test_empty_store_has_no_latest(): void
    {
        $store = new BalanceShotStore($this->dir);
        $this->assertNull($store->latest());
        $this->assertSame([], $store->list());
    }

    public function test_daily_marker_prevents_a_second_send_on_the_same_day(): void
    {
        $store = new BalanceShotStore($this->dir);
        $store->save('x', 'image/png');
        $this->assertFalse($store->dailySent('20260101'));
        $store->markDailySent('20260101');
        $this->assertTrue($store->dailySent('20260101'));
        $this->assertFalse($store->dailySent('20260102'));
        // Marker files are not shots and do not show up in the gallery.
        $this->assertCount(1, $store->list());
    }
}

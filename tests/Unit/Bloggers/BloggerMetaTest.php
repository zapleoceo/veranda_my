<?php

declare(strict_types=1);

namespace Tests\Unit\Bloggers;

use App\Bloggers\Support\BloggerMeta;
use PHPUnit\Framework\TestCase;

class BloggerMetaTest extends TestCase
{
    public function test_decode_bare_name(): void
    {
        $m = BloggerMeta::decode('Anna Ivanova');
        $this->assertSame('Anna Ivanova', $m->name);
        $this->assertNull($m->cashbackPct);
        $this->assertSame(15.0, $m->limitPct);
        $this->assertSame([], $m->socials);
    }

    public function test_decode_full_with_social_list(): void
    {
        $m = BloggerMeta::decode('Anna | cb=7 | lim=12 | s=ig:@a | s=ig:@second | s=fb:fb.com/anna');
        $this->assertSame('Anna', $m->name);
        $this->assertSame(7.0, $m->cashbackPct);
        $this->assertSame(12.0, $m->limitPct);
        $this->assertCount(3, $m->socials);
        $this->assertSame(['net' => 'ig', 'val' => '@a'], $m->socials[0]);
        $this->assertSame(['net' => 'ig', 'val' => '@second'], $m->socials[1]); // duplicates allowed
        $this->assertSame(['net' => 'fb', 'val' => 'fb.com/anna'], $m->socials[2]);
    }

    public function test_social_value_may_contain_colon(): void
    {
        $m = BloggerMeta::decode('Anna | s=yt:https://youtube.com/@a');
        $this->assertSame(['net' => 'yt', 'val' => 'https://youtube.com/@a'], $m->socials[0]);
    }

    public function test_decode_tolerates_legacy_socials(): void
    {
        $m = BloggerMeta::decode('Anna | ig=@a | TG: @b | tt=@c');
        $nets = array_column($m->socials, 'net');
        $this->assertContains('ig', $nets);
        $this->assertContains('tg', $nets);
        $this->assertContains('tt', $nets);
        $this->assertSame(15.0, $m->limitPct);
    }

    public function test_encode_format(): void
    {
        $m = new BloggerMeta('Anna', 7.0, 12.0, [
            ['net' => 'ig', 'val' => '@a'],
            ['net' => 'vk', 'val' => 'vk.com/anna'],
        ]);
        $this->assertSame('Anna | cb=7 | lim=12 | s=ig:@a | s=vk:vk.com/anna', $m->encode());
    }

    public function test_encode_replaces_pipe_in_name(): void
    {
        $m = new BloggerMeta('A | B', 0.0, 15.0);
        $this->assertStringStartsWith('A / B | ', $m->encode());
    }

    public function test_roundtrip_preserves_fields(): void
    {
        $src = new BloggerMeta('Имя', 5.5, 18.0, [
            ['net' => 'ig', 'val' => '@x'],
            ['net' => 'tg', 'val' => '@y'],
        ]);
        $out = BloggerMeta::decode($src->encode());
        $this->assertSame('Имя', $out->name);
        $this->assertSame(5.5, $out->cashbackPct);
        $this->assertSame(18.0, $out->limitPct);
        $this->assertSame($src->socials, $out->socials);
    }

    public function test_cashbackOr_uses_fallback_only_when_absent(): void
    {
        $this->assertSame(9.0, BloggerMeta::decode('Anna')->cashbackOr(9.0));
        $this->assertSame(7.0, BloggerMeta::decode('Anna | cb=7')->cashbackOr(9.0));
    }
}

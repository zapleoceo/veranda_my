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
        $this->assertSame('', $m->socials['ig']);
    }

    public function test_decode_full(): void
    {
        $m = BloggerMeta::decode('Anna | cb=7 | lim=12 | ig=@a | tg=@b | tt=@c | yt=youtube.com/@a');
        $this->assertSame('Anna', $m->name);
        $this->assertSame(7.0, $m->cashbackPct);
        $this->assertSame(12.0, $m->limitPct);
        $this->assertSame('@a', $m->socials['ig']);
        $this->assertSame('@b', $m->socials['tg']);
        $this->assertSame('@c', $m->socials['tt']);
        $this->assertSame('youtube.com/@a', $m->socials['yt']);
    }

    public function test_decode_tolerates_legacy_socials(): void
    {
        $m = BloggerMeta::decode('Anna | IG: @a | TG: @b');
        $this->assertSame('Anna', $m->name);
        $this->assertSame('@a', $m->socials['ig']);
        $this->assertSame('@b', $m->socials['tg']);
        $this->assertNull($m->cashbackPct);
        $this->assertSame(15.0, $m->limitPct);
    }

    public function test_encode_format_and_omits_empty_socials(): void
    {
        $m = new BloggerMeta('Anna', 7.0, 12.0, ['ig' => '@a', 'tg' => '', 'tt' => '', 'yt' => '']);
        $this->assertSame('Anna | cb=7 | lim=12 | ig=@a', $m->encode());
    }

    public function test_encode_replaces_pipe_in_name(): void
    {
        $m = new BloggerMeta('A | B', 0.0, 15.0);
        $this->assertStringStartsWith('A / B | ', $m->encode());
    }

    public function test_roundtrip_preserves_fields(): void
    {
        $src = new BloggerMeta('Имя', 5.5, 18.0, ['ig' => '@x', 'tg' => '@y', 'tt' => '', 'yt' => '']);
        $out = BloggerMeta::decode($src->encode());
        $this->assertSame('Имя', $out->name);
        $this->assertSame(5.5, $out->cashbackPct);
        $this->assertSame(18.0, $out->limitPct);
        $this->assertSame('@x', $out->socials['ig']);
        $this->assertSame('@y', $out->socials['tg']);
        $this->assertSame('', $out->socials['tt']);
    }

    public function test_cashbackOr_uses_fallback_only_when_absent(): void
    {
        $this->assertSame(9.0, BloggerMeta::decode('Anna')->cashbackOr(9.0));
        $this->assertSame(7.0, BloggerMeta::decode('Anna | cb=7')->cashbackOr(9.0));
    }
}

<?php

declare(strict_types=1);

namespace App\Bloggers\Support;

/**
 * Encodes/decodes the structured influencer-parameter block stored inside the
 * Poster client `comment` field. Poster has no custom fields, so every
 * parameter without a native column lives here. (Discount is the exception —
 * it stays in Poster `discount_per`, read by the POS at checkout.)
 *
 * Wire format — one line, segments joined by " | ":
 *
 *     Имя | cb=7 | lim=15 | s=ig:@anna | s=tg:@anna | s=ig:@second_acc
 *
 *   - first segment   = display name (free text)
 *   - cb=<n>          = cashback %                         (float)
 *   - lim=<n>         = limit %: max of discount+cashback the blogger may set
 *   - s=<net>:<value> = a social entry, REPEATABLE (multiple per network OK)
 *
 * Tolerant of the older fixed-key form (`ig=@h | tg=@h`) and the oldest
 * label form (`IG: @h`) so existing comments keep working. Socials are a
 * LIST of {net,val} — ready for a per-entry `verified` flag later.
 */
final class BloggerMeta
{
    public const DEFAULT_LIMIT = 15.0;

    /** @param list<array{net:string,val:string}> $socials */
    public function __construct(
        public string $name = '',
        public ?float $cashbackPct = null,
        public float $limitPct = self::DEFAULT_LIMIT,
        public array $socials = [],
    ) {}

    public static function decode(string $comment): self
    {
        $segments = array_map('trim', explode(' | ', trim($comment)));
        $name     = (string) array_shift($segments);
        $meta     = new self(name: $name);

        foreach ($segments as $seg) {
            if ($seg === '') {
                continue;
            }
            // New repeatable social: s=<net>:<value>  (value may contain ':')
            if (preg_match('/^s=([a-z0-9]+):(.*)$/i', $seg, $m)) {
                $meta->addSocial($m[1], $m[2]);
                continue;
            }
            // Numeric params.
            if (preg_match('/^(cb|lim)=(.*)$/i', $seg, $m)) {
                if (strtolower($m[1]) === 'cb') {
                    $meta->cashbackPct = self::clampPct((float) $m[2]);
                } else {
                    $meta->limitPct = self::clampPct((float) $m[2]);
                }
                continue;
            }
            // Legacy fixed-key socials: ig=@h | tg=@h | tt=@h | yt=link
            if (preg_match('/^(ig|tg|tt|yt)=(.*)$/i', $seg, $m)) {
                $meta->addSocial($m[1], $m[2]);
                continue;
            }
            // Oldest label socials: "IG: @h", "TikTok: @h", "YT: link"
            if (preg_match('/^(IG|TG|TikTok|YT):\s*(.+)$/i', $seg, $m)) {
                $label = strtolower($m[1]);
                $net   = $label === 'tiktok' ? 'tt' : $label;
                $meta->addSocial($net, $m[2]);
            }
            // Anything else → ignored (keeps the name clean).
        }

        return $meta;
    }

    public function encode(): string
    {
        $parts   = [self::clean($this->name)];
        $parts[] = 'cb='  . self::fmt($this->cashbackPct ?? 0.0);
        $parts[] = 'lim=' . self::fmt($this->limitPct);
        foreach ($this->socials as $s) {
            $net = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) ($s['net'] ?? '')));
            $val = self::clean((string) ($s['val'] ?? ''));
            if ($net !== '' && $val !== '') {
                $parts[] = 's=' . $net . ':' . $val;
            }
        }
        return implode(' | ', $parts);
    }

    public function cashbackOr(float $fallback): float
    {
        return $this->cashbackPct ?? $fallback;
    }

    private function addSocial(string $net, string $val): void
    {
        $net = strtolower(preg_replace('/[^a-z0-9]/i', '', $net));
        $val = trim($val);
        if ($net !== '' && $val !== '') {
            $this->socials[] = ['net' => $net, 'val' => $val];
        }
    }

    private static function clampPct(float $p): float
    {
        return max(0.0, min(100.0, round($p, 2)));
    }

    /** Strip emoji (utf8mb3) and the " | " delimiter char from free text. */
    private static function clean(string $s): string
    {
        return trim(str_replace('|', '/', PosterText::safe($s)));
    }

    /** Compact percent: 15.00 → "15", 7.50 → "7.5", 0 → "0". */
    private static function fmt(float $p): string
    {
        $s = rtrim(rtrim(number_format($p, 2, '.', ''), '0'), '.');
        return $s === '' ? '0' : $s;
    }
}

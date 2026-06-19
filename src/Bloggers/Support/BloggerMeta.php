<?php

declare(strict_types=1);

namespace App\Bloggers\Support;

/**
 * Encodes/decodes the structured blogger-parameter block stored inside the
 * Poster client `comment` field. Poster has no custom fields, so every
 * parameter that has no native Poster column lives here. (Discount is the one
 * exception — it stays in Poster's `discount_per` because the POS reads it to
 * apply the discount at checkout.)
 *
 * Wire format — one line, segments joined by " | ":
 *
 *     Имя | cb=7 | lim=15 | ig=@anna | tg=@anna | tt=@anna | yt=youtube.com/@a
 *
 *   - first segment   = display name (free text)
 *   - cb=<n>          = cashback %                      (float)
 *   - lim=<n>         = limit %: max of discount+cashback the blogger may set
 *                       (default 15; self-registration starts at 5)
 *   - ig/tg/tt/yt=<v> = social handles/links (empty ones omitted)
 *
 * Tolerant of the older "Имя | IG: @h | TG: @h" format and of a bare "Имя".
 */
final class BloggerMeta
{
    public const DEFAULT_LIMIT = 15.0;

    /** @var list<string> */
    public const SOCIAL_KEYS = ['ig', 'tg', 'tt', 'yt'];

    /**
     * @param array<string,string> $socials key (ig|tg|tt|yt) => handle/link
     */
    public function __construct(
        public string $name = '',
        public ?float $cashbackPct = null,
        public float $limitPct = self::DEFAULT_LIMIT,
        public array $socials = ['ig' => '', 'tg' => '', 'tt' => '', 'yt' => ''],
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
            // New format: key=value
            if (preg_match('/^([a-z]{2,3})=(.*)$/i', $seg, $m)) {
                $key = strtolower($m[1]);
                $val = trim($m[2]);
                if ($key === 'cb') {
                    $meta->cashbackPct = self::clampPct((float) $val);
                } elseif ($key === 'lim') {
                    $meta->limitPct = self::clampPct((float) $val);
                } elseif (in_array($key, self::SOCIAL_KEYS, true)) {
                    $meta->socials[$key] = $val;
                }
                continue;
            }
            // Legacy format: "IG: @h", "TikTok: @h", "YT: link"
            if (preg_match('/^(IG|TG|TikTok|YT):\s*(.+)$/i', $seg, $m)) {
                $label = strtolower($m[1]);
                $key   = $label === 'tiktok' ? 'tt' : $label;
                if (in_array($key, self::SOCIAL_KEYS, true)) {
                    $meta->socials[$key] = trim($m[2]);
                }
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
        foreach (self::SOCIAL_KEYS as $k) {
            $v = self::clean((string) ($this->socials[$k] ?? ''));
            if ($v !== '') {
                $parts[] = $k . '=' . $v;
            }
        }
        return implode(' | ', $parts);
    }

    /** Cashback from the comment, or $fallback when the comment carries none. */
    public function cashbackOr(float $fallback): float
    {
        return $this->cashbackPct ?? $fallback;
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

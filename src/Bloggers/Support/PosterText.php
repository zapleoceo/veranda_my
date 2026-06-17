<?php

declare(strict_types=1);

namespace App\Bloggers\Support;

final class PosterText
{
    /**
     * Strip 4-byte UTF-8 (emoji / ZWJ / variation selectors) before sending to
     * Poster — its client fields are utf8mb3 and a 4-byte char NULLs the whole
     * value. Same hazard handled in the /onlineorder module.
     */
    public static function safe(string $s): string
    {
        return trim((string) preg_replace('/[\x{10000}-\x{10FFFF}\x{FE0F}\x{200D}]/u', '', $s));
    }
}

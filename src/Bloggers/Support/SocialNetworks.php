<?php

declare(strict_types=1);

namespace App\Bloggers\Support;

/**
 * Catalogue of social networks an influencer can list. Codes are short,
 * lowercase, `[a-z0-9]+` (they go into the Poster comment as `s=<code>:value`).
 * Labels are brand names (locale-independent); the generic "other/site" label
 * is localised in the view. Ordered roughly by global + RU/CIS popularity.
 *
 * Verification is not built yet, but the data model (a list of {net,val}) is
 * ready for a per-entry verified flag later.
 */
final class SocialNetworks
{
    /** @var array<string,string> code => brand label */
    public const NETWORKS = [
        'ig'   => 'Instagram',
        'tt'   => 'TikTok',
        'yt'   => 'YouTube',
        'tg'   => 'Telegram',
        'fb'   => 'Facebook',
        'vk'   => 'VK',
        'x'    => 'X (Twitter)',
        'th'   => 'Threads',
        'ok'   => 'Odnoklassniki',
        'dzen' => 'Dzen',
        'rt'   => 'RuTube',
        'tw'   => 'Twitch',
        'sc'   => 'Snapchat',
        'pin'  => 'Pinterest',
        'wa'   => 'WhatsApp',
        'site' => 'site', // generic — label localised via BloggerLang('social.site')
    ];

    public static function isValid(string $code): bool
    {
        return isset(self::NETWORKS[strtolower($code)]);
    }

    /** Brand label, or the code itself if unknown. */
    public static function label(string $code): string
    {
        return self::NETWORKS[strtolower($code)] ?? $code;
    }

    /** @return array<string,string> the full ordered catalogue */
    public static function all(): array
    {
        return self::NETWORKS;
    }
}

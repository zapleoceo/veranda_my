<?php
declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Infrastructure\TelegramDns;
use PHPUnit\Framework\TestCase;

final class TelegramDnsTest extends TestCase
{
    public function test_apply_accepts_curl_handle(): void
    {
        $ch = curl_init('https://api.telegram.org/');
        TelegramDns::apply($ch);
        TelegramDns::applyIfTelegram($ch, 'https://api.telegram.org/botX/getMe');
        TelegramDns::applyIfTelegram($ch, 'https://joinposter.com/api');
        $this->assertSame('https://1.1.1.1/dns-query', TelegramDns::DOH_URL);
    }

    /** Every file that talks to api.telegram.org via raw curl must resolve through DoH. */
    public function test_all_raw_telegram_curl_sites_use_doh(): void
    {
        $root = dirname(__DIR__, 3);
        foreach ([
            'src/classes/TelegramBot.php', 'daily_summary.php', 'tr3/api_booking.php',
            'tr3/api_states.php', 'src/Payday3/Services/TelegramNotifier.php',
            'src/Services/ReservationMessagingService.php',
        ] as $f) {
            $src = (string)file_get_contents($root . '/' . $f);
            $this->assertGreaterThanOrEqual(
                substr_count($src, 'CURLOPT_URL, "https://api.telegram.org') + substr_count($src, 'curl_init("https://api.telegram.org'),
                substr_count($src, 'TelegramDns::apply($ch)'),
                $f
            );
            $this->assertStringContainsString('TelegramDns::apply($ch)', $src, $f);
        }
    }
}

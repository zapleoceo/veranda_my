<?php

declare(strict_types=1);

namespace Tests\Unit\Payday3;

use App\Payday3\Domain\LocalSettings;
use App\Payday3\Services\LocalSettingsCodec;
use PHPUnit\Framework\TestCase;

final class DailyShotSettingsTest extends TestCase
{
    public function test_defaults_point_daily_send_at_the_second_group_topic(): void
    {
        $d = LocalSettings::defaults();
        $this->assertSame('-1002884053196', $d->dailyChatId);
        $this->assertSame('3653', $d->dailyThreadId);
    }

    public function test_t_me_link_is_normalised_for_the_daily_group(): void
    {
        $canon = LocalSettingsCodec::toCanonicalArray([
            'daily_telegram_chat_id' => 'https://t.me/c/2884053196/3653/13099',
            'daily_telegram_message_thread_id' => '3653',
        ]);
        $this->assertSame('-1002884053196', $canon['daily_telegram_chat_id']);
    }

    public function test_empty_saved_value_disables_daily_send_and_is_not_replaced_by_default(): void
    {
        $s = LocalSettingsCodec::fromArray([
            'daily_telegram_chat_id' => '',
            'daily_telegram_message_thread_id' => '',
        ]);
        $this->assertSame('', $s->dailyChatId);
    }

    public function test_missing_key_falls_back_to_default(): void
    {
        $s = LocalSettingsCodec::fromArray([]);
        $this->assertSame('-1002884053196', $s->dailyChatId);
    }

    public function test_client_payload_exposes_daily_fields(): void
    {
        $p = LocalSettings::defaults()->toClientPayload();
        $this->assertSame('-1002884053196', $p['daily_telegram_chat_id']);
        $this->assertSame('3653', $p['daily_telegram_message_thread_id']);
    }
}

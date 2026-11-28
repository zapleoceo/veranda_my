<?php

declare(strict_types=1);

namespace Tests\Unit\Payday3;

use App\Payday3\Contracts\LocalSettingsRepositoryInterface;
use App\Payday3\Domain\LocalSettings;
use App\Payday3\Services\TelegramNotifier;
use PHPUnit\Framework\TestCase;

/**
 * Куда уходит снимок: явный чат — со своей темой (или без неё),
 * настройки — только когда чат не передан. Раньше null-тема второй группы
 * подменялась темой первого чата.
 */
final class TelegramNotifierTargetTest extends TestCase
{
    /** @return array{0:string,1:?string} */
    private function resolve(?string $chatId, ?string $threadId): array
    {
        $settings = new class implements LocalSettingsRepositoryInterface {
            public function load(): LocalSettings { return LocalSettings::defaults(); }
            public function save(array $payload): array { return ['ok' => true]; }
        };
        $notifier = new TelegramNotifier($settings, 'token');
        $m = new \ReflectionMethod($notifier, 'resolveTarget');
        /** @var array{0:string,1:?string} $out */
        $out = $m->invoke($notifier, $chatId, $threadId);
        return $out;
    }

    public function test_explicit_chat_with_null_topic_keeps_no_topic(): void
    {
        $defaults = LocalSettings::defaults();
        [$chat, $thread] = $this->resolve('-1002884053196', null);

        $this->assertSame('-1002884053196', $chat);
        $this->assertNull($thread, 'пустая тема второй группы не должна брать тему первого чата');
        $this->assertNotSame($defaults->telegramThreadId, $thread);
    }

    public function test_explicit_chat_and_topic_are_used_as_given(): void
    {
        $this->assertSame(['-1002884053196', '3653'], $this->resolve('-1002884053196', '3653'));
    }

    public function test_no_chat_falls_back_to_settings_for_both(): void
    {
        $defaults = LocalSettings::defaults();
        [$chat, $thread] = $this->resolve(null, null);

        $this->assertSame($defaults->telegramChatId, $chat);
        $this->assertSame($defaults->telegramThreadId, $thread);
    }

    public function test_no_chat_but_explicit_topic_uses_settings_chat(): void
    {
        $defaults = LocalSettings::defaults();
        [$chat, $thread] = $this->resolve(null, '42');

        $this->assertSame($defaults->telegramChatId, $chat);
        $this->assertSame('42', $thread);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Infrastructure\HttpClient;
use App\Infrastructure\TelegramBotClient;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class TelegramBotClientTest extends TestCase
{
    private HttpClient&MockObject $http;
    private TelegramBotClient $bot;

    protected function setUp(): void
    {
        $this->http = $this->createMock(HttpClient::class);
        $this->bot  = new TelegramBotClient('test_token', $this->http, '1234567890');
    }

    /**
     * Окно статус-сообщений забывает id по этому ответу: false = «удалять больше
     * нечего». Перепутать false и null — значит либо снова долбить удалённое
     * (баг 2026-09-19…10-03), либо потерять сообщение, которое ещё можно удалить.
     */
    public function test_tryDeleteMessage_distinguishes_outcomes(): void
    {
        $this->http->method('postJson')->willReturnOnConsecutiveCalls(
            ['ok' => true, 'result' => true],
            ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: message to delete not found'],
            null,
            ['ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests'],
            ['ok' => false, 'error_code' => 502, 'description' => 'Bad Gateway'],
        );

        $this->assertTrue($this->bot->tryDeleteMessage(1));
        $this->assertFalse($this->bot->tryDeleteMessage(2), 'сообщения нет — повторять бессмысленно');
        $this->assertNull($this->bot->tryDeleteMessage(3), 'сеть — исход неизвестен');
        $this->assertNull($this->bot->tryDeleteMessage(4), '429 — повторить позже');
        $this->assertNull($this->bot->tryDeleteMessage(5), '5xx — повторить позже');
    }

    public function test_sendMessage_returns_true_on_ok_response(): void
    {
        $this->http->expects($this->once())
            ->method('postJson')
            ->willReturn(['ok' => true, 'result' => ['message_id' => 42]]);

        $this->assertTrue($this->bot->sendMessage('Hello'));
    }

    public function test_sendMessage_returns_false_on_error_response(): void
    {
        $this->http->expects($this->once())
            ->method('postJson')
            ->willReturn(['ok' => false, 'description' => 'Bad Request']);

        $this->assertFalse($this->bot->sendMessage('Hello'));
    }

    public function test_sendMessage_returns_false_when_http_fails(): void
    {
        $this->http->expects($this->once())
            ->method('postJson')
            ->willReturn(null);

        $this->assertFalse($this->bot->sendMessage('Hello'));
    }

    public function test_sendMessageGetId_returns_message_id(): void
    {
        $this->http->expects($this->once())
            ->method('postJson')
            ->willReturn(['ok' => true, 'result' => ['message_id' => 99]]);

        $this->assertSame(99, $this->bot->sendMessageGetId('Hello'));
    }

    public function test_sendMessageGetId_returns_null_on_failure(): void
    {
        $this->http->expects($this->once())
            ->method('postJson')
            ->willReturn(null);

        $this->assertNull($this->bot->sendMessageGetId('Hello'));
    }

    public function test_deleteMessage_calls_correct_api_method(): void
    {
        $this->http->expects($this->once())
            ->method('postJson')
            ->with($this->stringContains('deleteMessage'), $this->anything())
            ->willReturn(['ok' => true]);

        $this->assertTrue($this->bot->deleteMessage(42));
    }

    /**
     * Положительный chat_id — это ЛИЧНЫЙ чат (user id), и он должен уходить
     * в Telegram как есть.
     *
     * Тест раньше требовал обратного: что 1234567890 превратится в
     * -1001234567890. Такое «дополнение до супергруппы» в коде было и его
     * намеренно убрали — оно ломало каждый ответ в личку (user id 169510539
     * становился -100169510539 → «Bad Request: chat not found»). Тест остался
     * от старого поведения и с тех пор просто падал, поэтому фиксируем
     * фактический контракт: caller передаёт канонический id, клиент его не
     * переизобретает.
     */
    public function test_positive_chat_id_is_passed_through_for_private_chats(): void
    {
        $bot = new TelegramBotClient('tok', $this->http, '1234567890');
        $this->http->expects($this->once())
            ->method('postJson')
            ->with($this->anything(), $this->callback(function (array $params): bool {
                return $params['chat_id'] === '1234567890';
            }))
            ->willReturn(['ok' => true]);

        $bot->sendMessage('test');
    }

    /** Пробелы по краям срезаются — единственная нормализация, которая осталась. */
    public function test_chat_id_is_trimmed(): void
    {
        $bot = new TelegramBotClient('tok', $this->http, "  -1001234567890\n");
        $this->http->expects($this->once())
            ->method('postJson')
            ->with($this->anything(), $this->callback(function (array $params): bool {
                return $params['chat_id'] === '-1001234567890';
            }))
            ->willReturn(['ok' => true]);

        $bot->sendMessage('test');
    }

    public function test_chat_id_leaves_negative_id_unchanged(): void
    {
        $bot = new TelegramBotClient('tok', $this->http, '-1001234567890');
        $this->http->expects($this->once())
            ->method('postJson')
            ->with($this->anything(), $this->callback(function (array $params): bool {
                return $params['chat_id'] === '-1001234567890';
            }))
            ->willReturn(['ok' => true]);

        $bot->sendMessage('test');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Middleware\WebhookSecretMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Защита Telegram-вебхука.
 *
 * Почему это важно: авторизация действий внутри WebhookController идёт по
 * username из ТЕЛА запроса. Значит любой, кто может достучаться до вебхука,
 * присылает callback «от имени» админа и жмёт vposter / vdecline / vrestore
 * и игноры. Единственное, что стоит между этим и интернетом — секрет.
 *
 * Раньше при пустом TELEGRAM_WEBHOOK_SECRET middleware пропускал ВСЁ
 * («для dev/testing»), то есть пропажа ключа из .env тихо открывала эндпоинт.
 */
final class WebhookSecretMiddlewareTest extends TestCase
{
    private const SECRET = 'a-very-secret-token-36-chars-long-xx';

    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public bool $called = false;
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->called = true;
                return new Response(200);
            }
        };
    }

    private function middleware(): WebhookSecretMiddleware
    {
        return new WebhookSecretMiddleware(new ResponseFactory());
    }

    private function request(string $query = ''): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/telegram_webhook' . ($query !== '' ? '?' . $query : ''));
    }

    protected function setUp(): void
    {
        // Config::get() падает на $_ENV, когда load() не вызывался.
        $_ENV['TELEGRAM_WEBHOOK_SECRET'] = self::SECRET;
    }

    protected function tearDown(): void
    {
        unset($_ENV['TELEGRAM_WEBHOOK_SECRET']);
    }

    public function test_correct_secret_header_passes(): void
    {
        $handler = $this->handler();
        $request = $this->request()->withHeader('X-Telegram-Bot-Api-Secret-Token', self::SECRET);

        $response = $this->middleware()->process($request, $handler);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($handler->called);
    }

    public function test_wrong_secret_is_rejected(): void
    {
        $handler = $this->handler();
        $request = $this->request()->withHeader('X-Telegram-Bot-Api-Secret-Token', 'wrong');

        $response = $this->middleware()->process($request, $handler);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($handler->called);
    }

    public function test_missing_header_is_rejected(): void
    {
        $handler = $this->handler();

        $response = $this->middleware()->process($this->request(), $handler);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($handler->called);
    }

    /**
     * Ключевая регрессия: пустой секрет — это отказ, а не «пропустить всё».
     */
    public function test_unconfigured_secret_blocks_instead_of_allowing_everything(): void
    {
        unset($_ENV['TELEGRAM_WEBHOOK_SECRET']);
        $handler = $this->handler();

        $response = $this->middleware()->process($this->request(), $handler);

        $this->assertSame(503, $response->getStatusCode());
        $this->assertFalse($handler->called, 'без настроенного секрета вебхук обязан быть закрыт');
    }

    /**
     * Секрет в query-строке больше не принимается: он оседает в access-логах.
     * Текущая регистрация вебхука его и не использует.
     */
    public function test_secret_in_query_string_is_not_accepted(): void
    {
        $handler = $this->handler();

        $response = $this->middleware()->process($this->request('secret=' . self::SECRET), $handler);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($handler->called);
    }

    /**
     * События WA-моста проходят мимо: их проверяет WaEventHandler своим
     * секретом (hash_equals, fail-closed). Поведение зафиксировано намеренно.
     */
    public function test_wa_event_is_delegated_to_controller(): void
    {
        $handler = $this->handler();

        $response = $this->middleware()->process($this->request('wa_event=1'), $handler);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($handler->called);
    }
}

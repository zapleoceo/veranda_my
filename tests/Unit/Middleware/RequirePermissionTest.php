<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Middleware\RequirePermission;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Гейт прав на группу роутов.
 *
 * Фон: право `payday` проверялось только на HTML-странице, а все 38
 * эндпоинтов /payday3/api/* были открыты любому залогиненному сотруднику —
 * включая создание реальной финансовой проводки в Poster.
 */
final class RequirePermissionTest extends TestCase
{
    protected function setUp(): void    { $_SESSION = []; }
    protected function tearDown(): void { $_SESSION = []; }

    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public bool $called = false;
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->called = true;
                $r = new Response(200);
                $r->getBody()->write('OK');
                return $r;
            }
        };
    }

    public function test_allows_request_when_permission_present(): void
    {
        $_SESSION['user_permissions'] = ['payday' => 1];

        $handler = $this->handler();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/payday3/api/links');

        $response = RequirePermission::for('payday')->process($request, $handler);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($handler->called);
    }

    public function test_blocks_request_without_permission_and_does_not_reach_handler(): void
    {
        $_SESSION['user_permissions'] = ['dashboard' => 1];

        $handler = $this->handler();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/payday3/api/links');

        $response = RequirePermission::for('payday')->process($request, $handler);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($handler->called, 'action не должен выполниться');
    }

    /** Сессия без прав — строгий отказ, а не «раз не знаем, то пропустим». */
    public function test_denies_when_session_has_no_permissions(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/payday3/api/links');

        $response = RequirePermission::for('payday')->process($request, $this->handler());

        $this->assertSame(403, $response->getStatusCode());
    }

    /** Ключевой сценарий: создание проводки в Poster без права payday. */
    public function test_blocks_poster_finance_transaction_create(): void
    {
        $_SESSION['user_permissions'] = ['dashboard' => 1, 'employees' => 1];

        $handler = $this->handler();
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/payday3/api/poster/finance/transactions');

        $response = RequirePermission::for('payday')->process($request, $handler);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($handler->called);
    }

    /** API-клиенту нужен JSON-конверт, а не HTML — иначе интерфейс ломается молча. */
    public function test_api_path_gets_json_envelope(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/payday3/api/links');

        $response = RequirePermission::for('payday')->process($request, $this->handler());

        $this->assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        $decoded = json_decode((string) $response->getBody(), true);
        $this->assertSame(false, $decoded['ok'] ?? null);
    }

    /** Обычная страница получает текстовый 403, а не JSON. */
    public function test_html_path_gets_plain_text(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/payday3');

        $response = RequirePermission::for('payday')->process($request, $this->handler());

        $this->assertStringContainsString('text/plain', $response->getHeaderLine('Content-Type'));
    }
}

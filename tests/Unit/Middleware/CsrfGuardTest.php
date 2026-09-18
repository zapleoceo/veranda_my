<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Infrastructure\SessionCsrf;
use App\Middleware\CsrfGuard;
use App\Order\Http\Middleware\CsrfMiddleware;
use App\Order\Infrastructure\Csrf;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * CSRF на /payday3/api: до правки токен в bootstrap был всегда пустой
 * (payday2_csrf никто не выставлял), и любой same-site POST создавал
 * проводки в Poster.
 */
final class CsrfGuardTest extends TestCase
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
                return new Response(200);
            }
        };
    }

    private function request(string $method, array $headers = []): ServerRequestInterface
    {
        $r = (new ServerRequestFactory())->createServerRequest($method, 'https://veranda.my/payday3/api/day/clear')
            ->withHeader('Host', 'veranda.my');
        foreach ($headers as $k => $v) $r = $r->withHeader($k, $v);
        return $r;
    }

    private function errorOf(ResponseInterface $res): string
    {
        return (string)(json_decode((string)$res->getBody(), true)['error'] ?? '');
    }

    public function test_get_passes_without_token(): void
    {
        $h = $this->handler();
        $res = CsrfGuard::payday3()->process($this->request('GET'), $h);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertTrue($h->called);
    }

    public function test_post_without_token_is_403_json(): void
    {
        SessionCsrf::token(CsrfGuard::PAYDAY3);
        $h = $this->handler();
        $res = CsrfGuard::payday3()->process($this->request('POST', ['Origin' => 'https://veranda.my']), $h);
        $this->assertSame(403, $res->getStatusCode());
        $this->assertStringContainsString('application/json', $res->getHeaderLine('Content-Type'));
        $this->assertSame('Forbidden: csrf', $this->errorOf($res));
        $this->assertFalse($h->called);
    }

    public function test_post_with_wrong_token_is_rejected(): void
    {
        SessionCsrf::token(CsrfGuard::PAYDAY3);
        $res = CsrfGuard::payday3()->process(
            $this->request('DELETE', ['X-CSRF-Token' => str_repeat('a', 64)]), $this->handler());
        $this->assertSame(403, $res->getStatusCode());
    }

    public function test_post_with_token_but_no_session_token_is_rejected(): void
    {
        // Empty session token must never match an empty/any header.
        $res = CsrfGuard::payday3()->process($this->request('POST', ['X-CSRF-Token' => 'x']), $this->handler());
        $this->assertSame(403, $res->getStatusCode());
    }

    public function test_post_with_valid_token_and_same_origin_passes(): void
    {
        $token = SessionCsrf::token(CsrfGuard::PAYDAY3);
        $h = $this->handler();
        $res = CsrfGuard::payday3()->process($this->request('POST', [
            'X-CSRF-Token' => $token, 'Origin' => 'https://veranda.my', 'Sec-Fetch-Site' => 'same-origin',
        ]), $h);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertTrue($h->called);
    }

    public function test_valid_token_without_origin_headers_passes_for_payday3(): void
    {
        $token = SessionCsrf::token(CsrfGuard::PAYDAY3);
        $res = CsrfGuard::payday3()->process($this->request('POST', ['X-CSRF-Token' => $token]), $this->handler());
        $this->assertSame(200, $res->getStatusCode(), 'Origin/Referer are checked only when present');
    }

    public function test_valid_token_from_foreign_origin_is_rejected(): void
    {
        $token = SessionCsrf::token(CsrfGuard::PAYDAY3);
        $res = CsrfGuard::payday3()->process($this->request('POST', [
            'X-CSRF-Token' => $token, 'Origin' => 'https://evil.example',
        ]), $this->handler());
        $this->assertSame(403, $res->getStatusCode());
        $this->assertSame('Forbidden: cross-origin', $this->errorOf($res));
    }

    public function test_cross_site_fetch_metadata_is_rejected(): void
    {
        $token = SessionCsrf::token(CsrfGuard::PAYDAY3);
        $res = CsrfGuard::payday3()->process($this->request('POST', [
            'X-CSRF-Token' => $token, 'Sec-Fetch-Site' => 'cross-site',
        ]), $this->handler());
        $this->assertSame(403, $res->getStatusCode());
    }

    public function test_neworder_token_does_not_unlock_payday3(): void
    {
        $neworder = Csrf::token();
        SessionCsrf::token(CsrfGuard::PAYDAY3);
        $res = CsrfGuard::payday3()->process($this->request('POST', ['X-CSRF-Token' => $neworder]), $this->handler());
        $this->assertSame(403, $res->getStatusCode());
    }

    public function test_neworder_middleware_still_requires_origin(): void
    {
        $token = Csrf::token();
        $h = $this->handler();
        $noOrigin = (new CsrfMiddleware())->process($this->request('POST', ['X-Csrf-Token' => $token]), $h);
        $this->assertSame(403, $noOrigin->getStatusCode());
        $this->assertSame('Forbidden: no-origin', $this->errorOf($noOrigin));

        $ok = (new CsrfMiddleware())->process($this->request('POST', [
            'X-Csrf-Token' => $token, 'Referer' => 'https://veranda.my/neworder/',
        ]), $h);
        $this->assertSame(200, $ok->getStatusCode());
    }
}

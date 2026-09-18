<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Infrastructure\SameOrigin;
use App\Infrastructure\SessionCsrf;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * CSRF guard for a group of mutation endpoints. Every non-safe request
 * (anything except GET/HEAD/OPTIONS) must:
 *
 *   1. carry an `X-CSRF-Token` header equal (hash_equals) to the token
 *      stored in the session under $sessionKey — the page renders that
 *      token into its bootstrap JSON;
 *   2. come from our own origin: Origin/Referer (when present, or always
 *      when $requireOriginHeader) must name our host, and Sec-Fetch-Site
 *      (when sent) must be same-origin/same-site.
 *
 * Otherwise → 403 JSON `{ok:false, error:"Forbidden: <reason>"}`.
 *
 * Used by /payday3/api (session key PAYDAY3) and — via CsrfMiddleware —
 * by /neworder + /onlineorder.
 */
final class CsrfGuard implements MiddlewareInterface
{
    public const PAYDAY3 = 'payday3_csrf';

    public function __construct(
        private readonly string $sessionKey,
        private readonly bool   $requireOriginHeader = false,
    ) {}

    public static function payday3(): self
    {
        return new self(self::PAYDAY3);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (in_array(strtoupper($request->getMethod()), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $handler->handle($request);
        }

        $token = trim($request->getHeaderLine('X-CSRF-Token'));
        if ($token === '' || !SessionCsrf::verify($this->sessionKey, $token)) {
            return self::reject('csrf');
        }

        $violation = SameOrigin::violation($request, $this->requireOriginHeader);
        if ($violation !== null) {
            return self::reject($violation);
        }

        return $handler->handle($request);
    }

    private static function reject(string $reason): ResponseInterface
    {
        $r = new Response(403);
        $r->getBody()->write((string)json_encode(
            ['ok' => false, 'error' => 'Forbidden: ' . $reason],
            JSON_UNESCAPED_UNICODE,
        ));
        return $r
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }
}

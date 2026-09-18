<?php

declare(strict_types=1);

namespace App\Order\Http\Middleware;

use App\Middleware\CsrfGuard;
use App\Order\Infrastructure\Csrf;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Three-layer guard for the /neworder POST endpoints. Until proper
 * auth ships, every mutation request must:
 *
 *   1. carry a valid `X-Csrf-Token` header matching the session;
 *   2. originate from a same-origin browser context (Origin/Referer
 *      header host matches the request's host header);
 *   3. (best-effort) declare `Sec-Fetch-Site: same-origin` if the
 *      browser sends it.
 *
 * Rejecting at the middleware layer keeps every Action class focused
 * on business logic — Actions assume the request is authenticated.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Shared implementation; /neworder additionally REQUIRES an
        // Origin or Referer header (public page, no login layer yet).
        return (new CsrfGuard(Csrf::SESSION_KEY, requireOriginHeader: true))
            ->process($request, $handler);
    }
}

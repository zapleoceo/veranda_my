<?php

declare(strict_types=1);

namespace App\Order\Http\Middleware;

use App\Order\Infrastructure\Csrf;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

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
        $method = strtoupper($request->getMethod());
        // Only mutation verbs need protection — GET endpoints are
        // either public read-only data (menu, locations) or already
        // safe to expose.
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $handler->handle($request);
        }

        $reject = function (string $reason): ResponseInterface {
            $r = new Response(403);
            $r->getBody()->write(json_encode(
                ['ok' => false, 'error' => 'Forbidden: ' . $reason],
                JSON_UNESCAPED_UNICODE,
            ));
            return $r->withHeader('Content-Type', 'application/json; charset=utf-8');
        };

        // 1. CSRF token.
        $token = trim($request->getHeaderLine('X-Csrf-Token'));
        if ($token === '' || !Csrf::verify($token)) {
            return $reject('csrf');
        }

        // 2. Origin / Referer must point to OUR host. We accept either
        // header (Origin is missing on some same-tab navigations, but
        // browsers always set at least one for fetch-driven POSTs).
        $host = $request->getHeaderLine('Host');
        if ($host === '') return $reject('no-host');
        $hostBase = strtolower(preg_replace('/:\d+$/', '', $host));

        $sources = [];
        $origin  = $request->getHeaderLine('Origin');
        if ($origin !== '' && $origin !== 'null') $sources[] = $origin;
        $referer = $request->getHeaderLine('Referer');
        if ($referer !== '') $sources[] = $referer;
        if (!$sources) return $reject('no-origin');

        $matched = false;
        foreach ($sources as $src) {
            $h = parse_url($src, PHP_URL_HOST);
            if (!is_string($h)) continue;
            if (strtolower($h) === $hostBase) { $matched = true; break; }
        }
        if (!$matched) return $reject('cross-origin');

        // 3. Fetch metadata (best-effort, modern browsers only).
        $sfs = strtolower($request->getHeaderLine('Sec-Fetch-Site'));
        if ($sfs !== '' && !in_array($sfs, ['same-origin', 'same-site'], true)) {
            return $reject('fetch-site');
        }

        return $handler->handle($request);
    }
}

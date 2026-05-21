<?php

declare(strict_types=1);

namespace App\PosterApp\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Allow widget→backend POSTs only when the request actually comes
 * from inside the Poster POS iframe. Cheap defence-in-depth on top
 * of the bearer token check that LoginAction does:
 *
 *   - Origin / Referer host must match *.joinposter.com
 *     (covers app.joinposter.com, <account>.joinposter.com,
 *      pos.joinposter.com, and the dev console).
 *
 * GET requests are exempt — those serve the widget HTML itself,
 * which has to be reachable so Poster can iframe it.
 *
 * Login bootstrap (POST /api/login) goes through this same gate;
 * we don't try to verify Poster's HMAC signature on the widget call
 * itself because Poster doesn't sign cross-iframe JS fetches —
 * trust is established by the origin AND the subsequent bearer
 * token roundtrip.
 */
final class PosterOriginMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getMethod() === 'GET' || $request->getMethod() === 'HEAD' || $request->getMethod() === 'OPTIONS') {
            return $handler->handle($request);
        }

        $sources = [];
        $origin  = $request->getHeaderLine('Origin');
        if ($origin !== '' && $origin !== 'null') $sources[] = $origin;
        $referer = $request->getHeaderLine('Referer');
        if ($referer !== '') $sources[] = $referer;

        $allowed = false;
        foreach ($sources as $src) {
            $host = parse_url($src, PHP_URL_HOST);
            if (!is_string($host)) continue;
            $host = strtolower($host);
            // Accept any *.joinposter.com host. Also accept our own
            // host (veranda.my) for testing the widget standalone.
            if ($host === 'joinposter.com' || str_ends_with($host, '.joinposter.com')) {
                $allowed = true; break;
            }
            if ($host === 'veranda.my' || str_ends_with($host, '.veranda.my')) {
                $allowed = true; break;
            }
        }

        if (!$allowed) {
            $r = new Response(403);
            $r->getBody()->write(json_encode(
                ['ok' => false, 'error' => 'Origin not allowed'],
                JSON_UNESCAPED_UNICODE,
            ));
            return $r->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        return $handler->handle($request);
    }
}

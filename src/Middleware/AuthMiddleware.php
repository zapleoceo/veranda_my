<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Infrastructure\ReturnPath;
use App\Infrastructure\Session;
use App\Services\UserPermissionsService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly UserPermissionsService   $permissions,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        Session::start();

        if (empty($_SESSION['user_email'])) {
            // Stash the path the user actually wanted so CallbackController
            // can land them back there after Google sign-in. Don't loop
            // /login / /logout / /auth/callback back through here.
            $uri  = $request->getUri();
            $path = $uri->getPath();
            $next = $path . (($q = $uri->getQuery()) !== '' ? '?' . $q : '');
            if (ReturnPath::isSafe($path)) {
                $_SESSION['auth_next'] = $next;
            }

            // AJAX / fetch / JSON callers get a 401 JSON envelope so
            // their client code can redirect cleanly instead of trying
            // to parse the HTML login page that a 302 would yield.
            if (self::wantsJson($request)) {
                $body = json_encode([
                    'ok'        => false,
                    'error'     => 'Auth required',
                    'login_url' => '/login',
                ], JSON_UNESCAPED_UNICODE);
                $r = $this->responseFactory->createResponse(401)
                    ->withHeader('Content-Type', 'application/json; charset=utf-8')
                    ->withHeader('Cache-Control', 'no-store');
                $r->getBody()->write($body !== false ? $body : '{"ok":false,"error":"Auth required"}');
                return $r;
            }
            return $this->responseFactory->createResponse(302)
                ->withHeader('Location', '/login');
        }

        $this->permissions->loadIntoSession((string) $_SESSION['user_email']);

        // Release the session file lock so concurrent AJAX from the
        // same operator can run in parallel — PHP's default file
        // session handler holds an exclusive lock until script end,
        // which otherwise serialises every fetch() coming from one
        // browser tab. Scoped to known read-only-on-session paths:
        //
        //   /payday3/* — RequestThrottle, BalanceSync and
        //                PosterCheckService all call Session::start()
        //                themselves when they need to write.
        //   /zapara/*  — workshops/dishes report, only reads
        //                $_SESSION['user_permissions'] once at the
        //                top of the controller. Without lock release
        //                the 14 parallel ?ajax=day fetches block
        //                each other → user sees serial responses
        //                ≈500ms apart instead of all together.
        //
        // Legacy modules (/payday2, /employees, /banya, /roma, …)
        // still hold the lock for their entire request to avoid
        // silently dropping their own session writes.
        $path = $request->getUri()->getPath();
        if (str_starts_with($path, '/payday3')
         || str_starts_with($path, '/zapara')) {
            Session::close();
        }

        return $handler->handle($request->withAttribute('user_email', $_SESSION['user_email']));
    }

    /** True for fetch()/XHR/JSON clients (they get a 401 JSON, not a 302). */
    private static function wantsJson(ServerRequestInterface $r): bool
    {
        $accept = strtolower($r->getHeaderLine('Accept'));
        if ($accept !== '' && str_contains($accept, 'application/json')) return true;
        if (strcasecmp($r->getHeaderLine('X-Requested-With'), 'XMLHttpRequest') === 0) return true;
        $ct = strtolower($r->getHeaderLine('Content-Type'));
        if ($ct !== '' && str_contains($ct, 'application/json')) return true;
        return false;
    }
}

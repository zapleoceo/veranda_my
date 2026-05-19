<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Infrastructure\Config;
use App\Infrastructure\Session;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class LoginController
{
    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        Session::start();

        // Honour ?next=/path passed by either AuthMiddleware (when an
        // unauthenticated user hit a protected route) or by the
        // operator pasting a deep link before signing in. Only
        // internal paths are accepted — never absolute URLs / //host
        // tricks.
        $qNext = (string) ($request->getQueryParams()['next'] ?? '');
        if ($qNext !== '' && self::isSafeReturnPath($qNext)) {
            $_SESSION['auth_next'] = $qNext;
        }

        if (!empty($_SESSION['user_email'])) {
            $next = (string) ($_SESSION['auth_next'] ?? '/admin');
            unset($_SESSION['auth_next']);
            return $response->withHeader('Location', $next)->withStatus(302);
        }

        $params = [
            'client_id'     => Config::require('GOOGLE_CLIENT_ID'),
            'redirect_uri'  => Config::require('GOOGLE_REDIRECT_URI'),
            'response_type' => 'code',
            'scope'         => 'email profile',
            'access_type'   => 'online',
            'prompt'        => 'select_account',
        ];
        $url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);

        $html = <<<HTML
        <!DOCTYPE html>
        <html lang="ru">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>Veranda — Вход</title>
            <style>
                body { font-family: sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; background: #f5f5f5; }
                .card { background: #fff; padding: 2rem 2.5rem; border-radius: 12px; box-shadow: 0 2px 16px rgba(0,0,0,.1); text-align: center; }
                h1 { margin: 0 0 1.5rem; font-size: 1.4rem; color: #333; }
                a.btn { display: inline-block; padding: .75rem 1.5rem; background: #4285F4; color: #fff; border-radius: 6px; text-decoration: none; font-size: 1rem; }
                a.btn:hover { background: #357ae8; }
            </style>
        </head>
        <body>
            <div class="card">
                <h1>Veranda Admin</h1>
                <a class="btn" href="{$url}">Войти через Google</a>
            </div>
        </body>
        </html>
        HTML;

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        Session::start();
        // Drop both the in-memory data and the cookie itself —
        // session_destroy alone leaves the cookie behind on some
        // browsers and they reattach to the killed session id.
        $_SESSION = [];
        if (!headers_sent()) {
            $name = session_name();
            $p = session_get_cookie_params();
            @setcookie($name !== false ? $name : 'PHPSESSID', '', [
                'expires'  => time() - 3600,
                'path'     => $p['path']     ?? '/',
                'domain'   => $p['domain']   ?? '',
                'secure'   => $p['secure']   ?? false,
                'httponly' => $p['httponly'] ?? true,
                'samesite' => $p['samesite'] ?? 'Lax',
            ]);
        }
        @session_destroy();

        return $response->withHeader('Location', '/login')->withStatus(302);
    }

    /** Only redirect back to internal paths (no open-redirect risk). */
    private static function isSafeReturnPath(string $path): bool
    {
        if ($path === '' || $path[0] !== '/') return false;
        if (str_starts_with($path, '//'))     return false;
        if (str_starts_with($path, '/login')) return false;
        return true;
    }
}

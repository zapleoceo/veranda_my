<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Infrastructure\Config;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class LoginController
{
    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!empty($_SESSION['user_email'])) {
            return $response->withHeader('Location', '/admin')->withStatus(302);
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
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        session_destroy();

        return $response->withHeader('Location', '/login')->withStatus(302);
    }
}

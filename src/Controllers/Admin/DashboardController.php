<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class DashboardController
{
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $email = $request->getAttribute('user_email', '');
        $response->getBody()->write("<h1>Dashboard</h1><p>Logged in as {$email}</p><p><a href='/logout'>Logout</a></p>");
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}

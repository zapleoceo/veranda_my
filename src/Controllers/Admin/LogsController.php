<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class LogsController
{
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write('<h1>Logs — TODO</h1>');
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}

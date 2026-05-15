<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class SyncController
{
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write('<h1>Sync — TODO</h1>');
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function start(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write(json_encode(['ok' => false, 'error' => 'not implemented']));
        return $response->withHeader('Content-Type', 'application/json');
    }
}

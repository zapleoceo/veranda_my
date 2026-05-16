<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class LinksController
{
    private const LINKS_DIR = __DIR__ . '/../../links';

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        ob_start();
        require self::LINKS_DIR . '/index.php';
        $html = (string)ob_get_clean();

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}

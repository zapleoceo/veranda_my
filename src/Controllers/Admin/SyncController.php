<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class SyncController
{
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userEmail = $request->getAttribute('user_email', '');
        $content = '<div class="card"><h2>Синк — в разработке</h2></div>';
        return $this->_layout($response, $content, '/admin/sync', $userEmail);
    }

    public function start(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write(json_encode(['ok' => false, 'error' => 'not implemented']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function _layout(ResponseInterface $response, string $content, string $path, string $userEmail): ResponseInterface
    {
        ob_start();
        $currentPath = $path;
        $flashOk = $flashErr = '';
        require __DIR__ . '/../../Views/layout.php';
        $html = ob_get_clean();
        $response->getBody()->write((string) $html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}

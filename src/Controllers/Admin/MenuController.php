<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class MenuController
{
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userEmail = $request->getAttribute('user_email', '');
        $content = '<div class="card"><h2>Меню — в разработке</h2></div>';
        return $this->_layout($response, $content, '/admin/menu', $userEmail);
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

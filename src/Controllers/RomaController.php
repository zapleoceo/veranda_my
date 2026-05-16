<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RomaController
{
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $perms = $_SESSION['user_permissions'] ?? null;
        if (is_array($perms) && empty($perms['roma'])) {
            $response->getBody()->write('Forbidden');
            return $response->withStatus(403)->withHeader('Content-Type', 'text/plain');
        }

        date_default_timezone_set('Asia/Ho_Chi_Minh');

        if (($request->getQueryParams()['ajax'] ?? '') !== '') {
            require __DIR__ . '/../../roma/index.php';
            return $response; // unreachable — legacy handler exits
        }

        $today        = date('Y-m-d');
        $firstOfMonth = date('Y-m-01');
        $pageTitle    = 'Roma — кальяны';
        $currentPath  = '/roma';
        $headExtra    = '<link rel="stylesheet" href="/assets/css/common.css">' . "\n"
                      . '<link rel="stylesheet" href="/assets/css/roma.css?v=20260516">';

        ob_start();
        require __DIR__ . '/../Views/roma_content.php';
        $content = (string) ob_get_clean();

        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = (string) ob_get_clean();

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}

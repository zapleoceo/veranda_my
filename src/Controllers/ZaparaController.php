<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class ZaparaController
{
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $perms = $_SESSION['user_permissions'] ?? null;
        if (is_array($perms) && empty($perms['zapara'])) {
            $response->getBody()->write('Forbidden');
            return $response->withStatus(403)->withHeader('Content-Type', 'text/plain');
        }

        date_default_timezone_set('Asia/Ho_Chi_Minh');

        $today       = date('Y-m-d');
        $defaultFrom = date('Y-m-d', strtotime('-14 days'));
        $defaultTo   = $today;
        $pageTitle   = 'Zapara';
        $currentPath = '/zapara';
        $headExtra   = '<link rel="stylesheet" href="/assets/css/common.css">' . "\n"
                     . '<link rel="stylesheet" href="/assets/css/zapara.css">';

        ob_start();
        require __DIR__ . '/../Views/zapara_content.php';
        $content = (string) ob_get_clean();

        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = (string) ob_get_clean();

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}

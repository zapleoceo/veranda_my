<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class EmployeesController
{
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $perms = $_SESSION['user_permissions'] ?? null;
        if (is_array($perms) && empty($perms['employees'])) {
            $response->getBody()->write('Forbidden');
            return $response->withStatus(403)->withHeader('Content-Type', 'text/plain');
        }

        date_default_timezone_set('Asia/Ho_Chi_Minh');

        if (($request->getQueryParams()['ajax'] ?? '') !== '') {
            require __DIR__ . '/../../employees/index.php';
            return $response; // unreachable — legacy handler exits
        }

        $today        = date('Y-m-d');
        $firstOfMonth = date('Y-m-01');
        $pageTitle    = 'ЗП сотрудников';
        $currentPath  = '/employees';
        $headExtra    = '<link rel="stylesheet" href="/assets/css/common.css?v=20260430_0007">' . "\n"
                      . '<link rel="stylesheet" href="/assets/css/employees.css?v=20260520_help">' . "\n"
                      . '<link rel="stylesheet" href="/assets/css/employees_view.css?v=20260516">';

        // Replicate employees_csrf_ensure() without requiring legacy files
        $employeesCsrf = '';
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (empty($_SESSION['employees_csrf'])) {
                $_SESSION['employees_csrf'] = bin2hex(random_bytes(16));
            }
            $employeesCsrf = (string) $_SESSION['employees_csrf'];
        }

        ob_start();
        require __DIR__ . '/../Views/employees_content.php';
        $content = (string) ob_get_clean();

        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = (string) ob_get_clean();

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}

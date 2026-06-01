<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Infrastructure\Permissions;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class EmployeesController
{
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // Single permission gate — same helper the sidebar and legacy
        // AJAX dispatcher use, so a revoked user can neither see the
        // link, open the page, nor fire AJAX.
        if (!Permissions::can('employees')) {
            return Permissions::denyHtml($response);
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
                      . '<link rel="stylesheet" href="/assets/css/employees.css?v=20260521_tabel2">' . "\n"
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

<?php

declare(strict_types=1);

namespace App\Cashflow\Http;

use App\Cashflow\Services\ExpenseService;
use App\Cashflow\Services\PosterHttp;
use App\Cashflow\Services\ReportService;
use App\Cashflow\Services\RevenueService;
use App\Infrastructure\Config;
use App\Infrastructure\Permissions;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * /cashflowreport — live P&L report (Stage 1: revenue grid straight from Poster).
 *
 * Page-level gate mirrors the route middleware (RequirePermission::for('cashflow'))
 * so a direct render can't leak the report even if the route wiring changes.
 */
final class CashflowController
{
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!Permissions::can('cashflow')) {
            return Permissions::denyHtml($response);
        }
        date_default_timezone_set('Asia/Ho_Chi_Minh');

        [$year, $month] = $this->parseMonth((string) ($request->getQueryParams()['ym'] ?? ''));

        // Token is server-only. Config first (Bootstrap loads .env into it),
        // then $_ENV / getenv fallbacks for the odd PHP-FPM worker.
        $token = trim((string) (
            Config::get('POSTER_API_TOKEN')
            ?: ($_ENV['POSTER_API_TOKEN'] ?? '')
            ?: (getenv('POSTER_API_TOKEN') ?: '')
        ));

        $data  = null;
        $error = null;
        if ($token === '') {
            $error = 'POSTER_API_TOKEN не задан на сервере.';
        } else {
            try {
                $http = new PosterHttp($token);
                $data = (new ReportService(new RevenueService($http), new ExpenseService($http)))->month($year, $month);
            } catch (\Throwable $e) {
                $error = 'Poster недоступен: ' . $e->getMessage();
            }
        }

        $cur  = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $prev = $cur->modify('-1 month')->format('Y-m');
        $next = $cur->modify('+1 month')->format('Y-m');

        $pageTitle   = 'Финансовый отчёт';
        $currentPath = '/cashflowreport';
        $headExtra   = '<link rel="stylesheet" href="/assets/css/cashflow.css?v=20260807_2">';

        ob_start();
        require __DIR__ . '/../../Views/cashflow_content.php';
        $content = (string) ob_get_clean();

        ob_start();
        require __DIR__ . '/../../Views/layout.php';
        $html = (string) ob_get_clean();

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * ?ym=YYYY-MM → [year, month]; anything invalid → current month.
     *
     * @return array{0:int,1:int}
     */
    private function parseMonth(string $ym): array
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', $ym, $m) === 1) {
            $y  = (int) $m[1];
            $mo = (int) $m[2];
            if ($mo >= 1 && $mo <= 12 && $y >= 2020 && $y <= 2100) {
                return [$y, $mo];
            }
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Ho_Chi_Minh'));
        return [(int) $now->format('Y'), (int) $now->format('n')];
    }
}

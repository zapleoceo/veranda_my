<?php

declare(strict_types=1);

namespace App\Cashflow\Http;

use App\Cashflow\Services\DrilldownService;
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

        // Token is server-only. Config first (Bootstrap loads .env into it),
        // then $_ENV / getenv fallbacks for the odd PHP-FPM worker.
        $token = trim((string) (
            Config::get('POSTER_API_TOKEN')
            ?: ($_ENV['POSTER_API_TOKEN'] ?? '')
            ?: (getenv('POSTER_API_TOKEN') ?: '')
        ));

        // Drill-down AJAX (JSON): ?ajax=day|checks|expenses&date=YYYY-MM-DD[&column=key]
        $ajax = (string) ($request->getQueryParams()['ajax'] ?? '');
        if ($ajax !== '') {
            return $this->ajax($request, $response, $ajax, $token);
        }

        [$year, $month] = $this->parseMonth((string) ($request->getQueryParams()['ym'] ?? ''));

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
        $headExtra   = '<link rel="stylesheet" href="/assets/css/cashflow.css?v=20260807_3">' . "\n"
                     . '<script src="/assets/js/cashflow.js?v=20260807_3" defer></script>';

        ob_start();
        require __DIR__ . '/../../Views/cashflow_content.php';
        $content = (string) ob_get_clean();

        ob_start();
        require __DIR__ . '/../../Views/layout.php';
        $html = (string) ob_get_clean();

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /** JSON drill-down endpoint. `cashflow` permission already checked in index(). */
    private function ajax(ServerRequestInterface $request, ResponseInterface $response, string $ajax, string $token): ResponseInterface
    {
        $json = static function (int $status, array $data) use ($response): ResponseInterface {
            $response->getBody()->write((string) json_encode($data, JSON_UNESCAPED_UNICODE));
            return $response->withStatus($status)
                ->withHeader('Content-Type', 'application/json; charset=utf-8')
                ->withHeader('Cache-Control', 'no-store');
        };
        if ($token === '') {
            return $json(500, ['ok' => false, 'error' => 'POSTER_API_TOKEN не задан']);
        }
        $q    = $request->getQueryParams();
        $date = (string) ($q['date'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return $json(400, ['ok' => false, 'error' => 'bad date']);
        }
        $svc = new DrilldownService(new PosterHttp($token));
        try {
            return match ($ajax) {
                'day'      => $json(200, ['ok' => true] + $svc->dayRevenue($date)),
                'checks'   => $json(200, ['ok' => true, 'checks' => $svc->dayChecks($date)]),
                'expenses' => $json(200, ['ok' => true] + $svc->dayExpenses($date, (string) ($q['column'] ?? ''))),
                default    => $json(404, ['ok' => false, 'error' => 'unknown ajax']),
            };
        } catch (\Throwable $e) {
            return $json(500, ['ok' => false, 'error' => $e->getMessage()]);
        }
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

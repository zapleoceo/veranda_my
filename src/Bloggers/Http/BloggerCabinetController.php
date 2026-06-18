<?php

declare(strict_types=1);

namespace App\Bloggers\Http;

use App\Bloggers\Services\BloggerService;
use App\Infrastructure\Config;
use App\Infrastructure\Session;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Public blogger cabinet — a separate, mobile-first page (its own minimal
 * layout, NOT the admin sidebar). Reachable at /blogger, and at the root of the
 * blogers.veranda.my subdomain once DNS + vhost point there.
 *
 * Bloggers live in their own session realm (blogger_client_id, set by
 * CallbackController on Google login), completely separate from staff — they
 * can only ever see their own report.
 */
final class BloggerCabinetController
{
    public function __construct(private readonly BloggerService $svc) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        Session::start();
        $clientId = (int) ($_SESSION['blogger_client_id'] ?? 0);

        if ($clientId <= 0) {
            return $this->html($response, $this->render(['mode' => 'login', 'googleUrl' => $this->googleUrl()]));
        }

        $q        = $request->getQueryParams();
        $dateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($q['dateFrom'] ?? '')) ? (string) $q['dateFrom'] : date('Y-m-01');
        $dateTo   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($q['dateTo'] ?? ''))   ? (string) $q['dateTo']   : date('Y-m-d');
        if ($dateTo < $dateFrom) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        $row = null;
        $err = '';
        try {
            $report = $this->svc->report($dateFrom, $dateTo, $clientId);
            $row    = $report['rows'][0] ?? null;
        } catch (\Throwable $e) {
            $err = 'Не удалось загрузить отчёт. Попробуйте позже.';
        }

        return $this->html($response, $this->render([
            'mode'     => 'report',
            'name'     => (string) ($_SESSION['blogger_name'] ?? ''),
            'row'      => $row,
            'dateFrom' => $dateFrom,
            'dateTo'   => $dateTo,
            'err'      => $err,
        ]));
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        Session::start();
        unset($_SESSION['blogger_client_id'], $_SESSION['blogger_email'], $_SESSION['blogger_name']);
        return $response->withHeader('Location', '/blogger')->withStatus(302);
    }

    /** Build the Google OAuth URL and stash auth_next=/blogger for the callback. */
    private function googleUrl(): string
    {
        $_SESSION['auth_next'] = '/blogger';
        $params = [
            'client_id'     => Config::require('GOOGLE_CLIENT_ID'),
            'redirect_uri'  => Config::require('GOOGLE_REDIRECT_URI'),
            'response_type' => 'code',
            'scope'         => 'email profile',
            'access_type'   => 'online',
            'prompt'        => 'select_account',
        ];
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }

    private function render(array $vars): string
    {
        ob_start();
        extract($vars, EXTR_SKIP);
        require __DIR__ . '/../../Views/blogger/cabinet.php';
        return (string) ob_get_clean();
    }

    private function html(ResponseInterface $response, string $html): ResponseInterface
    {
        $response->getBody()->write($html);
        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }
}

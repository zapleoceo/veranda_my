<?php

declare(strict_types=1);

namespace App\Bloggers\Http;

use App\Bloggers\Services\BloggerService;
use App\Infrastructure\Config;
use App\Infrastructure\Session;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class BloggerCabinetController
{
    public function __construct(private readonly BloggerService $svc) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        Session::start();
        $clientId = (int) ($_SESSION['blogger_client_id'] ?? 0);

        // ── Unauthenticated: welcome page + registration ──────────────────
        if ($clientId <= 0) {
            $flash   = ['ok' => '', 'err' => ''];
            $regData = [];

            if (strtoupper($request->getMethod()) === 'POST') {
                $body    = (array) ($request->getParsedBody() ?? []);
                $regData = $body; // pre-fill form on error
                if (isset($body['register'])) {
                    try {
                        $this->svc->register(
                            (string) ($body['promocode'] ?? ''),
                            (string) ($body['name'] ?? ''),
                            (string) ($body['email'] ?? ''),
                            [
                                'ig' => (string) ($body['ig'] ?? ''),
                                'tg' => (string) ($body['tg'] ?? ''),
                                'tt' => (string) ($body['tt'] ?? ''),
                                'yt' => (string) ($body['yt'] ?? ''),
                            ],
                        );
                        $flash['ok'] = 'Заявка отправлена! Как только менеджер одобрит её, вы сможете войти через Google.';
                        $regData     = []; // clear form after success
                    } catch (\Throwable $e) {
                        $flash['err'] = $e->getMessage();
                    }
                }
            }

            return $this->html($response, $this->render([
                'mode'      => 'login',
                'googleUrl' => $this->googleUrl(),
                'flash'     => $flash,
                'regData'   => $regData,
            ]));
        }

        // ── Authenticated blogger ─────────────────────────────────────────
        $q        = $request->getQueryParams();
        $dateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($q['dateFrom'] ?? '')) ? (string) $q['dateFrom'] : date('Y-m-01');
        $dateTo   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($q['dateTo'] ?? ''))   ? (string) $q['dateTo']   : date('Y-m-d');
        if ($dateTo < $dateFrom) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        $flash = ['ok' => '', 'err' => ''];

        if (strtoupper($request->getMethod()) === 'POST') {
            $body = (array) ($request->getParsedBody() ?? []);
            if (isset($body['save_self'])) {
                try {
                    $this->svc->selfUpdate(
                        $clientId,
                        (string) ($body['promocode'] ?? ''),
                        (float)  ($body['discount_pct'] ?? 0),
                        (float)  ($body['cashback_pct'] ?? 0),
                    );
                    $flash['ok'] = 'Изменения сохранены.';
                } catch (\Throwable $e) {
                    $flash['err'] = $e->getMessage();
                }
            }
        }

        $row    = null;
        $checks = [];
        try {
            $report = $this->svc->report($dateFrom, $dateTo, $clientId);
            $row    = $report['rows'][0] ?? null;
            if ($row !== null) {
                $checks = $this->svc->checks($dateFrom, $dateTo, $clientId);
            }
        } catch (\Throwable $e) {
            $flash['err'] = $flash['err'] !== '' ? $flash['err'] : 'Не удалось загрузить отчёт. Попробуйте позже.';
        }

        return $this->html($response, $this->render([
            'mode'     => 'report',
            'name'     => (string) ($_SESSION['blogger_name'] ?? ''),
            'row'      => $row,
            'checks'   => $checks,
            'dateFrom' => $dateFrom,
            'dateTo'   => $dateTo,
            'flash'    => $flash,
        ]));
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        Session::start();
        unset($_SESSION['blogger_client_id'], $_SESSION['blogger_email'], $_SESSION['blogger_name']);
        return $response->withHeader('Location', '/bloggers')->withStatus(302);
    }

    private function googleUrl(): string
    {
        $_SESSION['auth_next'] = '/bloggers';
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

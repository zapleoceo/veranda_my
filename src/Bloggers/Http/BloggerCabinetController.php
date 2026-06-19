<?php

declare(strict_types=1);

namespace App\Bloggers\Http;

use App\Bloggers\Services\BloggerService;
use App\Infrastructure\GoogleOAuth;
use App\Infrastructure\Session;
use App\OnlineOrder\Infrastructure\SubmitThrottle;
use App\Order\Infrastructure\Csrf;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Public influencer cabinet at /bloggers — its own mobile-first page (not the
 * admin layout). Bloggers live in a separate session realm (blogger_client_id,
 * NO user_email) so they can only ever see their own data and can never reach
 * /admin/*.
 *
 * State-changing POSTs are guarded by a synchroniser CSRF token (shared app
 * session token via Csrf); public self-registration additionally has a
 * honeypot + per-session submit throttle to blunt bot/spam flooding of the
 * Poster client list.
 */
final class BloggerCabinetController
{
    public function __construct(private readonly BloggerService $svc) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        Session::start();
        $clientId = (int) ($_SESSION['blogger_client_id'] ?? 0);

        return $clientId > 0
            ? $this->cabinet($request, $response, $clientId)
            : $this->welcome($request, $response);
    }

    // ── Unauthenticated: welcome + registration ───────────────────────────

    private function welcome(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $flash   = ['ok' => '', 'err' => ''];
        $regData = [];

        if (strtoupper($request->getMethod()) === 'POST') {
            $body    = (array) ($request->getParsedBody() ?? []);
            $regData = $body;
            if (isset($body['register'])) {
                $regData = $this->handleRegister($body, $flash);
            }
        }

        return $this->html($response, $this->render([
            'mode'      => 'login',
            'googleUrl' => $this->googleUrl(),
            'csrf'      => Csrf::token(),
            'flash'     => $flash,
            'regData'   => $regData,
        ]));
    }

    /** @return array<string,mixed> form data to re-fill on error ([] on success) */
    private function handleRegister(array $body, array &$flash): array
    {
        if (!Csrf::verify((string) ($body['csrf_token'] ?? ''))) {
            $flash['err'] = 'Сессия устарела — обновите страницу и попробуйте ещё раз.';
            return $body;
        }
        // Honeypot: real browsers leave it empty, bots fill it. Neutral reply.
        if (trim((string) ($body['website'] ?? '')) !== '') {
            $flash['err'] = 'Не удалось отправить заявку. Попробуйте позже.';
            return $body;
        }
        $throttle = new SubmitThrottle(5, 1800, 'bloggers_register_submits');
        if (!$throttle->allow()) {
            $flash['err'] = 'Слишком много попыток. Подождите немного и попробуйте снова.';
            return $body;
        }

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
            $throttle->hit();
            $flash['ok'] = 'Готово! Войдите через Google тем же адресом — и личный кабинет ваш.';
            return [];
        } catch (\Throwable $e) {
            $flash['err'] = $e->getMessage();
            return $body;
        }
    }

    // ── Authenticated blogger cabinet ─────────────────────────────────────

    private function cabinet(ServerRequestInterface $request, ResponseInterface $response, int $clientId): ResponseInterface
    {
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
                if (!Csrf::verify((string) ($body['csrf_token'] ?? ''))) {
                    $flash['err'] = 'Сессия устарела — обновите страницу и сохраните ещё раз.';
                } else {
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
            'csrf'     => Csrf::token(),
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

    /** Stash auth_next=/bloggers, then build the Google consent URL. */
    private function googleUrl(): string
    {
        $_SESSION['auth_next'] = '/bloggers';
        return GoogleOAuth::authorizeUrl();
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

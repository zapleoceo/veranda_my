<?php

declare(strict_types=1);

namespace App\Bloggers\Http;

use App\Bloggers\Services\BloggerService;
use App\Bloggers\Support\BloggerError;
use App\Bloggers\Support\BloggerLang;
use App\Home\I18n\Locale;
use App\Infrastructure\GoogleOAuth;
use App\Infrastructure\Session;
use App\OnlineOrder\Infrastructure\SubmitThrottle;
use App\Order\Infrastructure\Csrf;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Public influencer cabinet at /bloggers — its own mobile-first, multilingual
 * (en/ru/vi) page. Locale = ?lang override (persisted to the shared site
 * `home_lang` cookie) → cookie/browser via Locale::detect(). Bloggers live in
 * a separate session realm (blogger_client_id, NO user_email).
 *
 * State-changing POSTs are CSRF-guarded; public self-registration also has a
 * honeypot + per-session throttle. Validation errors come back as BloggerError
 * translation keys and are rendered in the visitor's locale.
 */
final class BloggerCabinetController
{
    public function __construct(private readonly BloggerService $svc) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        Session::start();
        $lang = $this->locale($request);
        $t    = new BloggerLang($lang);

        $clientId = (int) ($_SESSION['blogger_client_id'] ?? 0);

        return $clientId > 0
            ? $this->cabinet($request, $response, $clientId, $t, $lang)
            : $this->welcome($request, $response, $t, $lang);
    }

    /** ?lang override (persisted to home_lang cookie) → Locale::detect(). */
    private function locale(ServerRequestInterface $request): string
    {
        $q = Locale::normalize((string) ($request->getQueryParams()['lang'] ?? ''));
        if ($q !== null) {
            if (!headers_sent()) {
                setcookie(Locale::COOKIE, $q, [
                    'expires'  => time() + 31536000,
                    'path'     => '/',
                    'samesite' => 'Lax',
                ]);
            }
            $_COOKIE[Locale::COOKIE] = $q;
            return $q;
        }
        return Locale::detect();
    }

    // ── Unauthenticated: welcome + registration ───────────────────────────

    private function welcome(ServerRequestInterface $request, ResponseInterface $response, BloggerLang $t, string $lang): ResponseInterface
    {
        $flash   = ['ok' => '', 'err' => ''];
        $regData = [];

        if (strtoupper($request->getMethod()) === 'POST') {
            $body    = (array) ($request->getParsedBody() ?? []);
            $regData = $body;
            if (isset($body['register'])) {
                $regData = $this->handleRegister($body, $flash, $t);
            }
        }

        return $this->html($response, $this->render([
            'mode'      => 'login',
            'lang'      => $lang,
            't'         => $t,
            'googleUrl' => $this->googleUrl(),
            'csrf'      => Csrf::token(),
            'flash'     => $flash,
            'regData'   => $regData,
        ]));
    }

    /** @return array<string,mixed> form data to re-fill on error ([] on success) */
    private function handleRegister(array $body, array &$flash, BloggerLang $t): array
    {
        if (!Csrf::verify((string) ($body['csrf_token'] ?? ''))) {
            $flash['err'] = $t->t('fl.csrf');
            return $body;
        }
        // Honeypot: real browsers leave it empty, bots fill it. Neutral reply.
        if (trim((string) ($body['website'] ?? '')) !== '') {
            $flash['err'] = $t->t('fl.hp');
            return $body;
        }
        $throttle = new SubmitThrottle(5, 1800, 'bloggers_register_submits');
        if (!$throttle->allow()) {
            $flash['err'] = $t->t('fl.throttle');
            return $body;
        }

        try {
            $this->svc->register(
                (string) ($body['promocode'] ?? ''),
                (string) ($body['name'] ?? ''),
                (string) ($body['email'] ?? ''),
                $this->socialsFromBody($body),
            );
            $throttle->hit();
            $flash['ok'] = $t->t('fl.reg.ok');
            return [];
        } catch (\Throwable $e) {
            $flash['err'] = $this->errorText($e, $t);
            return $body;
        }
    }

    // ── Authenticated cabinet ─────────────────────────────────────────────

    private function cabinet(ServerRequestInterface $request, ResponseInterface $response, int $clientId, BloggerLang $t, string $lang): ResponseInterface
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
                    $flash['err'] = $t->t('fl.save.csrf');
                } else {
                    try {
                        $this->svc->selfUpdate(
                            $clientId,
                            (string) ($body['promocode'] ?? ''),
                            (float)  ($body['discount_pct'] ?? 0),
                            (float)  ($body['cashback_pct'] ?? 0),
                        );
                        $flash['ok'] = $t->t('fl.saved');
                    } catch (\Throwable $e) {
                        $flash['err'] = $this->errorText($e, $t);
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
            $flash['err'] = $flash['err'] !== '' ? $flash['err'] : $t->t('d.load.err');
        }

        return $this->html($response, $this->render([
            'mode'     => 'report',
            'lang'     => $lang,
            't'        => $t,
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

    /** Zip social_net[]/social_val[] into a list of {net,val}. @return list<array{net:string,val:string}> */
    private function socialsFromBody(array $body): array
    {
        $nets = is_array($body['social_net'] ?? null) ? $body['social_net'] : [];
        $vals = is_array($body['social_val'] ?? null) ? $body['social_val'] : [];
        $out  = [];
        foreach ($nets as $i => $net) {
            $out[] = ['net' => (string) $net, 'val' => (string) ($vals[$i] ?? '')];
        }
        return $out;
    }

    private function errorText(\Throwable $e, BloggerLang $t): string
    {
        return $e instanceof BloggerError ? $t->t($e->key, $e->params) : $t->t('err.generic');
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

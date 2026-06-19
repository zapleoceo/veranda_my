<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Bloggers\Services\BloggerService;
use App\Infrastructure\Permissions;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * /admin/bloggers — manager cabinet for the referral system.
 * Server-rendered (same pattern as AccessController): POST actions are handled
 * inline and the page re-renders with the fresh list + period report.
 */
final class BloggersController
{
    public function __construct(private readonly BloggerService $svc) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // One permission key = one page (strict gate, fail-safe).
        if (!Permissions::can('bloggers')) {
            $response->getBody()->write('Forbidden');
            return $response->withStatus(403)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        $userEmail = (string) $request->getAttribute('user_email', '');
        $flash     = ['ok' => '', 'err' => ''];
        $query     = $request->getQueryParams();
        $body       = [];

        if (strtoupper($request->getMethod()) === 'POST') {
            $body = (array) ($request->getParsedBody() ?? []);
            $this->handlePost($body, $userEmail, $flash);
        }

        // Period — defaults to first-of-month → today (same as Banya). POST
        // forms carry the dates as hidden fields so the period survives a save.
        $src      = $body + $query;
        $dateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($src['dateFrom'] ?? '')) ? (string) $src['dateFrom'] : date('Y-m-01');
        $dateTo   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($src['dateTo'] ?? ''))   ? (string) $src['dateTo']   : date('Y-m-d');
        if ($dateTo < $dateFrom) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        $config   = $this->svc->config();
        $report   = ['rows' => [], 'totals' => ['bloggers' => 0, 'checks' => 0, 'revenue' => 0, 'cashback' => 0, 'paid' => 0, 'topay' => 0]];
        $accounts = [];
        try {
            // report() returns every blogger (active first) + period stats;
            // accounts feed the payout dropdown.
            $report   = $this->svc->report($dateFrom, $dateTo);
            $accounts = $this->svc->accounts();
        } catch (\Throwable $e) {
            $flash['err'] = $flash['err'] !== '' ? $flash['err'] : ('Ошибка загрузки данных Poster: ' . $e->getMessage());
        }

        ob_start();
        require __DIR__ . '/../../Views/admin/bloggers.php';
        $content = (string) ob_get_clean();

        return $this->layout($response, $content, '/admin/bloggers', $userEmail, $flash);
    }

    private function handlePost(array $body, string $userEmail, array &$flash): void
    {
        try {
            if (isset($body['create_blogger'])) {
                $id = $this->svc->create(
                    (string) ($body['promocode'] ?? ''),
                    (string) ($body['name'] ?? ''),
                    (string) ($body['email'] ?? ''),
                    (float) ($body['discount_pct'] ?? 0),
                    (float) ($body['cashback_pct'] ?? 0),
                    (float) ($body['limit_pct'] ?? 15),
                    $this->socialsFrom($body),
                    $userEmail,
                );
                $flash['ok'] = "Блогер создан (Poster client_id {$id}).";
            } elseif (isset($body['update_blogger'])) {
                $this->svc->update(
                    (int) ($body['client_id'] ?? 0),
                    (string) ($body['promocode'] ?? ''),
                    (string) ($body['name'] ?? ''),
                    (string) ($body['email'] ?? ''),
                    (float) ($body['discount_pct'] ?? 0),
                    (float) ($body['cashback_pct'] ?? 0),
                    (float) ($body['limit_pct'] ?? 15),
                    $this->socialsFrom($body),
                );
                $flash['ok'] = 'Изменения сохранены.';
            } elseif (isset($body['toggle_active'])) {
                $activate = (string) $body['toggle_active'] === 'activate';
                $this->svc->setActive((int) ($body['client_id'] ?? 0), $activate);
                $flash['ok'] = $activate ? 'Блогер активирован.' : 'Блогер деактивирован.';
            } elseif (isset($body['save_config'])) {
                $this->svc->saveConfig((int) ($body['group_id'] ?? 0), (int) ($body['payout_category_id'] ?? 0));
                $flash['ok'] = 'Настройки сохранены.';
            } elseif (isset($body['pay_blogger'])) {
                $txId = $this->svc->pay(
                    (int) ($body['client_id'] ?? 0),
                    (int) ($body['amount_vnd'] ?? 0),
                    (int) ($body['account_id'] ?? 0),
                    $userEmail,
                    (string) ($body['comment'] ?? ''),
                );
                $flash['ok'] = "Выплата проведена (Poster transaction {$txId}).";
            }
        } catch (\Throwable $e) {
            $flash['err'] = $e->getMessage();
        }
    }

    /** @return array<string,string> social handles from the posted form */
    private function socialsFrom(array $body): array
    {
        return [
            'ig' => (string) ($body['ig'] ?? ''),
            'tg' => (string) ($body['tg'] ?? ''),
            'tt' => (string) ($body['tt'] ?? ''),
            'yt' => (string) ($body['yt'] ?? ''),
        ];
    }

    private function layout(ResponseInterface $response, string $content, string $path, string $userEmail, array $flash): ResponseInterface
    {
        $pageTitle = 'Блогеры';
        ob_start();
        $currentPath = $path;
        $flashOk     = $flash['ok'];
        $flashErr    = $flash['err'];
        require __DIR__ . '/../../Views/layout.php';
        $html = (string) ob_get_clean();

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}

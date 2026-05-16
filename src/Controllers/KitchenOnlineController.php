<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Infrastructure\Config;
use App\Services\KitchenOnlineService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class KitchenOnlineController
{
    public function __construct(private readonly KitchenOnlineService $service) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        date_default_timezone_set('Asia/Ho_Chi_Minh');

        $query  = $request->getQueryParams();
        $ajax   = $query['ajax'] ?? '';
        $action = $query['action'] ?? 'list';

        if ($ajax === '1') {
            return match ($action) {
                'exclude'      => $this->_handleExclude($request, $response),
                'set_logclose' => $this->_handleSetLogclose($request, $response),
                default        => $this->_handleList($request, $response),
            };
        }

        return $this->_handlePage($request, $response);
    }

    private function _handlePage(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $meta            = $this->service->getMeta();
        $lastSyncLabel   = $meta['last_sync'];
        $useLogicalClose = $meta['use_logical_close'];
        $pageTitle       = 'КухняОнлайн';
        $currentPath     = '/kitchen_online';
        $headExtra       = '<link rel="stylesheet" href="/assets/css/common.css">' . "\n"
                         . '<link rel="stylesheet" href="/assets/css/kitchen_online.css?v=20260516">' . "\n"
                         . '<script src="/assets/app.js" defer></script>';

        ob_start();
        require __DIR__ . '/../Views/kitchen_online_content.php';
        $content = (string)ob_get_clean();

        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = (string)ob_get_clean();

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    private function _handleList(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $meta       = $this->service->getMeta();
        $station    = $request->getQueryParams()['station'] ?? 'all';
        $waitLimit  = $this->service->getWaitLimitMinutes();
        $rows       = $this->service->getOpenItems($station, $meta['use_logical_close']);
        $cards      = $this->_groupByTransaction($rows);
        $tgConfig   = $this->_tgConfig();
        $canExclude = $this->_can('exclude_toggle');

        ob_start();
        require __DIR__ . '/../Views/partials/ko_cards.php';
        $html = (string)ob_get_clean();

        $payload = json_encode([
            'ok'                => true,
            'html'              => $html,
            'last_sync'         => $meta['last_sync'],
            'wait_limit_minutes' => $waitLimit,
        ], JSON_UNESCAPED_UNICODE);

        $response->getBody()->write((string)$payload);
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function _handleExclude(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->_can('exclude_toggle')) {
            $response->getBody()->write('{"ok":false,"error":"Forbidden"}');
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }

        $body   = (array)($request->getParsedBody() ?? []);
        $itemId = (int)($body['toggle_exclude_item'] ?? 0);
        if ($itemId > 0) $this->service->excludeItem($itemId);

        $response->getBody()->write(json_encode(['ok' => true, 'item_id' => $itemId], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function _handleSetLogclose(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $j   = json_decode((string)$request->getBody(), true);
        $use = isset($j['use']) ? (bool)$j['use'] : true;
        $this->service->setLogicalClose($use);

        $response->getBody()->write('{"ok":true}');
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function _groupByTransaction(array $rows): array
    {
        $cards = [];
        foreach ($rows as $r) {
            $txId = (int)($r['transaction_id'] ?? 0);
            if ($txId <= 0) continue;

            $cards[$txId] ??= [
                'transaction_id' => $txId,
                'receipt_number' => (string)($r['receipt_number'] ?? ''),
                'table_number'   => (string)($r['table_number'] ?? ''),
                'waiter_name'    => (string)($r['waiter_name'] ?? ''),
                'comment'        => trim((string)($r['transaction_comment'] ?? '')),
                'items'          => [],
            ];

            $sentTs = ($r['ticket_sent_at'] ?? '') !== '' ? (int)strtotime((string)$r['ticket_sent_at']) : 0;
            $cards[$txId]['items'][] = [
                'item_id'         => (int)($r['id'] ?? 0),
                'dish_name'       => (string)($r['dish_name'] ?? ''),
                'sent_ts'         => $sentTs,
                'sent_at'         => (string)($r['ticket_sent_at'] ?? ''),
                'tg_sent_at'      => (string)($r['tg_sent_at'] ?? ''),
                'tg_last_edit_at' => (string)($r['tg_last_edit_at'] ?? ''),
                'tg_message_id'   => (int)($r['tg_message_id'] ?? 0),
            ];
        }

        usort($cards, static fn($a, $b) => (int)$a['receipt_number'] <=> (int)$b['receipt_number']);
        return array_values($cards);
    }

    private function _tgConfig(): array
    {
        $chatId   = Config::get('TELEGRAM_CHAT_ID');
        $internal = '';
        if ($chatId !== '') {
            $tmp = str_starts_with($chatId, '-100') ? substr($chatId, 4) : ltrim($chatId, '-');
            $internal = ctype_digit($tmp) ? $tmp : '';
        }
        return [
            'username' => ltrim(Config::get('TELEGRAM_CHAT_USERNAME'), '@'),
            'thread'   => Config::int('TELEGRAM_THREAD_ID'),
            'internal' => $internal,
        ];
    }

    private function _can(string $perm): bool
    {
        $perms = $_SESSION['user_permissions'] ?? null;
        return !is_array($perms) || !empty($perms[$perm]);
    }
}

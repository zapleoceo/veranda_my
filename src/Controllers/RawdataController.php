<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\RawdataService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RawdataController
{
    public function __construct(private readonly RawdataService $service) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        date_default_timezone_set('Asia/Ho_Chi_Minh');

        $query  = $request->getQueryParams();
        $ajax   = $query['ajax'] ?? '';
        $body   = (array)($request->getParsedBody() ?? []);

        if ($ajax === '1' || $ajax === 'list') {
            return $this->_handleList($request, $response);
        }

        if ($request->getMethod() === 'POST' && isset($body['toggle_exclude_item'])) {
            return $this->_handleToggleExclude($request, $response);
        }

        if (($query['resync'] ?? '') === '1') {
            return $this->_handleResync($request, $response);
        }

        return $this->_handlePage($request, $response);
    }

    private function _handlePage(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();

        $selectedStatus = $query['status'] ?? 'all';
        $dateFrom       = $query['dateFrom'] ?? date('Y-m-d');
        $dateTo         = $query['dateTo'] ?? date('Y-m-d');
        $hourStart      = (int)($query['hourStart'] ?? 0);
        $hourEnd        = (int)($query['hourEnd'] ?? 23);
        $stationFilter  = $query['station'] ?? 'all';
        $lastSyncLabel  = $this->service->getLastSyncLabel();
        $userEmail      = (string)($_SESSION['user_email'] ?? '');

        $pageTitle   = 'Таблица';
        $currentPath = '/rawdata';
        $headExtra   = '<link rel="stylesheet" href="/assets/app.css">' . "\n"
                     . '<link rel="stylesheet" href="/assets/datepicker-range-dialog.css">' . "\n"
                     . '<link rel="stylesheet" href="/assets/css/common.css">' . "\n"
                     . '<link rel="stylesheet" href="/assets/css/rawdata.css">';

        ob_start();
        require __DIR__ . '/../Views/rawdata_content.php';
        $content = (string) ob_get_clean();

        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = (string) ob_get_clean();

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    private function _handleList(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $q        = $request->getQueryParams();
        $receipts = $this->service->getReceipts([
            'dateFrom'  => $q['dateFrom'] ?? date('Y-m-d'),
            'dateTo'    => $q['dateTo'] ?? date('Y-m-d'),
            'hourStart' => $q['hourStart'] ?? '0',
            'hourEnd'   => $q['hourEnd'] ?? '23',
            'status'    => $q['status'] ?? 'all',
            'station'   => $q['station'] ?? 'all',
            'sort'      => $q['sort'] ?? 'receipt',
            'dir'       => $q['dir'] ?? 'asc',
        ]);

        $payload = json_encode(['ok' => true, 'receipts' => $receipts], JSON_UNESCAPED_UNICODE);
        $response->getBody()->write((string)$payload);
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function _handleToggleExclude(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $perms = $_SESSION['user_permissions'] ?? null;
        if (is_array($perms) && empty($perms['exclude_toggle'])) {
            $response->getBody()->write('{"ok":false,"error":"Forbidden"}');
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }

        $body    = (array)($request->getParsedBody() ?? []);
        $itemId  = (int)($body['toggle_exclude_item'] ?? 0);
        $exclude = isset($body['exclude_from_dashboard']) ? 1 : 0;
        if ($itemId > 0) $this->service->toggleExclude($itemId, $exclude);

        $isXhr = strtolower($request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest';
        if ($isXhr) {
            $payload = json_encode(['ok' => true, 'item_id' => $itemId, 'exclude_from_dashboard' => $exclude], JSON_UNESCAPED_UNICODE);
            $response->getBody()->write((string)$payload);
            return $response->withHeader('Content-Type', 'application/json');
        }

        return $response->withHeader('Location', '/rawdata')->withStatus(302);
    }

    private function _handleResync(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $q        = $request->getQueryParams();
        $dateFrom = $q['dateFrom'] ?? date('Y-m-d');
        $dateTo   = $q['dateTo'] ?? date('Y-m-d');

        $this->service->startResync($dateFrom, $dateTo);

        $redirect = array_merge($q, ['resync_started' => '1']);
        unset($redirect['resync']);
        return $response->withHeader('Location', '/rawdata?' . http_build_query($redirect))->withStatus(302);
    }
}

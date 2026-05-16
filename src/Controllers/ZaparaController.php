<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class ZaparaController
{
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $perms = $_SESSION['user_permissions'] ?? null;
        if (is_array($perms) && empty($perms['zapara'])) {
            $response->getBody()->write('Forbidden');
            return $response->withStatus(403)->withHeader('Content-Type', 'text/plain');
        }

        date_default_timezone_set('Asia/Ho_Chi_Minh');

        $ajax = $request->getQueryParams()['ajax'] ?? '';
        if ($ajax !== '') {
            return $this->_handleAjax($request, $response, $ajax);
        }

        $today       = date('Y-m-d');
        $defaultFrom = date('Y-m-d', strtotime('-14 days'));
        $defaultTo   = $today;
        $pageTitle   = 'Zapara';
        $currentPath = '/zapara';
        $headExtra   = '<link rel="stylesheet" href="/assets/css/common.css">' . "\n"
                     . '<link rel="stylesheet" href="/assets/css/zapara.css">';

        ob_start();
        require __DIR__ . '/../Views/zapara_content.php';
        $content = (string) ob_get_clean();

        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = (string) ob_get_clean();

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    private function _handleAjax(ServerRequestInterface $request, ResponseInterface $response, string $ajax): ResponseInterface
    {
        $token = trim((string)($_ENV['POSTER_API_TOKEN'] ?? ''));
        if ($token === '') {
            $body = json_encode(['ok' => false, 'error' => 'POSTER_API_TOKEN не задан'], JSON_UNESCAPED_UNICODE);
            $response->getBody()->write((string)$body);
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }

        require_once __DIR__ . '/../../src/classes/PosterAPI.php';
        require_once __DIR__ . '/../../api/poster/zapara/Model.php';

        $api   = new \App\Classes\PosterAPI($token);
        $model = new \ApiPosterZaparaModel($api);

        $parseDate = static fn(string $s): ?string =>
            preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($s)) ? trim($s) : null;

        $json = function (int $status, array $data) use ($response): ResponseInterface {
            $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
            return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
        };

        if ($ajax === 'day') {
            $date = $parseDate((string)($request->getQueryParams()['date'] ?? ''));
            if ($date === null) return $json(400, ['ok' => false, 'error' => 'Bad request']);
            try {
                return $json(200, $model->day($date));
            } catch (\Throwable $e) {
                return $json(500, ['ok' => false, 'error' => $e->getMessage()]);
            }
        }

        if ($ajax === 'data') {
            $q    = $request->getQueryParams();
            $from = $parseDate((string)($q['date_from'] ?? ''));
            $to   = $parseDate((string)($q['date_to'] ?? ''));
            if ($from === null || $to === null || $from > $to) {
                return $json(400, ['ok' => false, 'error' => 'Bad request']);
            }
            try {
                return $json(200, $model->data($from, $to));
            } catch (\Throwable $e) {
                return $json(500, ['ok' => false, 'error' => $e->getMessage()]);
            }
        }

        return $json(404, ['ok' => false, 'error' => 'Unknown ajax action']);
    }
}

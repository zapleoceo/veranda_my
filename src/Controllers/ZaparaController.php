<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Infrastructure\Config;
use App\Infrastructure\Logger;
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
        // Read token via Config first (Bootstrap loads .env into Config::$_data
        // and also populates $_ENV — but some PHP-FPM workers can land here with
        // $_ENV partially missing, so Config::get is the safer primary source).
        $token = trim((string)(
            Config::get('POSTER_API_TOKEN')
            ?: ($_ENV['POSTER_API_TOKEN'] ?? '')
            ?: (getenv('POSTER_API_TOKEN') ?: '')
        ));
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

        // JSON emitter — wraps every response in an output buffer so that any
        // accidental PHP notice / warning from underlying code (Poster API
        // adapter, DateTime, etc.) doesn't get prepended to the body and
        // break the front-end JSON.parse with a SyntaxError.
        $json = function (int $status, array $data) use ($response): ResponseInterface {
            // Drain any unexpected output that landed before us.
            while (ob_get_level() > 0) { @ob_end_clean(); }
            $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
            return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
        };

        // Centralised error capture — every Throwable gets logged with the
        // ajax action + parameters so the next 500 has a server-side trail
        // instead of just being a generic "Ошибка (500)" on the client.
        $errorReply = function (\Throwable $e, string $action, array $ctx) use ($json): ResponseInterface {
            try {
                Logger::get()->error('[zapara] ' . $action . ': ' . $e->getMessage(), $ctx + [
                    'exception' => get_class($e),
                    'file'      => $e->getFile(),
                    'line'      => $e->getLine(),
                ]);
            } catch (\Throwable $_) {
                error_log('[zapara] ' . $action . ': ' . $e->getMessage() . ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE));
            }
            return $json(500, ['ok' => false, 'error' => $e->getMessage()]);
        };

        if ($ajax === 'day') {
            $date = $parseDate((string)($request->getQueryParams()['date'] ?? ''));
            if ($date === null) return $json(400, ['ok' => false, 'error' => 'Bad request']);
            try {
                return $json(200, $model->day($date));
            } catch (\Throwable $e) {
                return $errorReply($e, 'day', ['date' => $date]);
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
                return $errorReply($e, 'data', ['from' => $from, 'to' => $to]);
            }
        }

        return $json(404, ['ok' => false, 'error' => 'Unknown ajax action']);
    }
}

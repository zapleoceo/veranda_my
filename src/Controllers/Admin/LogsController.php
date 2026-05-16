<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class LogsController
{
    private const LOG_KEYS = ['kitchen', 'telegram', 'menu', 'php'];

    private const SYNC_JOBS = [
        'kitchen'  => ['label' => 'Kitchen sync',     'cron' => '*/5 * * * *'],
        'telegram' => ['label' => 'Telegram alerts',  'cron' => '*/1 * * * *'],
        'menu'     => ['label' => 'Menu sync',        'cron' => '0 * * * *'],
    ];

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userEmail = $request->getAttribute('user_email', '');
        $query     = $request->getQueryParams();
        $body      = $request->getParsedBody() ?? [];
        $flash     = ['ok' => '', 'err' => ''];

        $view  = in_array($query['view'] ?? '', self::LOG_KEYS, true) ? $query['view'] : 'kitchen';
        $lines = max(50, min(800, (int) ($query['lines'] ?? 200)));

        $logMap  = $this->_logMap();
        $action  = (string) (is_array($body) ? ($body['action'] ?? '') : '');

        if ($request->getMethod() === 'POST' && $action === 'run_sync') {
            return $this->_runSync((string) (is_array($body) ? ($body['key'] ?? '') : ''), $view, $lines, $response);
        }

        if ($request->getMethod() === 'POST' && $action === 'clear_log') {
            @file_put_contents($logMap[$view], '');
            $flash['ok'] = 'Лог очищен.';
        }

        $content_raw = $this->_tailFile($logMap[$view] ?? '', $lines);

        ob_start();
        $syncJobs = self::SYNC_JOBS;
        $fileInfo = fn(string $p) => is_file($p)
            ? ['exists' => true, 'mtime' => (int) @filemtime($p), 'size' => (int) @filesize($p)]
            : ['exists' => false, 'mtime' => null, 'size' => null];
        require __DIR__ . '/../../Views/admin/logs.php';
        $content = ob_get_clean();

        return $this->_layout($response, (string) $content, '/admin/logs', $userEmail, $flash);
    }

    private function _runSync(string $key, string $view, int $lines, ResponseInterface $response): ResponseInterface
    {
        $jobs = $this->_syncCmds();
        if (isset($jobs[$key])) {
            @set_time_limit(30);
            @session_write_close();
            @exec($jobs[$key]);
        }
        return $response
            ->withHeader('Location', "/admin/logs?view={$view}&lines={$lines}&ran={$key}")
            ->withStatus(302);
    }

    private function _logMap(): array
    {
        $base = dirname(__DIR__, 3);
        return [
            'kitchen'  => $base . '/cron.log',
            'telegram' => $base . '/telegram.log',
            'menu'     => $base . '/menu_sync.log',
            'php'      => $base . '/php_errors.log',
        ];
    }

    private function _syncCmds(): array
    {
        $php  = '/opt/php82/bin/php';
        $base = dirname(__DIR__, 3);
        return [
            'kitchen'  => "{$php} {$base}/cron/kitchen_sync.php >> {$base}/cron.log 2>&1",
            'telegram' => "{$php} {$base}/cron/telegram_alerts.php >> {$base}/telegram.log 2>&1",
        ];
    }

    private function _tailFile(string $path, int $maxLines): string
    {
        if (!is_file($path)) { return ''; }
        $data = @file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($data)) { return ''; }
        if (count($data) > $maxLines) {
            $data = array_slice($data, -$maxLines);
        }
        return implode("\n", array_reverse($data));
    }

    private function _layout(ResponseInterface $response, string $content, string $path, string $userEmail, array $flash): ResponseInterface
    {
        $pageTitle = 'Логи';
        ob_start();
        $currentPath = $path;
        $flashOk  = $flash['ok'];
        $flashErr = $flash['err'];
        require __DIR__ . '/../../Views/layout.php';
        $html = ob_get_clean();
        $response->getBody()->write((string) $html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}

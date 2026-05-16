<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Infrastructure\Config;
use App\Infrastructure\Database;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class SyncController
{
    private const SYNC_DEFS = [
        [
            'label'      => 'Kitchen sync',
            'at_key'     => 'kitchen_last_sync_at',
            'result_key' => 'kitchen_last_sync_result',
            'error_key'  => 'kitchen_last_sync_error',
            'desc'       => 'Синхронизирует чеки/позиции кухни из Poster в kitchen_stats.',
        ],
        [
            'label'      => 'Telegram alerts',
            'at_key'     => 'telegram_last_run_at',
            'result_key' => 'telegram_last_run_result',
            'error_key'  => 'telegram_last_run_error',
            'desc'       => 'Отправляет/обновляет уведомления в Telegram по долгим блюдам.',
        ],
        [
            'label'      => 'Kitchen resync job',
            'at_key'     => 'kitchen_resync_job_last_update_at',
            'result_key' => 'kitchen_resync_job_progress',
            'error_key'  => 'kitchen_resync_job_error',
            'desc'       => 'Фоновый пересинк кухни за диапазон дат.',
        ],
        [
            'label'      => 'Menu sync',
            'at_key'     => 'menu_last_sync_at',
            'result_key' => 'menu_last_sync_result',
            'error_key'  => 'menu_last_sync_error',
            'desc'       => 'Синхронизирует меню из Poster в poster_menu_items.',
        ],
    ];

    public function __construct(private readonly Database $db) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userEmail    = $request->getAttribute('user_email', '');
        $flash        = ['ok' => '', 'err' => ''];
        $body         = (array) ($request->getParsedBody() ?? []);
        $runResultHtml = '';

        if ($request->getMethod() === 'POST' && isset($body['run_script'])) {
            $runResultHtml = $this->_runScript($body);
        }

        $meta     = $this->_loadMeta();
        $canExec  = $this->_canExec();
        $syncDefs = self::SYNC_DEFS;

        ob_start();
        require __DIR__ . '/../../Views/admin/sync.php';
        $content = ob_get_clean();

        return $this->_layout($response, (string) $content, $userEmail, $flash);
    }

    public function start(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write(json_encode(['ok' => false, 'error' => 'use POST /admin/sync form']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function _loadMeta(): array
    {
        $meta = [];
        foreach (self::SYNC_DEFS as $d) {
            foreach (['at_key', 'result_key', 'error_key'] as $f) {
                $key = $d[$f];
                try {
                    $row = $this->db->query(
                        "SELECT meta_value FROM {$this->db->t('system_meta')} WHERE meta_key = ? LIMIT 1",
                        [$key]
                    )->fetch();
                    $meta[$key] = $row ? (string) $row['meta_value'] : '';
                } catch (\Throwable) {
                    $meta[$key] = '';
                }
            }
        }
        return $meta;
    }

    private function _canExec(): bool
    {
        if (!function_exists('exec')) { return false; }
        $disabled = strtolower((string) ini_get('disable_functions'));
        return $disabled === '' || !str_contains($disabled, 'exec');
    }

    private function _runScript(array $body): string
    {
        if (!$this->_canExec()) {
            return '<div class="msg-err">exec() отключён — запустить нельзя.</div>';
        }

        $script   = (string) ($body['script_name'] ?? '');
        $dateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $body['date_from'] ?? '') ? $body['date_from'] : date('Y-m-d');
        $dateTo   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $body['date_to'] ?? '')   ? $body['date_to']   : date('Y-m-d');

        $php  = '/opt/php82/bin/php';
        $base = dirname(__DIR__, 3);

        $cmds = [
            'kitchen_cron'          => ["{$php} {$base}/cron/kitchen_sync.php 2>&1", false],
            'tg_alerts'             => ["{$php} {$base}/cron/telegram_alerts.php 2>&1", false],
            'kitchen_resync_range'  => [
                "{$php} {$base}/cron/kitchen_sync.php " . escapeshellarg($dateFrom) . ' ' . escapeshellarg($dateTo) . " >> {$base}/resync_range.log 2>&1 & echo \$!",
                true,
            ],
        ];

        if (!isset($cmds[$script])) {
            return '<div class="msg-err">Неизвестный скрипт.</div>';
        }

        [$cmd, $isBackground] = $cmds[$script];
        $out = [];
        $code = 0;
        exec($cmd, $out, $code);

        if (count($out) > 200) { $out = array_slice($out, -200); }

        $text = $isBackground
            ? "Запущен в фоне (PID: " . implode('', $out) . ")"
            : "exit={$code}\n" . implode("\n", $out);

        return '<pre style="background:#0f172a;color:#e2e8f0;padding:1rem;border-radius:6px;font-size:.75rem;white-space:pre-wrap;word-break:break-all;max-height:300px;overflow:auto;margin-top:.75rem">'
            . htmlspecialchars($text) . '</pre>';
    }

    private function _layout(ResponseInterface $response, string $content, string $userEmail, array $flash): ResponseInterface
    {
        $pageTitle = 'Синк';
        ob_start();
        $currentPath = '/admin/sync';
        $flashOk  = $flash['ok'];
        $flashErr = $flash['err'];
        require __DIR__ . '/../../Views/layout.php';
        $html = ob_get_clean();
        $response->getBody()->write((string) $html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}

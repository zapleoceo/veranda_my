<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Infrastructure\Config;
use App\Infrastructure\Database;
use App\Infrastructure\TelegramBotClient;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class TelegramAdminController
{
    private const SETTING_KEYS = [
        'alert_timing_low_load'      => 20,
        'alert_load_threshold'       => 25,
        'alert_timing_high_load'     => 30,
        'alert_ack_snooze_minutes'   => 15,
        'exclude_partners_from_load' => 0,
    ];

    public function __construct(
        private readonly Database $db,
        private readonly TelegramBotClient $bot,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userEmail = $request->getAttribute('user_email', '');
        $flash     = ['ok' => '', 'err' => ''];
        $body      = (array) ($request->getParsedBody() ?? []);
        $query     = $request->getQueryParams();

        if ($request->getMethod() === 'POST' && isset($body['save_settings'])) {
            $this->_saveSettings($body, $flash);
        }

        if (($query['ajax'] ?? '') === 'telegram_test') {
            return $this->_ajaxTest($request, $response);
        }

        $settings    = $this->_loadSettings();
        $telegramMeta = $this->_loadRunMeta();

        ob_start();
        require __DIR__ . '/../../Views/admin/telegram.php';
        $content = ob_get_clean();

        return $this->_layout($response, (string) $content, $userEmail, $flash);
    }

    private function _saveSettings(array $body, array &$flash): void
    {
        try {
            foreach (self::SETTING_KEYS as $key => $default) {
                $val = isset($body[$key]) ? (int) $body[$key] : 0;
                $this->db->query(
                    "INSERT INTO {$this->db->t('system_meta')} (meta_key, meta_value) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = CURRENT_TIMESTAMP",
                    [$key, (string) $val]
                );
            }
            $flash['ok'] = 'Настройки сохранены.';
        } catch (\Throwable $e) {
            $flash['err'] = $e->getMessage();
        }
    }

    private function _ajaxTest(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $text = trim((string) ($body['text'] ?? 'Тест: статус проверки'));

        try {
            $chatId   = (int) Config::require('TELEGRAM_CHAT_ID');
            $threadId = Config::get('TELEGRAM_THREAD_ID') !== '' ? (int) Config::get('TELEGRAM_THREAD_ID') : null;
            $msgId    = $this->bot->sendMessage($chatId, $text, $threadId ? ['message_thread_id' => $threadId] : []);
            $payload  = json_encode(['ok' => true, 'message_id' => $msgId]);
        } catch (\Throwable $e) {
            $payload = json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }

        $response->getBody()->write((string) $payload);
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function _loadSettings(): array
    {
        $settings = [];
        foreach (self::SETTING_KEYS as $key => $default) {
            try {
                $row = $this->db->query(
                    "SELECT meta_value FROM {$this->db->t('system_meta')} WHERE meta_key = ? LIMIT 1",
                    [$key]
                )->fetch();
                $settings[$key] = $row ? (int) $row['meta_value'] : $default;
            } catch (\Throwable) {
                $settings[$key] = $default;
            }
        }
        return $settings;
    }

    private function _loadRunMeta(): array
    {
        $meta = [];
        foreach (['telegram_last_run_at', 'telegram_last_run_result', 'telegram_last_run_error'] as $k) {
            try {
                $row = $this->db->query(
                    "SELECT meta_value FROM {$this->db->t('system_meta')} WHERE meta_key = ? LIMIT 1",
                    [$k]
                )->fetch();
                $meta[$k] = $row ? (string) $row['meta_value'] : '';
            } catch (\Throwable) {
                $meta[$k] = '';
            }
        }
        return $meta;
    }

    private function _layout(ResponseInterface $response, string $content, string $userEmail, array $flash): ResponseInterface
    {
        ob_start();
        $currentPath = '/admin/telegram';
        $flashOk  = $flash['ok'];
        $flashErr = $flash['err'];
        require __DIR__ . '/../../Views/layout.php';
        $html = ob_get_clean();
        $response->getBody()->write((string) $html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}

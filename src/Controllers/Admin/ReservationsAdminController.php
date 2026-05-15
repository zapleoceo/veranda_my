<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Infrastructure\Database;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class ReservationsAdminController
{
    private const META_KEY = 'reservations_config';

    public function __construct(private readonly Database $db) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userEmail = $request->getAttribute('user_email', '');
        $flash     = ['ok' => '', 'err' => ''];
        $body      = $request->getParsedBody() ?? [];

        $config = $this->_loadConfig();

        if ($request->getMethod() === 'POST' && isset($body['save_config'])) {
            $config = $this->_saveConfig((array) $body, $flash);
        }

        ob_start();
        require __DIR__ . '/../../Views/admin/reservations.php';
        $content = ob_get_clean();

        return $this->_layout($response, (string) $content, '/admin/reservations', $userEmail, $flash);
    }

    private function _loadConfig(): array
    {
        try {
            $row = $this->db->query(
                "SELECT meta_value FROM {$this->db->t('system_meta')} WHERE meta_key = ? LIMIT 1",
                [self::META_KEY]
            )->fetch();
            if ($row) {
                return json_decode((string) $row['meta_value'], true) ?: [];
            }
        } catch (\Throwable) {}
        return [];
    }

    private function _saveConfig(array $body, array &$flash): array
    {
        $tables = array_filter(
            array_map('trim', explode(',', (string) ($body['tables'] ?? ''))),
            fn($v) => $v !== ''
        );
        $config = [
            'tables'           => array_values($tables),
            'soon_hours'       => max(0, min(24, (int) ($body['soon_hours'] ?? 2))),
            'latest_workday'   => trim((string) ($body['latest_workday'] ?? '21:00')),
            'latest_weekend'   => trim((string) ($body['latest_weekend'] ?? '22:00')),
        ];
        try {
            $this->db->query(
                "INSERT INTO {$this->db->t('system_meta')} (meta_key, meta_value)
                 VALUES (?, ?) ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = CURRENT_TIMESTAMP",
                [self::META_KEY, json_encode($config, JSON_UNESCAPED_UNICODE)]
            );
            $flash['ok'] = 'Настройки сохранены.';
        } catch (\Throwable $e) {
            $flash['err'] = $e->getMessage();
        }
        return $config;
    }

    private function _layout(ResponseInterface $response, string $content, string $path, string $userEmail, array $flash): ResponseInterface
    {
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

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
        $query     = $request->getQueryParams();

        // CSV-экспорт клиентов — стримим напрямую, без layout/HTML.
        if (($query['export'] ?? '') === 'clients') {
            return $this->_exportClientsCsv($response);
        }

        $config = $this->_loadConfig();

        if ($request->getMethod() === 'POST' && isset($body['save_config'])) {
            $config = $this->_saveConfig((array) $body, $flash);
        }

        $clients = $this->_loadClients();

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

    /**
     * Уникальные клиенты, агрегированные по основному телефону. Без
     * удалённых броней (soft-delete через `deleted_at`). За «имя» берём
     * самое свежее по start_time — у одного телефона за год имя могло
     * быть введено в разных вариантах, последнее обычно корректнее.
     *
     * Поля в возвращаемых рядах:
     *   phone, name, reservations_count, last_reservation, first_seen,
     *   whatsapp_phone, zalo_phone, lang
     */
    private function _loadClients(): array
    {
        $t = $this->db->t('reservations');
        try {
            return $this->db->query("
                SELECT
                    r.phone,
                    /* последнее использованное имя — берём имя из самой
                       свежей не-удалённой записи этого телефона */
                    (SELECT r2.name
                       FROM {$t} r2
                       WHERE r2.phone = r.phone
                         AND r2.deleted_at IS NULL
                       ORDER BY r2.start_time DESC
                       LIMIT 1) AS name,
                    COUNT(*)              AS reservations_count,
                    MAX(r.start_time)     AS last_reservation,
                    MIN(r.created_at)     AS first_seen,
                    MAX(r.whatsapp_phone) AS whatsapp_phone,
                    MAX(r.zalo_phone)     AS zalo_phone,
                    MAX(r.lang)           AS lang
                FROM {$t} r
                WHERE r.phone <> '' AND r.deleted_at IS NULL
                GROUP BY r.phone
                ORDER BY MAX(r.start_time) DESC
            ")->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    private function _exportClientsCsv(ResponseInterface $response): ResponseInterface
    {
        $clients = $this->_loadClients();

        // In-memory stream + fputcsv — экранирование кавычек/разделителей
        // делает сам PHP, без шанса сломать CSV запятой в имени.
        $fh = fopen('php://temp', 'w+');
        // BOM чтобы Excel под Windows открыл UTF-8 корректно.
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, [
            'Имя', 'Телефон', 'WhatsApp', 'Zalo',
            'Кол-во броней', 'Последняя бронь', 'Первая бронь', 'Язык',
        ]);
        foreach ($clients as $c) {
            fputcsv($fh, [
                (string)($c['name']               ?? ''),
                (string)($c['phone']              ?? ''),
                (string)($c['whatsapp_phone']     ?? ''),
                (string)($c['zalo_phone']         ?? ''),
                (string)($c['reservations_count'] ?? ''),
                (string)($c['last_reservation']   ?? ''),
                (string)($c['first_seen']         ?? ''),
                (string)($c['lang']               ?? ''),
            ]);
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        $filename = 'veranda-clients-' . date('Y-m-d') . '.csv';
        $response->getBody()->write((string) $csv);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    private function _layout(ResponseInterface $response, string $content, string $path, string $userEmail, array $flash): ResponseInterface
    {
        $pageTitle = 'Брони — настройки';
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

<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Infrastructure\Database;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class AccessController
{
    private const PERMISSION_KEYS = [
        'dashboard'      => 'Дашборд',
        'rawdata'        => 'Сырые данные',
        'kitchen_online' => 'КухняOnline',
        'errors'         => 'Cooked (errors)',
        'zapara'         => 'Zapara',
        'employees'      => 'ЗП сотрудников',
        'payday'         => 'Payday',
        'admin'          => 'УПРАВЛЕНИЕ',
        'roma'           => 'Roma (кальяны)',
        'banya'          => 'Отчет баня',
        'reservations'   => 'Брони',
        'vposter_button' => 'Кнопка "Бронь в Постере"',
        'exclude_toggle' => 'Игнор + ✅ Принято',
        'telegram_ack'   => '✅ Принято (Telegram)',
    ];

    public function __construct(private readonly Database $db) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userEmail = $request->getAttribute('user_email', '');
        $flash = ['ok' => '', 'err' => ''];
        $body  = $request->getParsedBody() ?? [];
        $query = $request->getQueryParams();

        $users = $this->_getUsers($flash);
        $this->_handlePost((array) $body, $userEmail, $flash, $users);
        $this->_handleDelete($query, $userEmail, $flash);

        if ($flash['ok'] || $flash['err']) {
            $users = $this->_getUsers($flash);
        }

        ob_start();
        $permissionKeys = self::PERMISSION_KEYS;
        require __DIR__ . '/../../Views/admin/access.php';
        $content = ob_get_clean();

        return $this->_layout($response, $content, '/admin/access', $userEmail, $flash);
    }

    public function save(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $response->withHeader('Location', '/admin/access')->withStatus(302);
    }

    private function _handlePost(array $body, string $selfEmail, array &$flash, array &$users): void
    {
        if (isset($body['save_user_permissions'])) {
            $email = trim((string) ($body['perm_email'] ?? ''));
            if ($email === '') { return; }
            $perms = [];
            foreach (self::PERMISSION_KEYS as $k => $_) {
                $perms[$k] = isset($body['perm_' . $k]) ? 1 : 0;
            }
            $perms['telegram_ack'] = !empty($perms['exclude_toggle']) ? 1 : 0;
            $tg = strtolower(ltrim(trim((string) ($body['perm_tg_username'] ?? '')), '@'));
            $this->db->query(
                "UPDATE {$this->db->t('users')} SET permissions_json = ?, telegram_username = ? WHERE email = ? LIMIT 1",
                [json_encode($perms, JSON_UNESCAPED_UNICODE), $tg ?: null, $email]
            );
            $flash['ok'] = "Права для {$email} сохранены.";
        }

        if (isset($body['add_email'])) {
            $email = trim((string) ($body['email'] ?? ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $flash['err'] = 'Некорректный email.';
                return;
            }
            try {
                $empty = array_map(fn() => 0, self::PERMISSION_KEYS);
                $this->db->query(
                    "INSERT INTO {$this->db->t('users')} (email, is_active, permissions_json) VALUES (?, 1, ?)",
                    [$email, json_encode($empty, JSON_UNESCAPED_UNICODE)]
                );
                $flash['ok'] = "Пользователь {$email} добавлен.";
            } catch (\Throwable $e) {
                $flash['err'] = 'Ошибка: ' . $e->getMessage();
            }
        }
    }

    private function _handleDelete(array $query, string $selfEmail, array &$flash): void
    {
        $del = trim((string) ($query['delete'] ?? ''));
        if ($del === '') { return; }
        if ($del === $selfEmail) {
            $flash['err'] = 'Нельзя удалить свой аккаунт.';
            return;
        }
        $this->db->query("DELETE FROM {$this->db->t('users')} WHERE email = ? LIMIT 1", [$del]);
        $flash['ok'] = "Пользователь {$del} удалён.";
    }

    private function _getUsers(array &$flash): array
    {
        try {
            return $this->db->query(
                "SELECT email, name, telegram_username, permissions_json, created_at
                 FROM {$this->db->t('users')} ORDER BY created_at DESC"
            )->fetchAll();
        } catch (\Throwable $e) {
            $flash['err'] = 'Ошибка чтения: ' . $e->getMessage();
            return [];
        }
    }

    private function _layout(
        ResponseInterface $response,
        string $content,
        string $path,
        string $userEmail,
        array $flash
    ): ResponseInterface {
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

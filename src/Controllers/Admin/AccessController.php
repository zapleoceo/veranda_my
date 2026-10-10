<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Infrastructure\Config;
use App\Infrastructure\Database;
use App\Infrastructure\Permissions;
use App\Infrastructure\TelegramUserDirectory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class AccessController
{
    private const PERMISSION_KEYS = [
        'dashboard'      => 'Дашборд',
        'rawdata'        => 'Сырые данные',
        'kitchen_online' => 'КухняOnline',
        'neworder'       => 'Заказ Менеджера',
        'errors'         => 'Cooked (errors)',
        'zapara'         => 'Zapara',
        'employees'      => 'ЗП сотрудников',
        'schedule'       => 'График смен',
        'payday'         => 'Payday',
        'bloggers'       => 'Блогеры',
        'admin'          => 'УПРАВЛЕНИЕ',
        'roma'           => 'Roma (кальяны)',
        'banya'          => 'Отчет баня',
        'reservations'   => 'Брони',
        'vposter_button' => 'Кнопка "Бронь в Постере"',
        'exclude_toggle' => 'Игнор + ✅ Принято',
        'telegram_ack'   => '✅ Принято (Telegram)',
        'aibot_finance'  => 'Бот: финансы (Telegram)',
    ];

    public function __construct(private readonly Database $db) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // Гейт по праву `admin`. Раньше страница была закрыта только
        // AuthMiddleware (=любой залогиненный), а _handlePost пишет права
        // ЛЮБОМУ email из формы. То есть сотрудник с одним лишь `dashboard`
        // мог отправить save_user_permissions с perm_email=<свой> и
        // perm_admin=1 — и стать админом. Это escalation of privilege,
        // а не просто лишняя видимость страницы.
        if (!Permissions::can('admin')) {
            return Permissions::denyHtml($response);
        }

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
        $lastLogin      = $this->_getPosterLastLogin();
        require __DIR__ . '/../../Views/admin/access.php';
        $content = ob_get_clean();

        return $this->_layout($response, $content, '/admin/access', $userEmail, $flash);
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
            // Числовой Telegram id — проверенная связка для бота (право aibot_finance).
            $tgIdRaw = trim((string) ($body['perm_tg_user_id'] ?? ''));
            if ($tgIdRaw !== '' && !preg_match('/^[1-9][0-9]{0,14}$/', $tgIdRaw)) {
                $flash['err'] = 'Telegram ID должен быть числом.';
                return;
            }
            $tgId = $tgIdRaw === '' ? null : (int) $tgIdRaw;
            (new TelegramUserDirectory($this->db))->ensureSchema();
            try {
                $this->db->query(
                    "UPDATE {$this->db->t('users')} SET permissions_json = ?, telegram_username = ?, telegram_user_id = ? WHERE email = ? LIMIT 1",
                    [json_encode($perms, JSON_UNESCAPED_UNICODE), $tg ?: null, $tgId, $email]
                );
            } catch (\Throwable $e) {
                $flash['err'] = str_contains($e->getMessage(), 'Duplicate')
                    ? 'Этот Telegram ID уже привязан к другому пользователю.'
                    : 'Ошибка сохранения.';
                return;
            }
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
            (new TelegramUserDirectory($this->db))->ensureSchema();
            return $this->db->query(
                "SELECT email, name, telegram_username, telegram_user_id, permissions_json, created_at
                 FROM {$this->db->t('users')} ORDER BY created_at DESC"
            )->fetchAll();
        } catch (\Throwable $e) {
            $flash['err'] = 'Ошибка чтения: ' . $e->getMessage();
            return [];
        }
    }

    /**
     * Карта email(login) → last_in из Poster (последний вход сотрудника в
     * Poster). Сопоставляем по email в нижнем регистре. Если Poster недоступен
     * или токен не задан — отдаём пустую карту, страница не падает.
     *
     * @return array<string,string>
     */
    private function _getPosterLastLogin(): array
    {
        $token = trim((string) (
            Config::get('POSTER_API_TOKEN')
            ?: ($_ENV['POSTER_API_TOKEN'] ?? '')
            ?: (getenv('POSTER_API_TOKEN') ?: '')
        ));
        if ($token === '') {
            return [];
        }
        try {
            require_once __DIR__ . '/../../classes/PosterAPI.php';
            $api  = new \App\Classes\PosterAPI($token);
            $rows = $api->request('access.getEmployees', [], 'GET');
            $map  = [];
            if (is_array($rows)) {
                foreach ($rows as $e) {
                    if (!is_array($e)) {
                        continue;
                    }
                    $login  = strtolower(trim((string) ($e['login'] ?? '')));
                    $lastIn = trim((string) ($e['last_in'] ?? ''));
                    if ($login !== '' && $lastIn !== '') {
                        $map[$login] = $lastIn;
                    }
                }
            }
            return $map;
        } catch (\Throwable $e) {
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
        $pageTitle = match ($path) {
            '/admin/access' => 'Доступ',
            default         => 'Admin',
        };
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

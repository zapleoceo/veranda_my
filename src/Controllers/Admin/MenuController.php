<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Infrastructure\Config;
use App\Infrastructure\Database;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class MenuController
{
    public function __construct(private readonly Database $db) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userEmail = $request->getAttribute('user_email', '');
        $flash     = ['ok' => '', 'err' => ''];
        $query     = $request->getQueryParams();
        $body      = (array) ($request->getParsedBody() ?? []);

        // AJAX: toggle publish
        if (($query['ajax'] ?? '') === 'toggle_publish') {
            return $this->_ajaxTogglePublish($request, $response);
        }

        // AJAX: save edit
        if (($query['ajax'] ?? '') === 'save_edit') {
            return $this->_ajaxSaveEdit($request, $response);
        }

        // Sync menu from Poster
        if ($request->getMethod() === 'POST' && isset($body['sync_menu'])) {
            $this->_syncMenu($flash);
        }

        $view = in_array($query['view'] ?? '', ['list', 'edit'], true) ? $query['view'] : 'list';

        if ($view === 'edit') {
            $item = $this->_loadItem((int) ($query['id'] ?? 0));
            if (!$item) {
                return $response->withHeader('Location', '/admin/menu')->withStatus(302);
            }
            ob_start();
            require __DIR__ . '/../../Views/admin/menu_edit.php';
            $content = ob_get_clean();
        } else {
            [$items, $total, $pages, $page] = $this->_loadList($query);
            $syncMeta = $this->_loadSyncMeta();
            ob_start();
            require __DIR__ . '/../../Views/admin/menu_list.php';
            $content = ob_get_clean();
        }

        return $this->_layout($response, (string) $content, $userEmail, $flash);
    }

    private function _loadList(array $q): array
    {
        $perPage = 50;
        $page    = max(1, (int) ($q['page'] ?? 1));
        $offset  = ($page - 1) * $perPage;

        $search    = trim((string) ($q['q'] ?? ''));
        $status    = $q['status'] ?? 'published';
        $workshop  = $q['workshop_id'] ?? '';

        $pmi  = $this->db->t('poster_menu_items');
        $mi   = $this->db->t('menu_items');
        $miTr = $this->db->t('menu_item_tr');

        // Схема: menu_items.poster_item_id -> poster_menu_items.id;
        // переводы лежат в menu_item_tr (item_id = menu_items.id, по языкам).
        $joins = "FROM {$pmi} pmi
                  LEFT JOIN {$mi} mi ON mi.poster_item_id = pmi.id
                  LEFT JOIN {$miTr} itr_ru ON itr_ru.item_id = mi.id AND itr_ru.lang = 'ru'
                  LEFT JOIN {$miTr} itr_en ON itr_en.item_id = mi.id AND itr_en.lang = 'en'";

        $where  = ['pmi.is_active = 1'];
        $params = [];

        if ($search !== '') {
            $where[]  = "(pmi.name_raw LIKE ? OR itr_ru.title LIKE ? OR itr_en.title LIKE ? OR CAST(pmi.poster_id AS CHAR) LIKE ?)";
            $like     = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        if ($status === 'published') {
            $where[] = 'COALESCE(mi.is_published, 0) = 1';
        } elseif ($status === 'unpublished') {
            $where[] = 'COALESCE(mi.is_published, 0) = 0';
        }

        if ($workshop !== '') {
            $where[]  = 'pmi.station_id = ?';
            $params[] = (int) $workshop;
        }

        $whereStr = implode(' AND ', $where);

        try {
            $total = (int) $this->db->query(
                "SELECT COUNT(*) {$joins} WHERE {$whereStr}",
                $params
            )->fetchColumn();

            $items = $this->db->query(
                "SELECT pmi.poster_id,
                        COALESCE(NULLIF(itr_ru.title,''), pmi.name_raw, '') AS title_ru,
                        COALESCE(itr_en.title, '') AS title_en,
                        pmi.price_raw AS price,
                        pmi.station_name AS workshop_name,
                        COALESCE(NULLIF(pmi.sub_category_name,''), pmi.main_category_name, '') AS category_name,
                        COALESCE(mi.is_published, 0) AS is_published,
                        mi.id AS menu_item_id
                 {$joins}
                 WHERE {$whereStr}
                 ORDER BY pmi.name_raw ASC
                 LIMIT {$perPage} OFFSET {$offset}",
                $params
            )->fetchAll();
        } catch (\Throwable $e) {
            $items = [];
            $total = 0;
        }

        $pages = $total > 0 ? (int) ceil($total / $perPage) : 1;
        return [$items, $total, $pages, $page];
    }

    private function _loadItem(int $posterId): array|false
    {
        $pmi = $this->db->t('poster_menu_items');
        $mi  = $this->db->t('menu_items');
        $tr  = $this->db->t('menu_item_tr');

        try {
            $item = $this->db->query(
                "SELECT pmi.poster_id,
                        pmi.name_raw AS title_ru,
                        pmi.price_raw AS price,
                        pmi.station_name AS workshop_name,
                        COALESCE(NULLIF(pmi.sub_category_name,''), pmi.main_category_name, '') AS category_name,
                        COALESCE(mi.is_published, 0) AS is_published,
                        mi.id AS menu_item_id
                 FROM {$pmi} pmi LEFT JOIN {$mi} mi ON mi.poster_item_id = pmi.id
                 WHERE pmi.poster_id = ? LIMIT 1",
                [$posterId]
            )->fetch();

            if (!$item) { return false; }

            $translations = [];
            if (!empty($item['menu_item_id'])) {
                $translations = $this->db->query(
                    "SELECT lang, title, description FROM {$tr} WHERE item_id = ?",
                    [(int) $item['menu_item_id']]
                )->fetchAll(\PDO::FETCH_UNIQUE | \PDO::FETCH_ASSOC);
            }

            $item['translations'] = $translations;
            return $item;
        } catch (\Throwable) {
            return false;
        }
    }

    private function _ajaxTogglePublish(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body     = (array) ($request->getParsedBody() ?? []);
        $posterId = (int) ($body['poster_id'] ?? 0);
        $publish  = (int) ($body['publish'] ?? 0);

        try {
            $pmi = $this->db->t('poster_menu_items');
            $mi  = $this->db->t('menu_items');

            $row = $this->db->query("SELECT id FROM {$pmi} WHERE poster_id = ? LIMIT 1", [$posterId])->fetch();
            $posterItemId = (int) ($row['id'] ?? 0);
            if ($posterItemId <= 0) {
                throw new \RuntimeException('Товар не найден (poster_id=' . $posterId . ')');
            }

            $this->db->query(
                "INSERT INTO {$mi} (poster_item_id, is_published) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE is_published = VALUES(is_published)",
                [$posterItemId, $publish ? 1 : 0]
            );
            $payload = json_encode(['ok' => true]);
        } catch (\Throwable $e) {
            $payload = json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }

        $response->getBody()->write((string) $payload);
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function _ajaxSaveEdit(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body     = (array) ($request->getParsedBody() ?? []);
        $posterId = (int) ($body['poster_id'] ?? 0);

        try {
            $pmi  = $this->db->t('poster_menu_items');
            $mi   = $this->db->t('menu_items');
            $tr   = $this->db->t('menu_item_tr');

            $row = $this->db->query("SELECT id FROM {$pmi} WHERE poster_id = ? LIMIT 1", [$posterId])->fetch();
            $posterItemId = (int) ($row['id'] ?? 0);
            if ($posterItemId <= 0) {
                throw new \RuntimeException('Товар не найден (poster_id=' . $posterId . ')');
            }

            // Переводы привязаны к menu_items.id — гарантируем строку и берём её id.
            $this->db->query(
                "INSERT INTO {$mi} (poster_item_id) VALUES (?)
                 ON DUPLICATE KEY UPDATE poster_item_id = VALUES(poster_item_id)",
                [$posterItemId]
            );
            $miRow  = $this->db->query("SELECT id FROM {$mi} WHERE poster_item_id = ? LIMIT 1", [$posterItemId])->fetch();
            $itemId = (int) ($miRow['id'] ?? 0);
            if ($itemId <= 0) {
                throw new \RuntimeException('Не удалось создать строку меню');
            }

            foreach (['ru', 'en', 'vn', 'ko'] as $lang) {
                $title = trim((string) ($body["title_{$lang}"] ?? ''));
                $desc  = trim((string) ($body["desc_{$lang}"] ?? ''));
                if ($title !== '' || $desc !== '') {
                    $this->db->query(
                        "INSERT INTO {$tr} (item_id, lang, title, description) VALUES (?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE title = VALUES(title), description = VALUES(description)",
                        [$itemId, $lang, $title, $desc]
                    );
                }
            }
            $payload = json_encode(['ok' => true]);
        } catch (\Throwable $e) {
            $payload = json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }

        $response->getBody()->write((string) $payload);
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function _syncMenu(array &$flash): void
    {
        try {
            $token = Config::require('POSTER_API_TOKEN');
            $api   = new \App\Classes\PosterAPI($token);
            $sync  = new \App\Classes\PosterMenuSync($api, $this->db);
            $result = $sync->sync(false);
            $mt = $this->db->t('system_meta');
            $now = (new \DateTime('now', new \DateTimeZone('Asia/Ho_Chi_Minh')))->format('Y-m-d H:i:s');
            $this->db->query(
                "INSERT INTO {$mt} (meta_key, meta_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)",
                ['menu_last_sync_at', $now]
            );
            $flash['ok'] = 'Меню обновлено из Poster.';
        } catch (\Throwable $e) {
            $flash['err'] = 'Ошибка синка меню: ' . $e->getMessage();
        }
    }

    private function _loadSyncMeta(): array
    {
        $meta = [];
        foreach (['menu_last_sync_at', 'menu_last_sync_result', 'menu_last_sync_error'] as $k) {
            try {
                $row = $this->db->query(
                    "SELECT meta_value FROM {$this->db->t('system_meta')} WHERE meta_key = ? LIMIT 1", [$k]
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
        $pageTitle = 'Меню';
        ob_start();
        $currentPath = '/admin/menu';
        $flashOk  = $flash['ok'];
        $flashErr = $flash['err'];
        require __DIR__ . '/../../Views/layout.php';
        $html = ob_get_clean();
        $response->getBody()->write((string) $html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}

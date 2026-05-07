<?php
declare(strict_types=1);

namespace Reservations\Repositories;

use App\Classes\Database;

class TableSettingsRepository {
    private Database $db;
    private string $tbl;

    public function __construct(Database $db) {
        $this->db = $db;
        $this->tbl = $db->t('reservation_table_settings');
    }

    public function getByHall(int $spotId, int $hallId): array {
        $rows = $this->db->query(
            "SELECT * FROM {$this->tbl} WHERE spot_id = ? AND hall_id = ?",
            [$spotId, $hallId]
        )->fetchAll();
        return is_array($rows) ? $rows : [];
    }

    public function upsertFromPosterTables(int $spotId, int $hallId, array $posterRows, array $metaCapsBySchemeNum, array $metaBookableSchemeNums): void {
        $caps = $metaCapsBySchemeNum;
        $bookable = array_fill_keys(array_map('strval', $metaBookableSchemeNums), true);

        $existing = [];
        foreach ($this->getByHall($spotId, $hallId) as $r) {
            $pid = (int)($r['poster_table_id'] ?? 0);
            if ($pid > 0) $existing[$pid] = $r;
        }

        foreach ($posterRows as $r) {
            if (!is_array($r)) continue;
            $posterId = (int)($r['table_id'] ?? 0);
            if ($posterId <= 0) continue;

            $title = trim((string)($r['table_title'] ?? ''));
            $numRaw = trim((string)($r['table_num'] ?? ''));
            $schemeNum = null;
            if ($title !== '' && preg_match('/^\d+$/', $title)) $schemeNum = (int)$title;
            elseif ($numRaw !== '' && preg_match('/^\d+$/', $numRaw)) $schemeNum = (int)$numRaw;

            $hasExisting = array_key_exists($posterId, $existing);
            $cur = $hasExisting ? $existing[$posterId] : null;

            $displayName = $hasExisting ? trim((string)($cur['display_name'] ?? '')) : '';
            if ($displayName === '') {
                if ($schemeNum !== null) $displayName = (string)$schemeNum;
                elseif ($title !== '') $displayName = $title;
                elseif ($numRaw !== '') $displayName = $numRaw;
                else $displayName = '#' . $posterId;
            }

            $capacity = $hasExisting ? (int)($cur['capacity'] ?? 0) : 0;
            if (!$hasExisting && $schemeNum !== null) {
                $k = (string)$schemeNum;
                if (array_key_exists($k, $caps)) $capacity = (int)$caps[$k];
            }

            $show = $hasExisting ? (int)($cur['show_on_canvas'] ?? 1) : 1;
            $book = $hasExisting ? (int)($cur['bookable'] ?? 0) : 0;
            if (!$hasExisting && $schemeNum !== null && isset($bookable[(string)$schemeNum])) {
                $book = 1;
            }

            $this->db->query(
                "INSERT INTO {$this->tbl} (spot_id, hall_id, poster_table_id, scheme_num, display_name, show_on_canvas, bookable, capacity)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   scheme_num = COALESCE(VALUES(scheme_num), scheme_num),
                   display_name = COALESCE(NULLIF(VALUES(display_name), ''), display_name),
                   show_on_canvas = show_on_canvas,
                   bookable = bookable,
                   capacity = capacity",
                [$spotId, $hallId, $posterId, $schemeNum, $displayName, $show, $book, $capacity]
            );
        }
    }

    public function updateByPosterTableId(int $spotId, int $hallId, int $posterTableId, array $fields): void {
        if ($spotId <= 0 || $hallId <= 0 || $posterTableId <= 0) return;
        $allowed = ['scheme_num', 'display_name', 'show_on_canvas', 'bookable', 'capacity'];
        $sets = [];
        $params = [];
        foreach ($allowed as $k) {
            if (!array_key_exists($k, $fields)) continue;
            $sets[] = "{$k} = ?";
            $params[] = $fields[$k];
        }
        if (!$sets) return;
        $params[] = $spotId;
        $params[] = $hallId;
        $params[] = $posterTableId;
        $this->db->query(
            "UPDATE {$this->tbl} SET " . implode(', ', $sets) . " WHERE spot_id = ? AND hall_id = ? AND poster_table_id = ? LIMIT 1",
            $params
        );
    }
}


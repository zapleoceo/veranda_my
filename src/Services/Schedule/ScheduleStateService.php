<?php

declare(strict_types=1);

namespace App\Services\Schedule;

use App\Infrastructure\Database;

/**
 * Manages the schedule state: snapshots (history with rollback), custom zones,
 * per-employee schedule tags. Tables are auto-created on first use — there is
 * no separate migration step.
 *
 * Storage model:
 *   schedule_snapshots ─ each row is the full schedule as a JSON blob.
 *     The latest row (highest id) is treated as "current". Older rows stay
 *     for rollback; periodic prune can be added later.
 *   schedule_zones ─ user-defined virtual zones (Беседка, Терраса) that aren't
 *     Poster Hall_IDs.
 *   schedule_staff_tags ─ per-employee overlays on top of Poster data:
 *     in_schedule (show on grid), can_be_senior (eligible for ⭐ block),
 *     only_in_blocks (restrict to certain blocks), rate_per_hour.
 *
 * JSON state format (state.shifts uses ISO dates 'YYYY-MM-DD'; slot keys are
 * "blockId:slotIndex" — e.g. "senior:0", "hall:1:2", "zone:1:0"):
 *   {
 *     "version": 1,
 *     "blocks": [
 *       {"id":"senior", "type":"senior", "name":"Старшие смены", "icon":"⭐",
 *        "slots":[
 *          {"label":"день",  "defaultTime":"09:00-17:00"},
 *          {"label":"день",  "defaultTime":"09:00-17:00"},
 *          {"label":"вечер", "defaultTime":"16:00-23:00"}
 *        ]},
 *       {"id":"hall:1", "type":"hall", "hall_id":1, "name":"Главный зал", "icon":"🏛", "slots":[...]},
 *       {"id":"zone:1", "type":"custom", "zone_id":1, "name":"Беседка", "icon":"🌿", "slots":[...]}
 *     ],
 *     "shifts": {
 *       "2026-05-13": { "senior:0": {"emp_id":12, "start":"09:00", "end":"17:00"},  ... },
 *       ...
 *     },
 *     "templates": [
 *       {"name":"Д", "start":"09:00", "end":"17:00"},
 *       ...
 *     ]
 *   }
 */
class ScheduleStateService
{
    public function __construct(private readonly Database $db)
    {
        $this->ensureTables();
    }

    // ────────────────────────────────────────────────────────────────
    //  Snapshots — load / save / list / load-by-id
    // ────────────────────────────────────────────────────────────────

    /**
     * Latest snapshot's JSON. If table is empty, returns the default scaffold
     * so the UI has something to render on first visit.
     */
    public function loadCurrent(): array
    {
        $t = $this->db->t('schedule_snapshots');
        $row = $this->db->query(
            "SELECT json_data FROM {$t} ORDER BY id DESC LIMIT 1"
        )->fetch();

        if ($row && !empty($row['json_data'])) {
            $decoded = json_decode((string)$row['json_data'], true);
            if (is_array($decoded)) {
                // Ensure required keys
                $decoded['version']  ??= 1;
                $decoded['blocks']   ??= $this->defaultState()['blocks'];
                $decoded['shifts']   ??= [];
                $decoded['templates'] ??= $this->defaultState()['templates'];
                return $decoded;
            }
        }
        return $this->defaultState();
    }

    /**
     * Store a new snapshot. Always inserts a new row (full history).
     * Returns the new snapshot id.
     */
    public function saveSnapshot(array $state, string $label, string $email): int
    {
        $t = $this->db->t('schedule_snapshots');
        // Strip current flag from previous rows (only the newest is "current")
        $this->db->query("UPDATE {$t} SET is_current = 0");
        $this->db->query(
            "INSERT INTO {$t} (label, json_data, is_current, created_by) VALUES (?, ?, 1, ?)",
            [
                $label !== '' ? mb_substr($label, 0, 100, 'UTF-8') : 'auto',
                json_encode($state, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                $email,
            ]
        );
        return (int)$this->db->lastInsertId();
    }

    /** Recent snapshots for the pills row + drop-downs. */
    public function listSnapshots(int $limit = 25): array
    {
        $t = $this->db->t('schedule_snapshots');
        $limit = max(1, min(100, $limit));
        $rows = $this->db->query(
            "SELECT id, label, is_current, created_at, created_by
             FROM {$t}
             ORDER BY id DESC
             LIMIT {$limit}"
        )->fetchAll();
        return array_map(static fn($r) => [
            'id'         => (int)$r['id'],
            'label'      => (string)($r['label'] ?? ''),
            'is_current' => (bool)(int)($r['is_current'] ?? 0),
            'created_at' => (string)($r['created_at'] ?? ''),
            'created_by' => (string)($r['created_by'] ?? ''),
        ], $rows ?: []);
    }

    public function loadSnapshot(int $id): ?array
    {
        $t = $this->db->t('schedule_snapshots');
        $row = $this->db->query(
            "SELECT json_data FROM {$t} WHERE id = ? LIMIT 1",
            [$id]
        )->fetch();
        if (!$row || empty($row['json_data'])) return null;
        $decoded = json_decode((string)$row['json_data'], true);
        return is_array($decoded) ? $decoded : null;
    }

    public function deleteSnapshot(int $id): bool
    {
        $t = $this->db->t('schedule_snapshots');
        $row = $this->db->query("SELECT is_current FROM {$t} WHERE id = ? LIMIT 1", [$id])->fetch();
        if (!$row) return false;
        // Не даём удалить текущую — иначе пропадёт state
        if ((int)($row['is_current'] ?? 0) === 1) return false;
        $this->db->query("DELETE FROM {$t} WHERE id = ?", [$id]);
        return true;
    }

    // ────────────────────────────────────────────────────────────────
    //  Custom zones (Беседка, Терраса и т.п.) — CRUD
    // ────────────────────────────────────────────────────────────────

    public function listZones(): array
    {
        $t = $this->db->t('schedule_zones');
        $rows = $this->db->query(
            "SELECT id, name, icon, sort_order, is_active FROM {$t}
             WHERE is_active = 1
             ORDER BY sort_order, id"
        )->fetchAll();
        return array_map(static fn($r) => [
            'id'         => (int)$r['id'],
            'name'       => (string)$r['name'],
            'icon'       => (string)($r['icon'] ?? '🌿'),
            'sort_order' => (int)($r['sort_order'] ?? 0),
        ], $rows ?: []);
    }

    public function addZone(string $name, string $icon = '🌿'): int
    {
        $t = $this->db->t('schedule_zones');
        $this->db->query(
            "INSERT INTO {$t} (name, icon, is_active) VALUES (?, ?, 1)",
            [mb_substr($name, 0, 100, 'UTF-8'), mb_substr($icon, 0, 20, 'UTF-8')]
        );
        return (int)$this->db->lastInsertId();
    }

    public function deleteZone(int $id): void
    {
        $t = $this->db->t('schedule_zones');
        // Soft delete — данные сохраняем для миграций
        $this->db->query("UPDATE {$t} SET is_active = 0 WHERE id = ?", [$id]);
    }

    // ────────────────────────────────────────────────────────────────
    //  Staff tags overlay
    // ────────────────────────────────────────────────────────────────

    /**
     * Returns tags map keyed by user_id.
     * Каждый сотрудник Poster получает дефолтные теги (in_schedule=true,
     * can_be_senior=false), пока админ не сделал явную настройку.
     */
    public function getStaffTags(): array
    {
        $t = $this->db->t('schedule_staff_tags');
        $rows = $this->db->query(
            "SELECT user_id, in_schedule, can_be_senior, only_in_blocks, custom_tag, rate_per_hour
             FROM {$t}"
        )->fetchAll();
        $out = [];
        foreach ($rows ?: [] as $r) {
            $out[(int)$r['user_id']] = [
                'in_schedule'    => (bool)(int)($r['in_schedule'] ?? 1),
                'can_be_senior'  => (bool)(int)($r['can_be_senior'] ?? 0),
                'only_in_blocks' => (string)($r['only_in_blocks'] ?? ''),
                'custom_tag'     => (string)($r['custom_tag'] ?? ''),
                'rate_per_hour'  => (int)($r['rate_per_hour'] ?? 0),
            ];
        }
        return $out;
    }

    public function saveStaffTag(int $userId, array $tag): void
    {
        $t = $this->db->t('schedule_staff_tags');
        $this->db->query(
            "INSERT INTO {$t} (user_id, in_schedule, can_be_senior, only_in_blocks, custom_tag, rate_per_hour)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                in_schedule    = VALUES(in_schedule),
                can_be_senior  = VALUES(can_be_senior),
                only_in_blocks = VALUES(only_in_blocks),
                custom_tag     = VALUES(custom_tag),
                rate_per_hour  = VALUES(rate_per_hour),
                updated_at     = CURRENT_TIMESTAMP",
            [
                $userId,
                (int)(bool)($tag['in_schedule']    ?? 1),
                (int)(bool)($tag['can_be_senior']  ?? 0),
                mb_substr((string)($tag['only_in_blocks'] ?? ''), 0, 255, 'UTF-8'),
                mb_substr((string)($tag['custom_tag']     ?? ''), 0, 50,  'UTF-8'),
                (int)($tag['rate_per_hour'] ?? 0),
            ]
        );
    }

    // ────────────────────────────────────────────────────────────────
    //  Default state — структура без смен (4 базовых блока)
    // ────────────────────────────────────────────────────────────────

    public function defaultState(): array
    {
        return [
            'version' => 1,
            'blocks' => [
                [
                    'id'    => 'senior', 'type' => 'senior', 'color' => 'senior',
                    'name'  => 'Старшие смены', 'icon' => '⭐',
                    'slots' => [
                        ['label' => 'день',  'defaultTime' => '09:00-17:00'],
                        ['label' => 'день',  'defaultTime' => '09:00-17:00'],
                        ['label' => 'вечер', 'defaultTime' => '16:00-23:00'],
                    ],
                ],
                [
                    'id'    => 'hall:1', 'type' => 'hall', 'hall_id' => 1, 'color' => 'main',
                    'name'  => 'Главный зал', 'icon' => '🏛',
                    'slots' => [
                        ['label' => 'утро',  'defaultTime' => '09:00-17:00'],
                        ['label' => 'утро',  'defaultTime' => '09:00-17:00'],
                        ['label' => 'вечер', 'defaultTime' => '16:00-23:00'],
                        ['label' => 'вечер', 'defaultTime' => '16:00-23:00'],
                    ],
                ],
                [
                    'id'    => 'hall:2', 'type' => 'hall', 'hall_id' => 2, 'color' => 'banya',
                    'name'  => 'Баня', 'icon' => '♨',
                    'slots' => [
                        ['label' => 'весь день', 'defaultTime' => '10:00-18:00'],
                    ],
                ],
                [
                    'id'    => 'zone:1', 'type' => 'custom', 'zone_id' => 1, 'color' => 'custom',
                    'name'  => 'Беседка', 'icon' => '🌿',
                    'slots' => [
                        ['label' => 'по брони', 'defaultTime' => '18:00-23:00'],
                    ],
                ],
            ],
            'shifts'    => new \stdClass(),
            'templates' => [
                ['name' => 'Д',      'start' => '09:00', 'end' => '17:00'],
                ['name' => 'В',      'start' => '16:00', 'end' => '23:00'],
                ['name' => 'У',      'start' => '09:00', 'end' => '14:00'],
                ['name' => 'Полный', 'start' => '09:00', 'end' => '23:00'],
            ],
        ];
    }

    // ────────────────────────────────────────────────────────────────
    //  Schema (auto-creates on first construct)
    // ────────────────────────────────────────────────────────────────

    private function ensureTables(): void
    {
        $snapshots = $this->db->t('schedule_snapshots');
        $this->db->query("
            CREATE TABLE IF NOT EXISTS {$snapshots} (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                label       VARCHAR(100) NOT NULL DEFAULT '',
                json_data   MEDIUMTEXT NOT NULL,
                is_current  TINYINT(1) NOT NULL DEFAULT 0,
                created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_by  VARCHAR(255) NOT NULL DEFAULT '',
                KEY idx_current (is_current),
                KEY idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $zones = $this->db->t('schedule_zones');
        $this->db->query("
            CREATE TABLE IF NOT EXISTS {$zones} (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                name        VARCHAR(100) NOT NULL,
                icon        VARCHAR(20)  NOT NULL DEFAULT '🌿',
                sort_order  INT          NOT NULL DEFAULT 0,
                is_active   TINYINT(1)   NOT NULL DEFAULT 1,
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_active (is_active, sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $tags = $this->db->t('schedule_staff_tags');
        $this->db->query("
            CREATE TABLE IF NOT EXISTS {$tags} (
                user_id          INT PRIMARY KEY,
                in_schedule      TINYINT(1) NOT NULL DEFAULT 1,
                can_be_senior    TINYINT(1) NOT NULL DEFAULT 0,
                only_in_blocks   VARCHAR(255) NOT NULL DEFAULT '',
                custom_tag       VARCHAR(50)  NOT NULL DEFAULT '',
                rate_per_hour    INT          NOT NULL DEFAULT 0,
                updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}

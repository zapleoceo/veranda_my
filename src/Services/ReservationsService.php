<?php

declare(strict_types=1);

namespace App\Services;

use App\Infrastructure\Database;

class ReservationsService
{
    private const SOON_KEY        = 'reservations_soon_booking_hours';
    private const MIN_PREORDER_KEY = 'preorder_min_per_guest_vnd';

    private string $tbl;
    private string $metaTbl;

    public function __construct(private readonly Database $db)
    {
        $this->tbl     = $db->t('reservations');
        $this->metaTbl = $db->t('system_meta');
    }

    public function getReservation(int $id): ?array
    {
        $row = $this->db->query("SELECT * FROM {$this->tbl} WHERE id = ? LIMIT 1", [$id])->fetch();
        return is_array($row) ? $row : null;
    }

    public function getTgMessageId(int $id): ?int
    {
        $row = $this->db->query(
            "SELECT tg_message_id FROM {$this->tbl} WHERE id = ? LIMIT 1", [$id]
        )->fetch();
        return is_array($row) && !empty($row['tg_message_id']) ? (int)$row['tg_message_id'] : null;
    }

    public function getReservationsList(
        string $dateFrom, string $dateTo, bool $showDeleted, string $sort, string $order
    ): array {
        $where  = "DATE(start_time) BETWEEN ? AND ?";
        $params = [$dateFrom, $dateTo];
        if (!$showDeleted) {
            $where .= " AND deleted_at IS NULL";
        }
        $rows = $this->db->query(
            "SELECT * FROM {$this->tbl} WHERE {$where} ORDER BY {$sort} {$order}",
            $params
        )->fetchAll();
        return is_array($rows) ? $rows : [];
    }

    public function updateReservation(int $id, array $updates): void
    {
        if (empty($updates)) return;
        $sets   = array_map(fn($k) => "{$k} = ?", array_keys($updates));
        $params = array_values($updates);
        $params[] = $id;
        $this->db->query(
            "UPDATE {$this->tbl} SET " . implode(', ', $sets) . " WHERE id = ? LIMIT 1",
            $params
        );
    }

    public function toggleDeleted(int $id, bool $deleted, string $userEmail): array
    {
        if ($deleted) {
            $this->db->query(
                "UPDATE {$this->tbl} SET deleted_at = NOW(), deleted_by = ? WHERE id = ? LIMIT 1",
                [$userEmail, $id]
            );
        } else {
            $this->db->query(
                "UPDATE {$this->tbl} SET deleted_at = NULL, deleted_by = NULL WHERE id = ? LIMIT 1",
                [$id]
            );
        }
        $row = $this->db->query(
            "SELECT id, deleted_at, deleted_by FROM {$this->tbl} WHERE id = ? LIMIT 1", [$id]
        )->fetch();
        return is_array($row) ? $row : [];
    }

    public function updateSoonHours(int $hours): void
    {
        $this->_upsertMeta(self::SOON_KEY, (string)max(0, min(24, $hours)));
    }

    public function updateMinPreorderPerGuest(int $amount): void
    {
        $this->_upsertMeta(self::MIN_PREORDER_KEY, (string)max(0, min(10_000_000, $amount)));
    }

    public function getSettings(): array
    {
        $rows = $this->db->query(
            "SELECT meta_key, meta_value FROM {$this->metaTbl} WHERE meta_key IN (?, ?)",
            [self::SOON_KEY, self::MIN_PREORDER_KEY]
        )->fetchAll();

        $map = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            $map[$r['meta_key']] = $r['meta_value'];
        }

        $soonRaw = trim((string)($map[self::SOON_KEY] ?? ''));
        $minRaw  = trim((string)($map[self::MIN_PREORDER_KEY] ?? ''));
        return [
            'soon_hours'          => ($soonRaw !== '' && is_numeric($soonRaw)) ? max(0, min(24, (int)$soonRaw)) : 2,
            'min_preorder_per_guest' => ($minRaw !== '' && is_numeric($minRaw)) ? max(0, (int)$minRaw) : 100_000,
        ];
    }

    private function _upsertMeta(string $key, string $value): void
    {
        $this->db->query(
            "INSERT INTO {$this->metaTbl} (meta_key, meta_value)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = CURRENT_TIMESTAMP",
            [$key, $value]
        );
    }
}

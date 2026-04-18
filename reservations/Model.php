<?php
namespace Reservations;

class Model {
    private $db;
    private $resTable;
    private $metaTable;

    public function __construct($db) {
        $this->db = $db;
        $this->resTable = $db->t('reservations');
        $this->metaTable = $db->t('system_meta');
    }

    public function getReservation($id) {
        return $this->db->query("SELECT * FROM {$this->resTable} WHERE id = ? LIMIT 1", [$id])->fetch();
    }

    public function updateReservation($id, $sets, $params) {
        $params[] = $id;
        $this->db->query("UPDATE {$this->resTable} SET " . implode(', ', $sets) . " WHERE id = ? LIMIT 1", $params);
    }

    public function getTgMessageId($id) {
        return $this->db->query("SELECT tg_message_id FROM {$this->resTable} WHERE id = ? LIMIT 1", [$id])->fetch();
    }

    public function toggleDeleted($id, $deleted, $userEmail) {
        if ($deleted) {
            $this->db->query("UPDATE {$this->resTable} SET deleted_at = NOW(), deleted_by = ? WHERE id = ? LIMIT 1", [$userEmail, $id]);
        } else {
            $this->db->query("UPDATE {$this->resTable} SET deleted_at = NULL, deleted_by = NULL WHERE id = ? LIMIT 1", [$id]);
        }
        return $this->db->query("SELECT id, deleted_at, deleted_by FROM {$this->resTable} WHERE id = ? LIMIT 1", [$id])->fetch();
    }

    public function updateMeta($key, $value) {
        $this->db->query(
            "INSERT INTO {$this->metaTable} (meta_key, meta_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = CURRENT_TIMESTAMP",
            [$key, $value]
        );
    }

    public function getReservationsList($dateFrom, $dateTo, $showDeleted, $sort, $order) {
        $where = "DATE(start_time) BETWEEN ? AND ?";
        $params = [$dateFrom, $dateTo];
        if (!$showDeleted) {
            $where .= " AND deleted_at IS NULL";
        }
        $rows = $this->db->query("
            SELECT * 
            FROM {$this->resTable} 
            WHERE {$where}
            ORDER BY {$sort} {$order}
        ", $params)->fetchAll();
        return is_array($rows) ? $rows : [];
    }
}

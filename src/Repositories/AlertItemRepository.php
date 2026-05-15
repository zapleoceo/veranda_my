<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Infrastructure\Database;
use App\Models\AlertItem;

class AlertItemRepository
{
    public function __construct(private readonly Database $db) {}

    /** @return array<int, AlertItem> keyed by kitchenStatsId */
    public function findByDate(string $date): array
    {
        $table = $this->db->t('tg_alert_items');
        $rows  = $this->db->query(
            "SELECT kitchen_stats_id, transaction_id, message_id, last_text_hash, last_seen_at
             FROM {$table}
             WHERE transaction_date = ?",
            [$date]
        )->fetchAll();

        $result = [];
        foreach ($rows as $row) {
            $item = AlertItem::fromRow($row);
            if ($item->kitchenStatsId > 0) {
                $result[$item->kitchenStatsId] = $item;
            }
        }
        return $result;
    }

    public function upsert(
        string $date,
        int $statsId,
        int $txId,
        int $msgId,
        string $hash,
        string $now
    ): void {
        $table = $this->db->t('tg_alert_items');
        $this->db->query(
            "INSERT INTO {$table}
                (transaction_date, kitchen_stats_id, transaction_id, message_id, last_text_hash, last_seen_at)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                transaction_id  = VALUES(transaction_id),
                message_id      = VALUES(message_id),
                last_text_hash  = VALUES(last_text_hash),
                last_seen_at    = VALUES(last_seen_at),
                updated_at      = CURRENT_TIMESTAMP",
            [$date, $statsId, $txId, $msgId, $hash, $now]
        );
    }

    public function updateSeen(string $date, int $statsId, string $now): void
    {
        $table = $this->db->t('tg_alert_items');
        $this->db->query(
            "UPDATE {$table}
             SET last_seen_at = ?, updated_at = CURRENT_TIMESTAMP
             WHERE transaction_date = ? AND kitchen_stats_id = ?",
            [$now, $date, $statsId]
        );
    }

    public function updateHash(string $date, int $statsId, int $txId, string $hash, string $now): void
    {
        $table = $this->db->t('tg_alert_items');
        $this->db->query(
            "UPDATE {$table}
             SET transaction_id = ?, last_text_hash = ?, last_seen_at = ?, updated_at = CURRENT_TIMESTAMP
             WHERE transaction_date = ? AND kitchen_stats_id = ?",
            [$txId, $hash, $now, $date, $statsId]
        );
    }

    public function delete(string $date, int $statsId): void
    {
        $table = $this->db->t('tg_alert_items');
        $this->db->query(
            "DELETE FROM {$table} WHERE transaction_date = ? AND kitchen_stats_id = ?",
            [$date, $statsId]
        );
    }
}

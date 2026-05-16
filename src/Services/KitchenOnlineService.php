<?php

declare(strict_types=1);

namespace App\Services;

use App\Infrastructure\Database;

class KitchenOnlineService
{
    public function __construct(private readonly Database $db) {}

    public function getMeta(): array
    {
        $mt = $this->db->t('system_meta');
        $ks = $this->db->t('kitchen_stats');

        $useLogical = true;
        $lastSync   = '—';

        try {
            $m = $this->db->query("SELECT meta_value FROM {$mt} WHERE meta_key='ko_use_logical_close' LIMIT 1")->fetch();
            $useLogical = !isset($m['meta_value']) || (string)$m['meta_value'] !== '0';
        } catch (\Throwable) {}

        try {
            $row = $this->db->query("SELECT meta_value FROM {$mt} WHERE meta_key='poster_last_sync_at' LIMIT 1")->fetch();
            if (!empty($row['meta_value'])) {
                $lastSync = date('d.m.Y H:i:s', strtotime((string)$row['meta_value']));
            } else {
                $fb = $this->db->query("SELECT MAX(created_at) AS t FROM {$ks}")->fetch();
                if (!empty($fb['t'])) $lastSync = date('d.m.Y H:i:s', strtotime((string)$fb['t']));
            }
        } catch (\Throwable) {}

        return ['use_logical_close' => $useLogical, 'last_sync' => $lastSync];
    }

    public function getOpenItems(string $station, bool $useLogical): array
    {
        $ks    = $this->db->t('kitchen_stats');
        $ti    = $this->db->t('tg_alert_items');
        $today = date('Y-m-d');

        $stationSql = match ($station) {
            'kitchen' => " AND (ks.station='2' OR ks.station=2 OR ks.station='Kitchen' OR ks.station='Main')",
            'bar'     => " AND (ks.station='3' OR ks.station=3 OR ks.station='Bar Veranda')",
            default   => '',
        };

        $excludeSql = $useLogical
            ? " AND COALESCE(ks.exclude_from_dashboard,0)=0 "
            : " AND NOT(COALESCE(ks.exclude_from_dashboard,0)=1 AND COALESCE(ks.exclude_auto,0)=0) ";

        try {
            return $this->db->query(
                "SELECT ks.id, ks.transaction_id, ks.receipt_number, ks.table_number,
                        ks.waiter_name, ks.transaction_comment, ks.dish_id, ks.dish_name,
                        ks.station, ks.ticket_sent_at,
                        COALESCE(ks.tg_sent_at, tga.created_at) AS tg_sent_at,
                        COALESCE(ks.tg_last_edit_at, tga.updated_at) AS tg_last_edit_at,
                        COALESCE(ks.tg_message_id, tga.message_id) AS tg_message_id
                 FROM {$ks} ks
                 LEFT JOIN {$ti} tga
                   ON tga.transaction_date=ks.transaction_date AND tga.kitchen_stats_id=ks.id
                 WHERE ks.transaction_date=?
                   AND ks.status=1
                   AND ks.ready_pressed_at IS NULL
                   AND ks.ticket_sent_at IS NOT NULL
                   AND COALESCE(ks.was_deleted,0)=0
                   {$excludeSql}
                   AND NOT(COALESCE(ks.dish_category_id,0)=47 OR COALESCE(ks.dish_sub_category_id,0)=47)
                   {$stationSql}
                 ORDER BY ks.ticket_sent_at ASC",
                [$today]
            )->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    public function getWaitLimitMinutes(): int
    {
        $ks  = $this->db->t('kitchen_stats');
        $mt  = $this->db->t('system_meta');
        $def = ['alert_timing_low_load' => 20, 'alert_load_threshold' => 25, 'alert_timing_high_load' => 30, 'exclude_partners_from_load' => 0];

        try {
            $keys = implode(',', array_fill(0, count($def), '?'));
            $rows = $this->db->query(
                "SELECT meta_key, meta_value FROM {$mt} WHERE meta_key IN ({$keys})",
                array_keys($def)
            )->fetchAll();
            foreach ($rows as $r) {
                if (array_key_exists((string)$r['meta_key'], $def)) {
                    $def[(string)$r['meta_key']] = (int)$r['meta_value'];
                }
            }
        } catch (\Throwable) {}

        $today = date('Y-m-d');
        $count = 0;
        try {
            $extra = !empty($def['exclude_partners_from_load']) ? " AND table_number!='Partners'" : '';
            $row   = $this->db->query("SELECT COUNT(DISTINCT transaction_id) AS c FROM {$ks} WHERE status=1 AND transaction_date=?{$extra}", [$today])->fetch();
            $count = (int)($row['c'] ?? 0);
        } catch (\Throwable) {}

        return $count < $def['alert_load_threshold']
            ? $def['alert_timing_low_load']
            : $def['alert_timing_high_load'];
    }

    public function excludeItem(int $itemId): void
    {
        $ks = $this->db->t('kitchen_stats');
        $this->db->query("UPDATE {$ks} SET exclude_from_dashboard=1, exclude_auto=0 WHERE id=?", [$itemId]);
    }

    public function setLogicalClose(bool $use): void
    {
        $mt = $this->db->t('system_meta');
        $this->db->query(
            "INSERT INTO {$mt} (meta_key, meta_value) VALUES ('ko_use_logical_close', ?)
             ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)",
            [$use ? '1' : '0']
        );
    }
}

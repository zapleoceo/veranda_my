<?php

declare(strict_types=1);

namespace App\Actions;

use App\Infrastructure\Database;
use App\Infrastructure\Logger;

/**
 * Audit log for manager-driven ignores (IgnoreItem / IgnoreTx button presses).
 *
 * Why we need an audit table separately from kitchen_stats.exclude_from_dashboard:
 *   - kitchen_stats only carries the current excluded state, not WHEN it was set
 *     and by WHOM. Both pieces are needed for the daily "Игноры X|Y" counter in
 *     the status message and for any later who-did-what diagnostics.
 *   - We can't rely on transaction_date because an ignore press can target a
 *     transaction from any past day, while the counter is over presses-today.
 *
 * Storage strategy: the table is created lazily the first time anything tries
 * to write to it. That keeps deploys hands-off — no migration step required.
 */
final class IgnoreLog
{
    /**
     * INSERT a single ignore-event row.
     *
     * @param Database $db
     * @param string   $kind     'item' (single dish, kid) or 'tx' (whole receipt)
     * @param int      $targetId kitchen_stats.id for item / transaction_id for tx
     * @param string   $actor    Telegram @username of the operator (may be empty)
     */
    public static function record(Database $db, string $kind, int $targetId, string $actor = ''): void
    {
        if ($kind !== 'item' && $kind !== 'tx') {
            return; // Defensive — caller bug, not worth throwing in a webhook path.
        }
        if ($targetId <= 0) {
            return;
        }

        $tbl = $db->t('kitchen_ignore_log');

        try {
            // PHP's clock controls the boundary, not MySQL's. The cron and PHP
            // are set to Asia/Ho_Chi_Minh; MySQL may be UTC — comparing apples
            // to apples by recording the timestamp in PHP-local TZ.
            $now = date('Y-m-d H:i:s');
            $db->query(
                "INSERT INTO {$tbl} (kind, target_id, actor_username, created_at) VALUES (?, ?, ?, ?)",
                [$kind, $targetId, $actor !== '' ? $actor : null, $now]
            );
        } catch (\Throwable $e) {
            // First-time path: the table doesn't exist yet. Create it and retry.
            // We keep this in the catch so steady-state INSERTs avoid the CREATE.
            try {
                $db->query("CREATE TABLE IF NOT EXISTS {$tbl} (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    kind ENUM('item','tx') NOT NULL,
                    target_id BIGINT UNSIGNED NOT NULL,
                    actor_username VARCHAR(64) NULL,
                    created_at DATETIME NOT NULL,
                    PRIMARY KEY (id),
                    KEY idx_created_at (created_at),
                    KEY idx_kind_created (kind, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                $now = $now ?? date('Y-m-d H:i:s');
                $db->query(
                    "INSERT INTO {$tbl} (kind, target_id, actor_username, created_at) VALUES (?, ?, ?, ?)",
                    [$kind, $targetId, $actor !== '' ? $actor : null, $now]
                );
            } catch (\Throwable $e2) {
                Logger::get()->warning('ignore_log.insert_failed', [
                    'kind'   => $kind,
                    'target' => $targetId,
                    'err'    => $e2->getMessage(),
                ]);
            }
        }
    }

    /**
     * Returns ['items' => X, 'tx' => Y] counts for the date span.
     * Both bounds expected in PHP-local TZ (Asia/Ho_Chi_Minh).
     */
    public static function countBetween(Database $db, string $fromInclusive, string $toExclusive): array
    {
        $tbl = $db->t('kitchen_ignore_log');
        try {
            $row = $db->query(
                "SELECT
                   SUM(CASE WHEN kind = 'item' THEN 1 ELSE 0 END) AS items,
                   SUM(CASE WHEN kind = 'tx'   THEN 1 ELSE 0 END) AS txs
                 FROM {$tbl}
                 WHERE created_at >= ? AND created_at < ?",
                [$fromInclusive, $toExclusive]
            )->fetch();
        } catch (\Throwable) {
            return ['items' => 0, 'tx' => 0]; // Table not yet created — fine.
        }
        return [
            'items' => (int) ($row['items'] ?? 0),
            'tx'    => (int) ($row['txs']   ?? 0),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Infrastructure\Database;
use App\Infrastructure\Logger;
use App\Infrastructure\PosterApiClient;
use App\Repositories\MetaRepository;

/**
 * Syncs today's kitchen data from Poster POS:
 * 1. Full stats sync via KitchenAnalytics
 * 2. Close-metadata refresh for transactions missing close time
 * 3. Probable close-time computation (prob_close_at) based on neighboring receipts
 * 4. Auto-exclude rules (hookah category, closed transactions, etc.)
 */
class KitchenSyncService
{
    // Hookah category ID excluded from overtime dashboard
    private const HOOKAH_CATEGORY_ID = 47;

    public function __construct(
        private readonly Database         $db,
        private readonly PosterApiClient  $poster,
        private readonly MetaRepository   $meta,
        private readonly \DateTimeZone    $spotTz,
    ) {}

    public function run(): void
    {
        $startedAt = microtime(true);
        $date      = date('Y-m-d');

        $synced  = $this->_syncStats($date);
        $reconciled = $this->_reconcileOpenStatus($date);
        $refreshed = $this->_refreshCloseMetadata($date);
        $prob    = $this->_computeProbCloseAt($date);
        $auto    = $this->_applyAutoExclude($date);

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $this->_writeRunMeta($date, $durationMs, $synced, $reconciled, $refreshed, $prob, $auto);

        Logger::get()->info('kitchen_sync.done', [
            'date'          => $date,
            'duration_ms'   => $durationMs,
            'stats_synced'  => $synced,
            'open_reconciled' => $reconciled,
            'close_refreshed' => $refreshed,
            'prob_set'      => $prob['set'],
            'prob_cleared'  => $prob['cleared'],
            'auto_exclude'  => array_sum($auto),
        ]);
    }

    // ─── 1. stats sync ────────────────────────────────────────────────────────

    private function _syncStats(string $date): int
    {
        try {
            // Use legacy KitchenAnalytics for now — will be migrated in a follow-up
            $analytics = new \App\Classes\KitchenAnalytics(
                new \App\Classes\PosterAPI(
                    \App\Infrastructure\Config::require('POSTER_API_TOKEN')
                )
            );
            $stats = $analytics->getStatsForPeriod($date, $date);
            if (!empty($stats)) {
                $this->_persistStats($stats);
                $this->meta->set('poster_last_sync_at', date('Y-m-d H:i:s'));
                return count($stats);
            }
        } catch (\Throwable $e) {
            Logger::get()->warning('kitchen_sync.stats_failed', ['error' => $e->getMessage()]);
        }
        $this->meta->set('poster_last_sync_at', date('Y-m-d H:i:s'));
        return 0;
    }

    /**
     * UPSERT kitchen_stats rows. Migrated verbatim from legacy
     * App\Classes\Database::saveStats() — kept SQL identical to preserve
     * the hookah auto-exclude semantics. Caller wraps in try/catch.
     */
    private function _persistStats(array $stats): void
    {
        $ks  = $this->db->t('kitchen_stats');
        $sql = "INSERT INTO {$ks}
                (transaction_date, receipt_number, transaction_opened_at, transaction_closed_at, transaction_id, table_number, waiter_name, transaction_comment, status, pay_type, close_reason, dish_id, item_seq, dish_category_id, dish_sub_category_id, dish_name, ticket_sent_at, ready_pressed_at, ready_chass_at, was_deleted, service_type, total_sum, station, exclude_from_dashboard, exclude_auto)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                dish_id = VALUES(dish_id),
                item_seq = VALUES(item_seq),
                dish_category_id = VALUES(dish_category_id),
                dish_sub_category_id = VALUES(dish_sub_category_id),
                dish_name = VALUES(dish_name),
                transaction_opened_at = VALUES(transaction_opened_at),
                transaction_closed_at = VALUES(transaction_closed_at),
                table_number = VALUES(table_number),
                waiter_name = VALUES(waiter_name),
                transaction_comment = COALESCE(VALUES(transaction_comment), transaction_comment),
                status = VALUES(status),
                pay_type = VALUES(pay_type),
                close_reason = VALUES(close_reason),
                ticket_sent_at = COALESCE(VALUES(ticket_sent_at), ticket_sent_at),
                ready_pressed_at = COALESCE(VALUES(ready_pressed_at), ready_pressed_at),
                ready_chass_at = COALESCE(VALUES(ready_chass_at), ready_chass_at),
                was_deleted = GREATEST(VALUES(was_deleted), was_deleted),
                service_type = VALUES(service_type),
                total_sum = VALUES(total_sum),
                station = VALUES(station),
                exclude_from_dashboard = CASE
                    WHEN (VALUES(dish_category_id) = " . self::HOOKAH_CATEGORY_ID . " OR VALUES(dish_sub_category_id) = " . self::HOOKAH_CATEGORY_ID . ") THEN 1
                    WHEN exclude_auto = 1 AND VALUES(ready_pressed_at) IS NOT NULL THEN 0
                    ELSE exclude_from_dashboard
                END,
                exclude_auto = CASE
                    WHEN (VALUES(dish_category_id) = " . self::HOOKAH_CATEGORY_ID . " OR VALUES(dish_sub_category_id) = " . self::HOOKAH_CATEGORY_ID . ") THEN 1
                    WHEN exclude_auto = 1 AND VALUES(ready_pressed_at) IS NOT NULL THEN 0
                    ELSE exclude_auto
                END";

        foreach ($stats as $row) {
            $mainCat = isset($row['dish_category_id']) && $row['dish_category_id'] !== null ? (int) $row['dish_category_id'] : null;
            $subCat  = isset($row['dish_sub_category_id']) && $row['dish_sub_category_id'] !== null ? (int) $row['dish_sub_category_id'] : null;
            $isHookah = ($mainCat === self::HOOKAH_CATEGORY_ID) || ($subCat === self::HOOKAH_CATEGORY_ID);

            $this->db->query($sql, [
                $row['date'],
                $row['receipt_number'],
                $row['transaction_opened_at'],
                $row['transaction_closed_at'],
                $row['transaction_id'],
                $row['table_number']        ?? null,
                $row['waiter_name']         ?? null,
                $row['transaction_comment'] ?? null,
                $row['status'],
                $row['pay_type']            ?? null,
                $row['close_reason']        ?? null,
                $row['dish_id'],
                isset($row['item_seq']) && (int) $row['item_seq'] > 0 ? (int) $row['item_seq'] : 1,
                $mainCat,
                $subCat,
                $row['dish_name'],
                $row['ticket_sent_at'],
                $row['ready_pressed_at'],
                $row['ready_chass_at']      ?? null,
                !empty($row['was_deleted']) ? 1 : 0,
                $row['service_type'],
                $row['total_sum'],
                $row['station'],
                $isHookah ? 1 : 0,
                $isHookah ? 1 : 0,
            ]);
        }
    }

    // ─── 1b. reconcile stale-open status ──────────────────────────────────────

    /**
     * Closes kitchen_stats rows still marked status=1 locally whose transaction
     * Poster already reports as closed.
     *
     * Why this is needed: _syncStats refreshes a row's status only when
     * getStatsForPeriod re-emits that exact (transaction, dish, item_seq) — and
     * it only re-emits rows that still produce kitchen instances. A receipt that
     * closes after its dishes drop out of re-emission (item_seq shift, a voided
     * or re-fired dish, or a transient detail-fetch failure) keeps a stale
     * status=1 row forever. Nothing else corrects it — _refreshCloseMetadata
     * only touches status>1 rows. The orphan then inflates the "Открыто чеков"
     * counter (COUNT DISTINCT transaction_id WHERE status=1) and can even fire a
     * false overdue alert for a dish sitting on an already-closed check.
     *
     * Fix: pull the day's authoritative transaction list (lightweight — no
     * products/history) and close any locally-open transaction Poster reports as
     * closed. Writing status=2 + transaction_closed_at also lets the downstream
     * auto-exclude rules retire those dishes correctly.
     */
    private function _reconcileOpenStatus(string $date): int
    {
        $ks = $this->db->t('kitchen_stats');

        $rows = $this->db->query(
            "SELECT DISTINCT transaction_id FROM {$ks}
             WHERE transaction_date = ? AND status = 1",
            [$date]
        )->fetchAll();
        if (empty($rows)) {
            return 0; // mirror has no open checks today — nothing to reconcile
        }
        $localOpen = array_flip(array_map(
            static fn($r) => (int) ($r['transaction_id'] ?? 0),
            $rows
        ));

        // Authoritative status from Poster for this business day. Lightweight:
        // omit include_products/include_history — we only need status + close ts.
        $ymd      = str_replace('-', '', $date);
        $closedAt = []; // tx_id => closed_at|null, only for tx Poster says are closed
        $offset   = 0;
        $limit    = 100;
        try {
            do {
                $batch = (array) $this->poster->request('dash.getTransactions', [
                    'dateFrom' => $ymd,
                    'dateTo'   => $ymd,
                    'status'   => 0,
                    'limit'    => $limit,
                    'offset'   => $offset,
                ]);
                foreach ($batch as $tx) {
                    $txId = (int) ($tx['transaction_id'] ?? 0);
                    if ($txId <= 0 || !isset($localOpen[$txId])) {
                        continue;
                    }
                    if ((int) ($tx['status'] ?? 1) > 1) {
                        $closedAt[$txId] = $this->_resolveClosedAt($tx);
                    }
                }
                $count   = count($batch);
                $offset += $limit;
            } while ($count === $limit);
        } catch (\Throwable $e) {
            Logger::get()->warning('kitchen_sync.reconcile_failed', ['error' => $e->getMessage()]);
            return 0;
        }

        $reconciled = 0;
        foreach ($closedAt as $txId => $ts) {
            try {
                $n = $this->db->query(
                    "UPDATE {$ks}
                     SET status = 2,
                         transaction_closed_at = COALESCE(transaction_closed_at, ?)
                     WHERE transaction_id = ? AND transaction_date = ? AND status = 1",
                    [$ts, $txId, $date]
                )->rowCount();
                if ($n > 0) {
                    $reconciled++;
                }
            } catch (\Throwable) {}
        }
        return $reconciled;
    }

    // ─── 2. close metadata refresh ────────────────────────────────────────────

    private function _refreshCloseMetadata(string $date): int
    {
        $ks   = $this->db->t('kitchen_stats');
        $rows = $this->db->query(
            "SELECT DISTINCT transaction_id FROM {$ks}
             WHERE transaction_date = ?
               AND status > 1
               AND (transaction_closed_at IS NULL OR transaction_closed_at < '2000-01-01 00:00:00')
             LIMIT 200",
            [$date]
        )->fetchAll();

        $refreshed = 0;
        foreach ($rows as $row) {
            $txId = (int) $row['transaction_id'];
            try {
                $result = $this->poster->getTransaction($txId);
                $tx     = $result[0] ?? $result;
                $status = (int) ($tx['status'] ?? 2);
                if ($status <= 1) {
                    continue;
                }
                $closedAt = $this->_resolveClosedAt($tx);
                $this->db->query(
                    "UPDATE {$ks}
                     SET status = ?, pay_type = ?, close_reason = ?, transaction_closed_at = ?
                     WHERE transaction_id = ?",
                    [
                        $status,
                        isset($tx['pay_type']) ? (int) $tx['pay_type'] : null,
                        isset($tx['reason']) && $tx['reason'] !== '' ? (int) $tx['reason'] : null,
                        $closedAt,
                        $txId,
                    ]
                );
                $refreshed++;
            } catch (\Throwable $e) {
                Logger::get()->warning('kitchen_sync.close_refresh_failed', [
                    'tx_id' => $txId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        return $refreshed;
    }

    private function _resolveClosedAt(array $tx): string|null
    {
        if (!empty($tx['date_close']) && (int) $tx['date_close'] > 0) {
            $dt = new \DateTime('@' . (int) round((int) $tx['date_close'] / 1000));
            $dt->setTimezone($this->spotTz);
            if ((int) $dt->format('Y') >= 2000) {
                return $dt->format('Y-m-d H:i:s');
            }
        }
        if (!empty($tx['date_close_date']) && $tx['date_close_date'] !== '0000-00-00 00:00:00') {
            $ts = strtotime($tx['date_close_date']);
            if ($ts !== false && $ts > 0 && (int) date('Y', $ts) >= 2000) {
                return date('Y-m-d H:i:s', $ts);
            }
        }
        return null;
    }

    // ─── 3. prob_close_at ─────────────────────────────────────────────────────

    private function _computeProbCloseAt(string $date): array
    {
        $ks = $this->db->t('kitchen_stats');
        $hid = self::HOOKAH_CATEGORY_ID;
        $baseExclude = "AND NOT (COALESCE(dish_category_id,0)={$hid} OR COALESCE(dish_sub_category_id,0)={$hid})";

        // Build map: receipt+station → earliest ready_pressed_at for finished items
        $readyRows = $this->db->query(
            "SELECT receipt_number, station, ready_pressed_at
             FROM {$ks}
             WHERE transaction_date = ?
               AND receipt_number REGEXP '^[0-9]+$'
               AND COALESCE(was_deleted, 0) = 0
               {$baseExclude}
               AND ready_pressed_at IS NOT NULL",
            [$date]
        )->fetchAll();

        $byReceiptStation = [];
        foreach ($readyRows as $r) {
            $receipt = (int) ($r['receipt_number'] ?? 0);
            $station = (string) ($r['station'] ?? '');
            if ($receipt <= 0 || $station === '') {
                continue;
            }
            $end = $r['ready_pressed_at'];
            if (!isset($byReceiptStation[$receipt][$station])
                || strtotime($end) < strtotime($byReceiptStation[$receipt][$station])) {
                $byReceiptStation[$receipt][$station] = $end;
            }
        }

        // Find pending items and compute prob_close_at from neighboring receipts
        $targets = $this->db->query(
            "SELECT id, receipt_number, station, prob_close_at, ticket_sent_at
             FROM {$ks}
             WHERE transaction_date = ?
               AND receipt_number REGEXP '^[0-9]+$'
               AND COALESCE(was_deleted, 0) = 0
               {$baseExclude}
               AND ticket_sent_at IS NOT NULL
               AND ready_pressed_at IS NULL",
            [$date]
        )->fetchAll();

        $upd      = $this->db->getPdo()->prepare("UPDATE {$ks} SET prob_close_at = ? WHERE id = ?");
        $setCount = $clearCount = 0;

        foreach ($targets as $t) {
            $id      = (int) ($t['id'] ?? 0);
            $receipt = (int) ($t['receipt_number'] ?? 0);
            $station = (string) ($t['station'] ?? '');
            if ($id <= 0 || $receipt <= 0 || $station === '') {
                continue;
            }
            $sentTs = strtotime((string) ($t['ticket_sent_at'] ?? ''));
            if ($sentTs === false || $sentTs <= 0) {
                continue;
            }

            // Look for the next 1-3 receipts with a ready time in the same station
            $candidate = null;
            for ($delta = 1; $delta <= 3; $delta++) {
                if (isset($byReceiptStation[$receipt + $delta][$station])) {
                    $c = $byReceiptStation[$receipt + $delta][$station];
                    if (strtotime($c) >= $sentTs) {
                        $candidate = $c;
                        break;
                    }
                }
            }

            $current = $t['prob_close_at'] ?? null;
            if ($candidate === null && ($current === null || $current === '')) {
                continue;
            }
            if ($candidate !== null && (string) $candidate === (string) $current) {
                continue;
            }

            $upd->execute([$candidate, $id]);
            $candidate !== null ? $setCount++ : $clearCount++;
        }

        return ['set' => $setCount, 'cleared' => $clearCount];
    }

    // ─── 4. auto-exclude ──────────────────────────────────────────────────────

    private function _applyAutoExclude(string $date): array
    {
        $ks  = $this->db->t('kitchen_stats');
        $hid = self::HOOKAH_CATEGORY_ID;

        $hookah = $this->db->query(
            "UPDATE {$ks} SET exclude_from_dashboard=1, exclude_auto=1
             WHERE transaction_date=?
               AND (COALESCE(dish_category_id,0)=? OR COALESCE(dish_sub_category_id,0)=?)",
            [$date, $hid, $hid]
        )->rowCount();

        // Exclude pending items where a future receipt closed (prob close set)
        $setProb = $this->db->query(
            "UPDATE {$ks} SET exclude_from_dashboard=1, exclude_auto=1
             WHERE transaction_date=? AND COALESCE(was_deleted,0)=0
               AND COALESCE(exclude_from_dashboard,0)=0
               AND NOT (COALESCE(dish_category_id,0)={$hid} OR COALESCE(dish_sub_category_id,0)={$hid})
               AND ready_pressed_at IS NULL AND prob_close_at IS NOT NULL",
            [$date]
        )->rowCount();

        // Exclude pending items where transaction is already closed
        $setClose = $this->db->query(
            "UPDATE {$ks} SET exclude_from_dashboard=1, exclude_auto=1
             WHERE transaction_date=? AND COALESCE(was_deleted,0)=0
               AND COALESCE(exclude_from_dashboard,0)=0
               AND NOT (COALESCE(dish_category_id,0)={$hid} OR COALESCE(dish_sub_category_id,0)={$hid})
               AND ready_pressed_at IS NULL AND prob_close_at IS NULL
               AND ticket_sent_at IS NOT NULL AND status>1
               AND transaction_closed_at IS NOT NULL
               AND transaction_closed_at>'2000-01-01 00:00:00'",
            [$date]
        )->rowCount();

        // Un-exclude items that were actually marked ready (false positive)
        $unsetFact = $this->db->query(
            "UPDATE {$ks} SET exclude_from_dashboard=0, exclude_auto=0
             WHERE transaction_date=? AND exclude_auto=1
               AND NOT (COALESCE(dish_category_id,0)={$hid} OR COALESCE(dish_sub_category_id,0)={$hid})
               AND ready_pressed_at IS NOT NULL",
            [$date]
        )->rowCount();

        // Un-exclude items that no longer match any exclusion criteria
        $unsetLost = $this->db->query(
            "UPDATE {$ks} SET exclude_from_dashboard=0, exclude_auto=0
             WHERE transaction_date=? AND exclude_auto=1
               AND NOT (COALESCE(dish_category_id,0)={$hid} OR COALESCE(dish_sub_category_id,0)={$hid})
               AND ready_pressed_at IS NULL AND prob_close_at IS NULL
               AND NOT (ticket_sent_at IS NOT NULL AND status>1
                        AND transaction_closed_at IS NOT NULL
                        AND transaction_closed_at>'2000-01-01 00:00:00')",
            [$date]
        )->rowCount();

        return [
            'hookah'     => (int) $hookah,
            'set_prob'   => (int) $setProb,
            'set_close'  => (int) $setClose,
            'unset_fact' => (int) $unsetFact,
            'unset_lost' => (int) $unsetLost,
        ];
    }

    // ─── meta ─────────────────────────────────────────────────────────────────

    private function _writeRunMeta(
        string $date,
        int $durationMs,
        int $synced,
        int $reconciled,
        int $refreshed,
        array $prob,
        array $auto
    ): void {
        $result = "duration_ms={$durationMs}; date={$date}; synced={$synced}; "
            . "open_reconciled={$reconciled}; close_refreshed={$refreshed}; "
            . "prob_set={$prob['set']}; prob_cleared={$prob['cleared']}; "
            . 'auto=' . array_sum($auto);

        $this->meta->setMany([
            'poster_last_sync_at'       => date('Y-m-d H:i:s'),
            'kitchen_last_sync_at'      => date('Y-m-d H:i:s'),
            'kitchen_last_sync_result'  => $result,
            'kitchen_last_sync_error'   => '',
        ]);
    }
}

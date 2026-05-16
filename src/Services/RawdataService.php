<?php

declare(strict_types=1);

namespace App\Services;

use App\Infrastructure\Database;

class RawdataService
{
    public function __construct(private readonly Database $db) {}

    public function getLastSyncLabel(): string
    {
        $mt = $this->db->t('system_meta');
        $ks = $this->db->t('kitchen_stats');

        try {
            $row = $this->db->query("SELECT meta_value FROM {$mt} WHERE meta_key='poster_last_sync_at' LIMIT 1")->fetch();
            if (!empty($row['meta_value'])) return date('d.m.Y H:i:s', strtotime((string)$row['meta_value']));

            $fb = $this->db->query("SELECT MAX(created_at) AS t FROM {$ks}")->fetch();
            if (!empty($fb['t'])) return date('d.m.Y H:i:s', strtotime((string)$fb['t']));
        } catch (\Throwable) {}

        return '—';
    }

    public function getReceipts(array $filters): array
    {
        $ks = $this->db->t('kitchen_stats');

        [$where, $params] = $this->_buildWhere($filters);
        $orderBy = $this->_buildOrder($filters['sort'] ?? 'receipt', $filters['dir'] ?? 'asc');

        try {
            $rows = $this->db->query(
                "SELECT ks.id, ks.transaction_id, ks.transaction_date,
                        ks.receipt_number, ks.transaction_opened_at, ks.prob_close_at,
                        ks.status, ks.table_number, ks.waiter_name,
                        ks.dish_id, ks.dish_name, ks.station,
                        ks.ticket_sent_at, ks.ready_pressed_at,
                        ks.exclude_from_dashboard, ks.exclude_auto,
                        TIMESTAMPDIFF(SECOND, ks.ticket_sent_at,
                            COALESCE(ks.ready_pressed_at, NOW())) AS wait_seconds
                 FROM {$ks} ks
                 WHERE " . implode(' AND ', $where) . "
                   AND ks.ticket_sent_at IS NOT NULL
                 ORDER BY {$orderBy}
                 LIMIT 2000",
                $params
            )->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        return $this->_groupByTransaction($rows);
    }

    public function toggleExclude(int $itemId, int $exclude): void
    {
        $ks = $this->db->t('kitchen_stats');
        $this->db->query("UPDATE {$ks} SET exclude_from_dashboard=?, exclude_auto=0 WHERE id=?", [$exclude, $itemId]);
    }

    public function startResync(string $dateFrom, string $dateTo): int
    {
        $mt  = $this->db->t('system_meta');
        $pid = $this->_runningPid();

        if ($pid > 0) return $pid;

        $php    = PHP_BINARY;
        $script = escapeshellarg(dirname(__DIR__, 2) . '/scripts/kitchen/resync_range.php');
        $log    = escapeshellarg(dirname(__DIR__, 2) . '/resync_range.log');
        $jobId  = date('Ymd_His');
        $cmd    = "{$php} {$script} " . escapeshellarg($dateFrom) . ' ' . escapeshellarg($dateTo) . ' ' . escapeshellarg($jobId);

        $out = [];
        @exec("{$cmd} >> {$log} 2>&1 & echo \$!", $out);
        $newPid = (int)trim((string)end($out));

        if ($newPid > 0) {
            try {
                $this->db->query(
                    "INSERT INTO {$mt} (meta_key, meta_value) VALUES ('kitchen_resync_job_pid', ?)
                     ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value), updated_at=CURRENT_TIMESTAMP",
                    [(string)$newPid]
                );
            } catch (\Throwable) {}
        }

        return $newPid;
    }

    private function _runningPid(): int
    {
        $mt = $this->db->t('system_meta');
        try {
            $rows = $this->db->query(
                "SELECT meta_key, meta_value FROM {$mt} WHERE meta_key IN ('kitchen_resync_job_pid','kitchen_resync_job_status')"
            )->fetchAll();
            $meta = array_column($rows, 'meta_value', 'meta_key');
            $pid  = (int)($meta['kitchen_resync_job_pid'] ?? 0);
            if ($pid > 0 && (string)($meta['kitchen_resync_job_status'] ?? '') === 'running') {
                $alive = function_exists('posix_kill') ? @posix_kill($pid, 0) : is_dir('/proc/' . $pid);
                if ($alive) return $pid;
            }
        } catch (\Throwable) {}
        return 0;
    }

    private function _buildWhere(array $f): array
    {
        $where  = ['ks.transaction_date BETWEEN ? AND ?'];
        $params = [$f['dateFrom'] ?? date('Y-m-d'), $f['dateTo'] ?? date('Y-m-d')];

        $hourStart = (int)($f['hourStart'] ?? 0);
        $hourEnd   = (int)($f['hourEnd'] ?? 23);
        if ($hourStart > 0 || $hourEnd < 23) {
            $where[]  = 'HOUR(ks.transaction_opened_at) BETWEEN ? AND ?';
            $params[] = $hourStart;
            $params[] = $hourEnd;
        }

        match ($f['status'] ?? 'all') {
            'open'   => $where[] = 'ks.status=1',
            'closed' => $where[] = 'ks.status!=1',
            default  => null,
        };

        match ($f['station'] ?? 'all') {
            'kitchen' => $where[] = "(ks.station='2' OR ks.station=2 OR ks.station='Kitchen' OR ks.station='Main')",
            'bar'     => $where[] = "(ks.station='3' OR ks.station=3 OR ks.station='Bar Veranda')",
            default   => null,
        };

        return [$where, $params];
    }

    private function _buildOrder(string $sort, string $dir): string
    {
        $dir = strtoupper($dir) === 'DESC' ? 'DESC' : 'ASC';
        $col = match ($sort) {
            'opened' => 'ks.transaction_opened_at',
            'closed' => 'ks.prob_close_at',
            'wait'   => 'wait_seconds',
            default  => 'ks.receipt_number',
        };
        return "{$col} {$dir}";
    }

    private function _groupByTransaction(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $r) {
            $txId = (int)($r['transaction_id'] ?? 0);
            $grouped[$txId] ??= [
                'transaction_id'   => $txId,
                'receipt_number'   => (string)($r['receipt_number'] ?? ''),
                'transaction_date' => (string)($r['transaction_date'] ?? ''),
                'opened_at'        => (string)($r['transaction_opened_at'] ?? ''),
                'closed_at'        => (string)($r['prob_close_at'] ?? ''),
                'status'           => (int)($r['status'] ?? 1),
                'table_number'     => (string)($r['table_number'] ?? ''),
                'waiter_name'      => (string)($r['waiter_name'] ?? ''),
                'max_wait'         => 0,
                'items'            => [],
            ];

            $wait = (int)($r['wait_seconds'] ?? 0);
            if ($wait > $grouped[$txId]['max_wait']) $grouped[$txId]['max_wait'] = $wait;

            $grouped[$txId]['items'][] = [
                'id'           => (int)($r['id'] ?? 0),
                'dish_name'    => (string)($r['dish_name'] ?? ''),
                'station'      => (string)($r['station'] ?? ''),
                'sent_at'      => (string)($r['ticket_sent_at'] ?? ''),
                'ready_at'     => (string)($r['ready_pressed_at'] ?? ''),
                'wait_seconds' => $wait,
                'excluded'     => (int)($r['exclude_from_dashboard'] ?? 0),
            ];
        }

        return array_values($grouped);
    }
}

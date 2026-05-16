<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Infrastructure\Database;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class DashboardController
{
    public function __construct(private readonly Database $db) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userEmail = $request->getAttribute('user_email', '');
        $q         = $request->getQueryParams();
        $flash     = ['ok' => '', 'err' => ''];

        $dateFrom  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $q['dateFrom'] ?? '') ? $q['dateFrom'] : date('Y-m-d');
        $dateTo    = preg_match('/^\d{4}-\d{2}-\d{2}$/', $q['dateTo'] ?? '')   ? $q['dateTo']   : date('Y-m-d');
        $hourStart = max(0, min(23, (int) ($q['hourStart'] ?? 0)));
        $hourEnd   = max(0, min(23, (int) ($q['hourEnd']   ?? 23)));
        if ($hourEnd < $hourStart) { $hourEnd = $hourStart; }

        [$hours, $slotDates, $slotHours] = $this->_buildSlots($dateFrom, $dateTo, $hourStart, $hourEnd);
        $slotCount = count($hours);

        $chartData = [
            '2' => ['label' => 'KITCHEN',     'avg' => array_fill(0, $slotCount, 0), 'max' => array_fill(0, $slotCount, 0)],
            '3' => ['label' => 'BAR VERANDA', 'avg' => array_fill(0, $slotCount, 0), 'max' => array_fill(0, $slotCount, 0)],
        ];

        $slotIndex = [];
        for ($i = 0; $i < $slotCount; $i++) {
            $slotIndex[$slotDates[$i]][(int) $slotHours[$i]] = $i;
        }

        $lastSync = $this->_lastSync();

        try {
            $rows = $this->db->query(
                "SELECT sid, d_iso, h_int,
                        ROUND(AVG(wait_min), 1) AS avg_wait,
                        ROUND(MAX(wait_min), 1) AS max_wait
                 FROM (
                      SELECT
                        CASE
                          WHEN station IN ('2',2,'Kitchen','Main') THEN '2'
                          WHEN station IN ('3',3,'Bar Veranda')    THEN '3'
                          ELSE NULL
                        END AS sid,
                        DATE(transaction_opened_at) AS d_iso,
                        HOUR(transaction_opened_at) AS h_int,
                        (TIMESTAMPDIFF(SECOND, ticket_sent_at,
                            CASE
                              WHEN ready_pressed_at IS NOT NULL THEN ready_pressed_at
                              WHEN prob_close_at IS NOT NULL AND status > 1
                               AND transaction_closed_at IS NOT NULL AND transaction_closed_at <> '0000-00-00 00:00:00'
                                THEN LEAST(prob_close_at, transaction_closed_at)
                              WHEN prob_close_at IS NOT NULL THEN prob_close_at
                              WHEN status > 1 AND transaction_closed_at IS NOT NULL AND transaction_closed_at <> '0000-00-00 00:00:00'
                                THEN transaction_closed_at
                              ELSE NULL
                            END
                        ) / 60) AS wait_min
                      FROM {$this->db->t('kitchen_stats')}
                      WHERE transaction_date BETWEEN ? AND ?
                        AND COALESCE(exclude_from_dashboard, 0) = 0
                        AND COALESCE(was_deleted, 0) = 0
                        AND ticket_sent_at IS NOT NULL
                        AND transaction_opened_at IS NOT NULL
                        AND HOUR(transaction_opened_at) BETWEEN ? AND ?
                        AND NOT (COALESCE(dish_category_id,0) = 47 OR COALESCE(dish_sub_category_id,0) = 47)
                        AND (ready_pressed_at IS NOT NULL OR prob_close_at IS NOT NULL
                             OR (status > 1 AND transaction_closed_at IS NOT NULL AND transaction_closed_at <> '0000-00-00 00:00:00'))
                 ) x
                 WHERE sid IS NOT NULL AND wait_min IS NOT NULL AND wait_min >= 0
                 GROUP BY sid, d_iso, h_int",
                [$dateFrom, $dateTo, $hourStart, $hourEnd]
            )->fetchAll();

            foreach ($rows as $r) {
                $sid = (string) ($r['sid'] ?? '');
                $idx = $slotIndex[$r['d_iso'] ?? ''][(int) ($r['h_int'] ?? -1)] ?? null;
                if ($idx === null || !isset($chartData[$sid])) { continue; }
                $chartData[$sid]['avg'][$idx] = (float) $r['avg_wait'];
                $chartData[$sid]['max'][$idx] = (float) $r['max_wait'];
            }

            if (empty($rows)) {
                $flash['err'] = 'Нет данных за выбранный период.';
            }
        } catch (\Throwable $e) {
            $flash['err'] = $e->getMessage();
        }

        ob_start();
        require __DIR__ . '/../../Views/admin/dashboard.php';
        $content = ob_get_clean();

        return $this->_layout($response, (string) $content, '/admin', $userEmail, $flash);
    }

    private function _buildSlots(string $from, string $to, int $hs, int $he): array
    {
        $hours = $dates = $hourNums = [];
        $singleDay = $from === $to;

        if ($singleDay) {
            for ($h = $hs; $h <= $he; $h++) {
                $hours[]    = sprintf('%02d:00', $h);
                $dates[]    = $from;
                $hourNums[] = $h;
            }
        } else {
            $dt    = new \DateTime($from);
            $dtEnd = new \DateTime($to);
            while ($dt <= $dtEnd) {
                $d = $dt->format('Y-m-d');
                $l = $dt->format('d.m');
                for ($h = $hs; $h <= $he; $h++) {
                    $hours[]    = $l . ' ' . sprintf('%02d:00', $h);
                    $dates[]    = $d;
                    $hourNums[] = $h;
                }
                $dt->modify('+1 day');
            }
        }

        return [$hours, $dates, $hourNums];
    }

    private function _lastSync(): string
    {
        try {
            $row = $this->db->query(
                "SELECT meta_value FROM {$this->db->t('system_meta')} WHERE meta_key = 'poster_last_sync_at' LIMIT 1"
            )->fetch();
            if (!empty($row['meta_value'])) {
                return date('d.m.Y H:i:s', strtotime($row['meta_value']));
            }
        } catch (\Throwable) {}
        return '—';
    }

    private function _layout(ResponseInterface $response, string $content, string $path, string $userEmail, array $flash): ResponseInterface
    {
        $pageTitle = 'Дашборд';
        $headExtra = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>';
        ob_start();
        $currentPath = $path;
        $flashOk  = $flash['ok'];
        $flashErr = $flash['err'];
        require __DIR__ . '/../../Views/layout.php';
        $html = ob_get_clean();
        $response->getBody()->write((string) $html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}

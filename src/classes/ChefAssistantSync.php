<?php

namespace App\Classes;

class ChefAssistantSync {
    private Database $db;
    private CodemealAPI $api;
    private \DateTimeZone $tz;

    public function __construct(Database $db, CodemealAPI $api, string $timezone) {
        $this->db = $db;
        $this->api = $api;
        $this->tz = new \DateTimeZone($timezone !== '' ? $timezone : 'Asia/Ho_Chi_Minh');
    }

    public function ensureTables(): void {
        $this->db->query("CREATE TABLE IF NOT EXISTS chef_assistant_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            dish_name_raw VARCHAR(255) NOT NULL,
            dish_name_norm VARCHAR(255) NOT NULL,
            send_at DATETIME NULL,
            start_at DATETIME NULL,
            end_at DATETIME NULL,
            ready_at DATETIME NULL,
            cooking_time_sec INT NULL,
            status_desc VARCHAR(64) NULL,
            status_css VARCHAR(64) NULL,
            fetched_at DATETIME NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_chef_assistant_order_dish (order_id, dish_name_norm),
            KEY idx_chef_assistant_ready (ready_at),
            KEY idx_chef_assistant_order (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function syncOrders(string $from, ?string $to = null, ?int $maxPages = 50): array {
        $this->ensureTables();

        $saved = 0;
        $orderIds = [];
        $fetchedAt = (new \DateTimeImmutable('now', $this->tz))->format('Y-m-d H:i:s');
        $pages = 0;

        $hardLimit = $maxPages === null ? 2000 : max(1, $maxPages);
        for ($page = 1; $page <= $hardLimit; $page++) {
            $resp = $this->api->getOrders($from, $to, '', '', $page);

            if (isset($resp['isSuccess']) && $resp['isSuccess'] === false) {
                $msg = (string)($resp['errorMessage'] ?? $resp['message'] ?? 'Request failed');
                return ['ok' => false, 'error' => $msg, 'auth_error' => $this->looksLikeAuthError($msg)];
            }

            $orders = $this->extractOrderRows($resp);
            if (count($orders) === 0) {
                break;
            }
            $pages++;

            foreach ($orders as $row) {
                if (!is_array($row)) continue;
                $orderId = (int)($row['orderId'] ?? 0);
                $nameRaw = trim((string)($row['name'] ?? ''));
                if ($orderId <= 0 || $nameRaw === '') {
                    continue;
                }
                $orderIds[$orderId] = true;
                $nameNorm = $this->normalizeDishName($nameRaw);

                $sendAt = $this->parsePartialDateTime((string)($row['sendTime'] ?? ''));
                $startAt = $this->parsePartialDateTime((string)($row['startTime'] ?? ''));
                $endAt = $this->parsePartialDateTime((string)($row['endTime'] ?? ''));
                $readyAt = $this->parsePartialDateTime((string)($row['readyTime'] ?? ''));

                $cookingTime = null;
                if (isset($row['cookingTime']) && $row['cookingTime'] !== '' && $row['cookingTime'] !== null) {
                    $cookingTime = (int)$row['cookingTime'];
                }

                $statusDesc = isset($row['statusDescription']) ? trim((string)$row['statusDescription']) : null;
                $statusCss = isset($row['statusCss']) ? trim((string)$row['statusCss']) : null;

                if ($readyAt === null) {
                    $readyAt = $endAt;
                }
                if ($readyAt === null && $cookingTime !== null && $cookingTime > 0 && $sendAt !== null && $this->looksLikeFinishedStatus($statusDesc, $statusCss)) {
                    $sendDt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $sendAt, $this->tz);
                    if ($sendDt) {
                        $seconds = $cookingTime >= 600 ? $cookingTime : ($cookingTime * 60);
                        $readyAt = $sendDt->modify('+' . $seconds . ' seconds')->format('Y-m-d H:i:s');
                    }
                }

                $this->db->query(
                    "INSERT INTO chef_assistant_items
                        (order_id, dish_name_raw, dish_name_norm, send_at, start_at, end_at, ready_at, cooking_time_sec, status_desc, status_css, fetched_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        dish_name_raw = VALUES(dish_name_raw),
                        send_at = IF(VALUES(send_at) IS NULL, chef_assistant_items.send_at, VALUES(send_at)),
                        start_at = IF(VALUES(start_at) IS NULL, chef_assistant_items.start_at, VALUES(start_at)),
                        end_at = IF(VALUES(end_at) IS NULL, chef_assistant_items.end_at, VALUES(end_at)),
                        ready_at = IF(VALUES(ready_at) IS NULL, chef_assistant_items.ready_at, VALUES(ready_at)),
                        cooking_time_sec = IF(VALUES(cooking_time_sec) IS NULL, chef_assistant_items.cooking_time_sec, VALUES(cooking_time_sec)),
                        status_desc = IF(VALUES(status_desc) IS NULL OR VALUES(status_desc) = '', chef_assistant_items.status_desc, VALUES(status_desc)),
                        status_css = IF(VALUES(status_css) IS NULL OR VALUES(status_css) = '', chef_assistant_items.status_css, VALUES(status_css)),
                        fetched_at = VALUES(fetched_at)",
                    [
                        $orderId,
                        $nameRaw,
                        $nameNorm,
                        $sendAt,
                        $startAt,
                        $endAt,
                        $readyAt,
                        $cookingTime,
                        $statusDesc,
                        $statusCss,
                        $fetchedAt
                    ]
                );
                $saved++;
            }
        }

        return ['ok' => true, 'saved' => $saved, 'pages' => $pages, 'order_ids' => array_keys($orderIds)];
    }

    public function updateKitchenStatsReadyChAss(string $dateFrom, string $dateTo, array $orderIds): int {
        if (empty($orderIds)) return 0;

        $items = [];
        $chunks = array_chunk($orderIds, 200);
        foreach ($chunks as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $rows = $this->db->query(
                "SELECT order_id, dish_name_raw, ready_at
                 FROM chef_assistant_items
                 WHERE order_id IN ($in)
                   AND ready_at IS NOT NULL",
                $chunk
            )->fetchAll();
            foreach ($rows as $r) {
                $oid = (int)($r['order_id'] ?? 0);
                $ready = $r['ready_at'] ?? null;
                $raw = (string)($r['dish_name_raw'] ?? '');
                if ($oid > 0 && $raw !== '' && $ready) {
                    foreach ($this->normalizeDishNameVariants($raw) as $norm) {
                        if ($norm === '') continue;
                        $items[$oid][$norm] = $ready;
                    }
                }
            }
        }

        if (empty($items)) return 0;

        $updated = 0;
        $updatePrepared = $this->db->getPdo()->prepare("UPDATE kitchen_stats SET ready_chass_at = ? WHERE id = ?");

        foreach ($chunks as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $params = array_merge([$dateFrom, $dateTo], $chunk);
            $rows = $this->db->query(
                "SELECT id, receipt_number, dish_name
                 FROM kitchen_stats
                 WHERE transaction_date BETWEEN ? AND ?
                   AND receipt_number IN ($in)",
                $params
            )->fetchAll();
            foreach ($rows as $r) {
                $id = (int)($r['id'] ?? 0);
                $receipt = (int)($r['receipt_number'] ?? 0);
                $dishName = (string)($r['dish_name'] ?? '');
                if ($id <= 0 || $receipt <= 0 || $dishName === '') continue;
                $ready = null;
                foreach ($this->normalizeDishNameVariants($dishName) as $norm) {
                    if ($norm === '') continue;
                    if (isset($items[$receipt][$norm])) {
                        $ready = $items[$receipt][$norm];
                        break;
                    }
                }
                if ($ready === null) continue;
                $updatePrepared->execute([$ready, $id]);
                $updated++;
            }
        }

        return $updated;
    }

    private function looksLikeAuthError(string $msg): bool {
        $m = mb_strtolower($msg);
        return str_contains($m, 'unauthor') || str_contains($m, 'forbidden') || str_contains($m, '401') || str_contains($m, '403');
    }

    private function extractOrderRows($resp): array {
        if (!is_array($resp)) return [];
        if (isset($resp['orders']) && is_array($resp['orders'])) return $resp['orders'];
        if (isset($resp['response']) && is_array($resp['response'])) return $resp['response'];
        $keys = array_keys($resp);
        $isList = $keys === range(0, count($resp) - 1);
        if ($isList) return $resp;
        return [];
    }

    private function looksLikeFinishedStatus(?string $desc, ?string $css): bool {
        $d = mb_strtolower(trim((string)$desc));
        $c = mb_strtolower(trim((string)$css));
        $hay = $d . ' ' . $c;
        return str_contains($hay, 'ready')
            || str_contains($hay, 'done')
            || str_contains($hay, 'finish')
            || str_contains($hay, 'complete')
            || str_contains($hay, 'готов')
            || str_contains($hay, 'заверш');
    }

    private function parsePartialDateTime(string $raw): ?string {
        $raw = trim($raw);
        if ($raw === '') return null;
        $now = new \DateTimeImmutable('now', $this->tz);
        $year = $now->format('Y');
        $dt = $this->parseDayMonthTimeWithYear($raw, (int)$year);
        if ($dt === null) return null;
        if ($dt > $now->modify('+1 day')) {
            $dt = $dt->modify('-1 year');
        }
        return $dt->format('Y-m-d H:i:s');
    }

    private function normalizeDishName(string $name): string {
        $name = str_replace('\\/', '/', $name);
        $name = preg_replace('/^\s*\d+(?:[.,]\d+)?\s*/u', '', $name);
        $name = mb_strtolower(trim($name));
        $name = preg_replace('/[^\p{L}\p{N}]+/u', '', $name);
        return $name ?? '';
    }

    private function normalizeDishNameVariants(string $name): array {
        $name = str_replace('\\/', '/', $name);
        $name = trim($name);
        $parts = [$name];
        if (str_contains($name, '/')) {
            foreach (explode('/', $name) as $p) {
                $p = trim($p);
                if ($p !== '') $parts[] = $p;
            }
        }

        $variants = [];
        foreach ($parts as $p) {
            $variants[] = $this->normalizeDishName($p);
        }
        $variants = array_values(array_unique(array_filter($variants, fn($v) => $v !== null && $v !== '')));
        usort($variants, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        return $variants;
    }

    private function parseDayMonthTimeWithYear(string $raw, int $year): ?\DateTimeImmutable {
        $raw = trim($raw);
        if ($raw === '') return null;

        $m = [];
        if (!preg_match('/^(\d{1,2})\s+([A-Za-z]{3})\s+(\d{2}:\d{2}:\d{2})$/', $raw, $m)) {
            return null;
        }
        $day = (int)$m[1];
        $mon = strtolower($m[2]);
        $time = $m[3];

        $monthMap = [
            'jan' => 1,
            'feb' => 2,
            'mar' => 3,
            'apr' => 4,
            'may' => 5,
            'jun' => 6,
            'jul' => 7,
            'aug' => 8,
            'sep' => 9,
            'oct' => 10,
            'nov' => 11,
            'dec' => 12,
        ];
        if (!isset($monthMap[$mon])) return null;
        $month = $monthMap[$mon];

        $dtStr = sprintf('%04d-%02d-%02d %s', $year, $month, $day, $time);
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dtStr, $this->tz);
        if (!$dt) return null;
        return $dt;
    }
}

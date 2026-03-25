<?php

namespace App\Classes;

class KitchenAnalytics {
    private PosterAPI $api;
    private array $productNames = [];
    private array $productMainCategories = [];
    private array $productSubCategories = [];
    private array $employeesById = [];

    public function __construct(PosterAPI $api) {
        $this->api = $api;
    }

    /**
     * Карта цехов для продуктов
     */
    private array $productWorkshops = [];
    private array $workshopNames = [];

    /**
     * Загрузка данных всех товаров для кэширования
     */
    private function loadProductData(): void {
        if (!empty($this->productNames)) return;
        
        try {
            $products = $this->api->request('menu.getProducts');
            foreach ($products as $p) {
                $productId = (int)($p['product_id'] ?? 0);
                if ($productId <= 0) {
                    continue;
                }
                $this->productNames[$productId] = $p['product_name'] ?? ('Product #' . $productId);
                $this->productWorkshops[$productId] = $p['workshop'] ?? null;
                $this->productMainCategories[$productId] = (int)($p['category_id'] ?? $p['menu_category_id'] ?? $p['main_category_id'] ?? 0);
                $this->productSubCategories[$productId] = (int)($p['sub_category_id'] ?? $p['menu_category_id2'] ?? $p['category2_id'] ?? 0);
            }

            $workshops = $this->api->request('menu.getWorkshops');
            foreach ($workshops as $w) {
                $this->workshopNames[$w['workshop_id']] = $w['workshop_name'];
            }
        } catch (\Exception $e) {
            error_log("Error loading product data: " . $e->getMessage());
        }
    }

    /**
     * Преобразование Unix Timestamp (мс) в локальное время Нячанга
     */
    private function formatTimestamp(int $ms): string {
        $dt = new \DateTime('@' . round($ms / 1000));
        $dt->setTimezone(new \DateTimeZone('Asia/Ho_Chi_Minh'));
        return $dt->format('Y-m-d H:i:s');
    }

    private function resolveClosedAt(array $tx): ?string {
        if ((int)($tx['status'] ?? 1) <= 1) {
            return null;
        }
        if (!empty($tx['date_close']) && (int)$tx['date_close'] > 0) {
            $closedAt = $this->formatTimestamp((int)$tx['date_close']);
            if ((int)date('Y', strtotime($closedAt)) >= 2000) {
                return $closedAt;
            }
        }
        if (!empty($tx['date_close_date']) && $tx['date_close_date'] !== '0000-00-00 00:00:00') {
            $ts = strtotime($tx['date_close_date']);
            if ($ts !== false && $ts > 0 && (int)date('Y', $ts) >= 2000) {
                return date('Y-m-d H:i:s', $ts);
            }
        }
        return null;
    }

    private function resolveWaiterName(int $transactionId, array $tx): string {
        $name = trim((string)($tx['name'] ?? ''));
        if ($name !== '' && !is_numeric($name)) {
            return $name;
        }

        $empName = trim((string)($tx['employee_name'] ?? ''));
        if ($empName !== '') {
            return $empName;
        }

        $txUserId = isset($tx['user_id']) ? (int)$tx['user_id'] : 0;
        if ($txUserId > 0) {
            if (empty($this->employeesById)) {
                try {
                    $employees = $this->api->request('access.getEmployees');
                    if (is_array($employees)) {
                        foreach ($employees as $employee) {
                            $id = (int)($employee['user_id'] ?? 0);
                            $employeeName = trim((string)($employee['name'] ?? ''));
                            if ($id > 0 && $employeeName !== '') {
                                $this->employeesById[$id] = $employeeName;
                            }
                        }
                    }
                } catch (\Exception $e) {
                }
            }
            if (isset($this->employeesById[$txUserId])) {
                return $this->employeesById[$txUserId];
            }
        }

        $historyUserId = 0;
        try {
            $history = $this->api->request('dash.getTransactionHistory', ['transaction_id' => $transactionId]);
            if (is_array($history)) {
                foreach ($history as $event) {
                    $type = $event['type_history'] ?? '';
                    if ($type === 'open' || $type === 'print') {
                        $candidate = (int)($event['value'] ?? 0);
                        if ($candidate > 0) {
                            $historyUserId = $candidate;
                            break;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
        }

        if ($historyUserId > 0) {
            if (empty($this->employeesById)) {
                try {
                    $employees = $this->api->request('access.getEmployees');
                    if (is_array($employees)) {
                        foreach ($employees as $employee) {
                            $id = (int)($employee['user_id'] ?? 0);
                            $employeeName = trim((string)($employee['name'] ?? ''));
                            if ($id > 0 && $employeeName !== '') {
                                $this->employeesById[$id] = $employeeName;
                            }
                        }
                    }
                } catch (\Exception $e) {
                }
            }
            if (isset($this->employeesById[$historyUserId])) {
                return $this->employeesById[$historyUserId];
            }
        }

        return '';
    }

    /**
     * Анализ Kitchen Kit событий для периода дат
     */
    public function getStatsForPeriod(string $dateFrom, string $dateTo): array {
        $this->loadProductData();
        
        $allTransactions = [];
        $offset = 0;
        $limit = 100;
        
        do {
            $params = [
                'dateFrom' => str_replace('-', '', $dateFrom),
                'dateTo' => str_replace('-', '', $dateTo),
                'include_products' => 'true',
                'include_history' => 'true',
                'status' => 0,
                'limit' => $limit,
                'offset' => $offset
            ];
            
            $batch = $this->api->request('dash.getTransactions', $params);
            $allTransactions = array_merge($allTransactions, $batch);
            
            $count = count($batch);
            $offset += $limit;
        } while ($count === $limit);

        $results = [];

        foreach ($allTransactions as $tx) {
            $txId = $tx['transaction_id'];
            $history = $tx['history'] ?? [];
            $products = $tx['products'] ?? [];
            $needsCloseDetails = ((int)($tx['status'] ?? 1) > 1) && (
                $this->resolveClosedAt($tx) === null ||
                !array_key_exists('pay_type', $tx) ||
                !array_key_exists('reason', $tx)
            );

            if (empty($products) || $needsCloseDetails) {
                try {
                    $detailedTx = $this->api->getTransaction((int)$txId);
                    $detailed = $detailedTx[0] ?? $detailedTx;
                    if (!empty($detailed)) {
                        $tx = array_merge($tx, $detailed);
                        $products = $tx['products'] ?? $products;
                        $history = $tx['history'] ?? $history;
                    }
                } catch (\Exception $e) {
                    error_log("Error fetching detailed transaction $txId: " . $e->getMessage());
                }
            }

            $waiterName = $this->resolveWaiterName((int)$txId, $tx);
            $productQty = [];
            foreach ($products as $p) {
                $pid = (int)($p['product_id'] ?? 0);
                if ($pid <= 0) continue;
                $cntRaw = $p['count'] ?? ($p['quantity'] ?? null);
                $cnt = is_numeric($cntRaw) ? (int)$cntRaw : 0;
                if ($cnt < 0) $cnt = 0;
                $productQty[$pid] = max($productQty[$pid] ?? 0, $cnt);
            }

            $productInstances = $this->extractProductKitchenInstances($history, $productQty);

            foreach ($productInstances as $pId => $instances) {
                $pId = (int)$pId;
                if ($pId <= 0) continue;
                if (!is_array($instances) || count($instances) === 0) continue;

                $seq = 1;
                foreach ($instances as $inst) {
                    $results[] = [
                    'date' => date('Y-m-d', ($tx['date_start'] ?? time()*1000) / 1000),
                    'receipt_number' => $tx['receipt_number'] ?? ($tx['transaction_id'] ?? 'N/A'),
                    'transaction_opened_at' => isset($tx['date_start']) ? $this->formatTimestamp($tx['date_start']) : null,
                    'transaction_closed_at' => $this->resolveClosedAt($tx),
                    'transaction_id' => $txId,
                    'table_number' => $tx['table_name'] ?? ($tx['table_id'] ?? null),
                    'waiter_name' => $waiterName !== '' ? $waiterName : null,
                    'transaction_comment' => isset($tx['transaction_comment']) && trim((string)$tx['transaction_comment']) !== '' ? trim((string)$tx['transaction_comment']) : null,
                    'status' => $tx['status'] ?? 1,
                    'pay_type' => isset($tx['pay_type']) ? (int)$tx['pay_type'] : null,
                    'close_reason' => isset($tx['reason']) && $tx['reason'] !== '' ? (int)$tx['reason'] : null,
                    'dish_id' => $pId,
                    'item_seq' => $seq,
                    'dish_category_id' => $this->productMainCategories[$pId] ?? null,
                    'dish_sub_category_id' => $this->productSubCategories[$pId] ?? null,
                    'dish_name' => $this->productNames[$pId] ?? ('Product #' . $pId),
                    'ticket_sent_at' => $inst['sent'] ?? null,
                    'ready_pressed_at' => $inst['ready'] ?? null,
                    'was_deleted' => $inst['was_deleted'] ?? false,
                    'service_type' => $tx['service_mode'] ?? 1,
                    'total_sum' => ($tx['payed_sum'] ?? 0) / 100,
                    'station' => $this->workshopNames[$this->productWorkshops[$pId] ?? 0] ?? ($this->productWorkshops[$pId] ?? 'N/A')
                ];
                    $seq++;
                }
            }
        }

        return $results;
    }

    /**
     * Анализ Kitchen Kit событий для конкретной даты (устаревший, оставлен для совместимости)
     */
    public function getDailyStats(string $date): array {
        return $this->getStatsForPeriod($date, $date);
    }

    /**
     * Извлечение времени из истории событий по каждому продукту
     */
    private function extractProductKitchenInstances(array $history, array $productQtyById): array {
        $sendTimesById = [];
        $firstSendById = [];
        $finishTimesById = [];
        $deletedCount = [];
        $sendTotalById = [];
        $maxCountById = [];

        foreach ($history as $event) {
            $type = $event['type_history'] ?? null;
            $time = isset($event['time']) ? $this->formatTimestamp((int)$event['time']) : null;

            if ($type === 'sendtokitchen') {
                $items = $event['value_text'] ?? [];
                if (!is_array($items)) continue;
                foreach ($items as $item) {
                    $pId = (int)($item['product_id'] ?? 0);
                    if ($pId <= 0) continue;
                    $count = array_key_exists('count', $item) ? (int)$item['count'] : 1;
                    if ($count <= 0) continue;
                    if (!isset($sendTimesById[$pId])) $sendTimesById[$pId] = [];
                    if (!isset($firstSendById[$pId]) && $time) $firstSendById[$pId] = $time;
                    $sendTotalById[$pId] = ($sendTotalById[$pId] ?? 0) + $count;
                    for ($i = 0; $i < $count; $i++) $sendTimesById[$pId][] = $time;
                }
                continue;
            }

            if ($type === 'finishedcooking') {
                $pId = (int)($event['value'] ?? 0);
                if ($pId <= 0) continue;
                if (!isset($finishTimesById[$pId])) $finishTimesById[$pId] = [];
                $finishTimesById[$pId][] = $time;
                continue;
            }

            if ($type === 'deleteitem' || $type === 'delete') {
                $pId = (int)($event['value'] ?? 0);
                if ($pId <= 0) continue;
                $deletedCount[$pId] = ($deletedCount[$pId] ?? 0) + 1;
                continue;
            }

            if ($type === 'changeitemcount') {
                $pId = (int)($event['value'] ?? 0);
                if ($pId <= 0) continue;
                $count = (int)($event['value2'] ?? 0);
                if ($count > 0) {
                    $maxCountById[$pId] = max($maxCountById[$pId] ?? 0, $count);
                } else {
                    $deletedCount[$pId] = ($deletedCount[$pId] ?? 0) + 1;
                }
                continue;
            }
        }

        $instances = [];
        $pids = array_unique(array_merge(
            array_map('intval', array_keys($productQtyById)),
            array_map('intval', array_keys($sendTotalById)),
            array_map('intval', array_keys($maxCountById))
        ));
        foreach ($pids as $pId) {
            if ($pId <= 0) continue;
            $qty = max(
                (int)($productQtyById[$pId] ?? 0),
                (int)($sendTotalById[$pId] ?? 0),
                (int)($maxCountById[$pId] ?? 0)
            );
            if ($qty <= 0) $qty = 1;
            $instances[$pId] = [];
            $sendList = $sendTimesById[$pId] ?? [];
            $finishList = $finishTimesById[$pId] ?? [];
            $fallbackSent = $firstSendById[$pId] ?? null;
            for ($i = 0; $i < $qty; $i++) {
                $instances[$pId][] = [
                    'sent' => $sendList[$i] ?? $fallbackSent,
                    'ready' => $finishList[$i] ?? null,
                    'was_deleted' => false
                ];
            }
        }

        foreach ($deletedCount as $pId => $del) {
            $del = (int)$del;
            if ($del <= 0) continue;
            if (!isset($instances[$pId])) continue;
            for ($i = count($instances[$pId]) - 1; $i >= 0 && $del > 0; $i--) {
                if (!empty($instances[$pId][$i]['ready'])) continue;
                if (($instances[$pId][$i]['was_deleted'] ?? false) === true) continue;
                $instances[$pId][$i]['was_deleted'] = true;
                $del--;
            }
        }

        return $instances;
    }
}

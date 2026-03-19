<?php

namespace App\Classes;

class KitchenAnalytics {
    private PosterAPI $api;
    private array $productNames = [];
    private array $productMainCategories = [];
    private array $productSubCategories = [];

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

            $productTimes = $this->extractProductKitchenTimes($history);

            foreach ($products as $product) {
                $pId = $product['product_id'];
                $times = $productTimes[$pId] ?? ['sent' => null, 'ready' => null];

                $results[] = [
                    'date' => date('Y-m-d', ($tx['date_start'] ?? time()*1000) / 1000),
                    'receipt_number' => $tx['receipt_number'] ?? ($tx['transaction_id'] ?? 'N/A'),
                    'transaction_opened_at' => isset($tx['date_start']) ? $this->formatTimestamp($tx['date_start']) : null,
                    'transaction_closed_at' => $this->resolveClosedAt($tx),
                    'transaction_id' => $txId,
                    'table_number' => $tx['table_name'] ?? ($tx['table_id'] ?? null),
                    'status' => $tx['status'] ?? 1,
                    'pay_type' => isset($tx['pay_type']) ? (int)$tx['pay_type'] : null,
                    'close_reason' => isset($tx['reason']) && $tx['reason'] !== '' ? (int)$tx['reason'] : null,
                    'dish_id' => $pId,
                    'dish_category_id' => $this->productMainCategories[$pId] ?? null,
                    'dish_sub_category_id' => $this->productSubCategories[$pId] ?? null,
                    'dish_name' => $this->productNames[$pId] ?? ($product['product_name'] ?? ('Product #' . $pId)),
                    'ticket_sent_at' => $times['sent'] ?? null,
                    'ready_pressed_at' => $times['ready'] ?? null,
                    'was_deleted' => $times['was_deleted'] ?? false,
                    'service_type' => $tx['service_mode'] ?? 1,
                    'total_sum' => ($tx['payed_sum'] ?? 0) / 100,
                    'station' => $this->workshopNames[$this->productWorkshops[$pId] ?? 0] ?? ($this->productWorkshops[$pId] ?? 'N/A')
                ];
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
    private function extractProductKitchenTimes(array $history): array {
        $productEvents = [];
        $productCounts = [];

        foreach ($history as $event) {
            $type = $event['type_history'] ?? null;
            $time = isset($event['time']) ? $this->formatTimestamp($event['time']) : null;

            if ($type === 'sendtokitchen') {
                $items = $event['value_text'] ?? [];
                if (is_array($items)) {
                    foreach ($items as $item) {
                        $pId = $item['product_id'] ?? null;
                        $count = (int)($item['count'] ?? 0);
                        if ($pId) {
                            if (!isset($productCounts[$pId])) $productCounts[$pId] = 0;
                            $productCounts[$pId] += $count;

                            if ($count > 0 && !isset($productEvents[$pId]['sent'])) {
                                $productEvents[$pId]['sent'] = $time;
                            }
                        }
                    }
                }
            }

            if ($type === 'finishedcooking') {
                $pId = $event['value'] ?? null;
                if ($pId) {
                    $productEvents[$pId]['ready'] = $time;
                }
            }
        }

        foreach ($productCounts as $pId => $count) {
            if (isset($productEvents[$pId])) {
                 $productEvents[$pId]['was_deleted'] = ($count <= 0);
            }
        }

        return $productEvents;
    }
}

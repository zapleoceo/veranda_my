<?php

namespace App\Roma;

require_once __DIR__ . '/../src/classes/PosterAPI.php';

class Model {
    private const ROMA_CATEGORY_ID = 47;
    private const ROMA_FACTOR = 0.65;
    private string $token;

    public function __construct(string $token) {
        $this->token = $token;
    }

    public function getSales(string $dateFrom, string $dateTo): array {
        if ($this->token === '') {
            throw new \Exception('POSTER_API_TOKEN не задан в .env');
        }

        $poster = new \App\Classes\PosterAPI($this->token);
        $txs = $poster->request('dash.getTransactions', [
            'dateFrom' => str_replace('-', '', $dateFrom),
            'dateTo' => str_replace('-', '', $dateTo),
            'status' => 2,
            'include_products' => 1,
            'include_history' => 0,
        ], 'GET');

        if (!is_array($txs)) $txs = [];

        $rows = [];
        $totalCount = 0.0;
        $totalSumMinor = 0.0;
        $totalDiscountMinor = 0.0;

        foreach ($txs as $tx) {
            if (!is_array($tx)) continue;

            $products = $this->extractProducts($tx);
            if (!$products) continue;

            $txProductsTotalMinor = 0.0;
            foreach ($products as $p) {
                if (!is_array($p)) continue;
                $txProductsTotalMinor += $this->toMinor($p['sum'] ?? $p['product_sum'] ?? $p['productSum'] ?? $p['sum_minor'] ?? 0);
            }

            $txSumMinor = $this->toMinor($tx['sum'] ?? $tx['sum_minor'] ?? 0);
            $allocBaseMinor = $txProductsTotalMinor > 0 ? $txProductsTotalMinor : $txSumMinor;
            $txDiscountMinor = $this->extractDiscountMinor($tx, $allocBaseMinor);

            $txRomaDiscountMinor = 0.0;

            foreach ($products as $p) {
                if (!is_array($p)) continue;

                $catId = (int)($p['category_id'] ?? $p['categoryId'] ?? 0);
                if ($catId !== self::ROMA_CATEGORY_ID) continue;

                $name = trim((string)($p['product_name'] ?? $p['productName'] ?? $p['name'] ?? ''));
                if ($name === '') continue;

                $count = (float)($p['count'] ?? $p['qty'] ?? $p['quantity'] ?? 0);
                $sumMinor = (float)$this->toMinor($p['sum'] ?? $p['product_sum'] ?? $p['productSum'] ?? $p['sum_minor'] ?? 0);

                $discMinor = 0.0;
                if ($txDiscountMinor > 0 && $allocBaseMinor > 0 && $sumMinor > 0) {
                    $discMinor = $txDiscountMinor * ($sumMinor / $allocBaseMinor);
                }
                $txRomaDiscountMinor += $discMinor;

                if (!isset($rows[$name])) {
                    $rows[$name] = ['product_name' => $name, 'count' => 0.0, 'sum_minor' => 0.0, 'discount_minor' => 0.0];
                }
                $rows[$name]['count'] += $count;
                $rows[$name]['sum_minor'] += $sumMinor;
                $rows[$name]['discount_minor'] += $discMinor;

                $totalCount += $count;
                $totalSumMinor += $sumMinor;
            }

            $totalDiscountMinor += $txRomaDiscountMinor;
        }

        $items = array_values($rows);
        usort($items, function ($a, $b) {
            $sa = (float)($a['sum_minor'] ?? 0);
            $sb = (float)($b['sum_minor'] ?? 0);
            if ($sa === $sb) return strcmp((string)$a['product_name'], (string)$b['product_name']);
            return ($sa < $sb) ? 1 : -1;
        });

        $outItems = [];
        foreach ($items as $it) {
            $outItems[] = [
                'product_name' => (string)$it['product_name'],
                'count' => $this->fmtCount($it['count']),
                'discount' => $this->fmtMoney((float)($it['discount_minor'] ?? 0)),
                'sum' => $this->fmtMoney($it['sum_minor']),
                'sum_minor' => (int)round((float)$it['sum_minor']),
                'discount_minor' => (int)round((float)($it['discount_minor'] ?? 0)),
            ];
        }

        $netSumMinor = $totalSumMinor - $totalDiscountMinor;
        $romaMinor = $totalSumMinor * self::ROMA_FACTOR;
        $romaDiscountMinor = $totalDiscountMinor * self::ROMA_FACTOR;
        $romaNetMinor = $netSumMinor * self::ROMA_FACTOR;

        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'category_id' => self::ROMA_CATEGORY_ID,
            'items' => $outItems,
            'totals' => [
                'count' => $this->fmtCount($totalCount),
                'sum' => $this->fmtMoney($totalSumMinor),
                'discount' => $this->fmtMoney($totalDiscountMinor),
                'net' => $this->fmtMoney($netSumMinor),
                'sum_minor' => (int)round($totalSumMinor),
                'discount_minor' => (int)round($totalDiscountMinor),
                'net_minor' => (int)round($netSumMinor),
            ],
            'roma' => [
                'factor' => self::ROMA_FACTOR,
                'sum' => $this->fmtMoney($romaMinor),
                'sum_minor' => (int)round($romaMinor),
                'discount' => $this->fmtMoney($romaDiscountMinor),
                'discount_minor' => (int)round($romaDiscountMinor),
                'net' => $this->fmtMoney($romaNetMinor),
                'net_minor' => (int)round($romaNetMinor),
            ],
        ];
    }

    private function extractProducts(array $tx): array {
        $p = $tx['products'] ?? $tx['product'] ?? $tx['items'] ?? null;
        if (!is_array($p)) return [];
        if (isset($p['product_name']) || isset($p['productName']) || isset($p['category_id']) || isset($p['categoryId'])) {
            return [$p];
        }
        return $p;
    }

    private function extractDiscountMinor(array $tx, float $allocBaseMinor): float {
        $raw = $tx['discount_sum'] ?? $tx['discountSum'] ?? $tx['discount'] ?? 0;
        $v = (float)$this->toMinor($raw);
        if ($v <= 0) return 0.0;
        if ($v <= 100.0 && $allocBaseMinor > 0) {
            return $allocBaseMinor * ($v / 100.0);
        }
        return $v;
    }

    private function toMinor($v): float {
        if (is_int($v)) return (float)$v;
        if (is_float($v)) return $v;
        if (is_numeric($v)) return (float)$v;
        if (is_string($v)) {
            $t = trim($v);
            if ($t === '') return 0.0;
            $t = str_replace(',', '.', $t);
            return is_numeric($t) ? (float)$t : 0.0;
        }
        return 0.0;
    }

    private function fmtCount(float $v): string {
        $n = (float)$v;
        if (abs($n - round($n)) < 0.0001) return (string)((int)round($n));
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }

    private function fmtMoney(float $minor): string {
        $n = (float)$minor;
        $vnd = (int)round($n / 100);
        return number_format($vnd, 0, '.', ' ');
    }
}

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
        $resp = $poster->request('dash.getProductsSales', [
            'date_from' => str_replace('-', '', $dateFrom),
            'date_to' => str_replace('-', '', $dateTo),
        ], 'GET');

        if (!is_array($resp)) $resp = [];

        $rows = [];
        $totalCount = 0.0;
        $totalSumMinor = 0.0;
        $totalDiscountMinor = 0.0;
        $totalPayedMinor = 0.0;
        $totalPriceWeightedMinor = 0.0;

        foreach ($resp as $r) {
            $catId = (int)($r['category_id'] ?? 0);
            if ($catId !== self::ROMA_CATEGORY_ID) continue;

            $name = trim((string)($r['product_name'] ?? ''));
            if ($name === '') continue;

            $count = (float)($r['count'] ?? 0);
            $sumMinor = (float)($r['product_sum'] ?? 0);
            $discountMinor = (float)($r['discount'] ?? 0);
            $payedMinor = (float)($r['payed_sum'] ?? 0);
            $priceMinor = (float)($r['price'] ?? 0);
            if ($priceMinor <= 0 && $count > 0 && $sumMinor > 0) {
                $priceMinor = $sumMinor / $count;
            }

            if (!isset($rows[$name])) {
                $rows[$name] = [
                    'product_name' => $name,
                    'count' => 0.0,
                    'sum_minor' => 0.0,
                    'discount_minor' => 0.0,
                    'payed_minor' => 0.0,
                    'price_weighted_minor' => 0.0,
                ];
            }
            $rows[$name]['count'] += $count;
            $rows[$name]['sum_minor'] += $sumMinor;
            $rows[$name]['discount_minor'] += $discountMinor;
            $rows[$name]['payed_minor'] += $payedMinor;
            $rows[$name]['price_weighted_minor'] += ($priceMinor > 0 && $count > 0) ? ($priceMinor * $count) : 0.0;

            $totalCount += $count;
            $totalSumMinor += $sumMinor;
            $totalDiscountMinor += $discountMinor;
            $totalPayedMinor += $payedMinor;
            $totalPriceWeightedMinor += ($priceMinor > 0 && $count > 0) ? ($priceMinor * $count) : 0.0;
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
            $cnt = (float)($it['count'] ?? 0);
            $priceMinor = ($cnt > 0)
                ? ((float)($it['price_weighted_minor'] ?? 0) / $cnt)
                : 0.0;
            $outItems[] = [
                'product_name' => (string)$it['product_name'],
                'count' => $this->fmtCount($cnt),
                'price' => $this->fmtMoney($priceMinor),
                'price_minor' => (int)round($priceMinor),
                'discount' => $this->fmtMoney((float)($it['discount_minor'] ?? 0)),
                'discount_minor' => (int)round((float)($it['discount_minor'] ?? 0)),
                'payed_sum' => $this->fmtMoney((float)($it['payed_minor'] ?? 0)),
                'payed_minor' => (int)round((float)($it['payed_minor'] ?? 0)),
                'sum' => $this->fmtMoney($it['sum_minor']),
                'sum_minor' => (int)round((float)$it['sum_minor']),
            ];
        }

        $netSumMinor = $totalSumMinor - $totalDiscountMinor;
        $avgPriceMinor = $totalCount > 0 ? ($totalPriceWeightedMinor / $totalCount) : 0.0;
        $romaMinor = $totalPayedMinor * self::ROMA_FACTOR;
        $romaDiscountMinor = $totalDiscountMinor * self::ROMA_FACTOR;
        $romaNetMinor = $netSumMinor * self::ROMA_FACTOR;
        $romaGrossMinor = $totalSumMinor * self::ROMA_FACTOR;

        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'category_id' => self::ROMA_CATEGORY_ID,
            'items' => $outItems,
            'totals' => [
                'count' => $this->fmtCount($totalCount),
                'price' => $this->fmtMoney($avgPriceMinor),
                'price_minor' => (int)round($avgPriceMinor),
                'discount' => $this->fmtMoney($totalDiscountMinor),
                'discount_minor' => (int)round($totalDiscountMinor),
                'payed_sum' => $this->fmtMoney($totalPayedMinor),
                'payed_minor' => (int)round($totalPayedMinor),
                'sum' => $this->fmtMoney($totalSumMinor),
                'sum_minor' => (int)round($totalSumMinor),
                'net' => $this->fmtMoney($netSumMinor),
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
                'gross' => $this->fmtMoney($romaGrossMinor),
                'gross_minor' => (int)round($romaGrossMinor),
            ],
        ];
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

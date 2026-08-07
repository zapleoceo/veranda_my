<?php

declare(strict_types=1);

namespace App\Cashflow\Services;

use App\Cashflow\Domain\FinanceMap;
use App\Cashflow\Domain\PosterMoney;

/**
 * On-demand drill-down for one day: revenue → categories, day → checks →
 * items, and an expense/income column → the finance rows behind it.
 *
 * All money via PosterMoney (dash.* = cents ÷100, finance.* = signed cents).
 */
final class DrilldownService
{
    private const TZ = 'Asia/Ho_Chi_Minh';

    public function __construct(private readonly PosterHttp $http) {}

    /** L1: how a revenue cell is built + the day's category breakdown. */
    public function dayRevenue(string $date): array
    {
        $ymd  = str_replace('-', '', $date);
        $cats = $this->http->get('dash.getCategoriesSales', ['dateFrom' => $ymd, 'dateTo' => $ymd]);

        $total = 0;
        $hookah = 0;
        $hookahCount = 0;
        $list = [];
        foreach ($cats as $c) {
            $rev    = PosterMoney::fromDashCents($c['revenue'] ?? 0);
            $count  = (int) round((float) ($c['count'] ?? 0));
            $isH    = in_array((int) ($c['category_id'] ?? -1), RevenueService::HOOKAH_CATEGORY_IDS, true);
            $total += $rev;
            if ($isH) {
                $hookah      += $rev;
                $hookahCount += $count;
            }
            $list[] = [
                'name'    => (string) ($c['category_name'] ?? ''),
                'revenue' => $rev,
                'count'   => $count,
                'hookah'  => $isH,
            ];
        }
        usort($list, static fn ($a, $b) => $b['revenue'] <=> $a['revenue']);

        return [
            'date'        => $date,
            'total'       => $total,
            'hookah'      => $hookah,
            'food'        => $total - $hookah,
            'hookahCount' => $hookahCount,
            'categories'  => $list,
        ];
    }

    /** L2 + L3: the day's checks, each with its line items inline. */
    public function dayChecks(string $date): array
    {
        $ymd    = str_replace('-', '', $date);
        $checks = $this->http->get('dash.getTransactions', [
            'dateFrom'         => $ymd,
            'dateTo'           => $ymd,
            'include_products' => 'true',
            'status'           => 0,
        ]);
        $names = $this->productNames();

        $out = [];
        foreach ($checks as $c) {
            if (!is_array($c)) {
                continue;
            }
            $items = [];
            foreach (is_array($c['products'] ?? null) ? $c['products'] : [] as $p) {
                if (!is_array($p)) {
                    continue;
                }
                $pid = (int) ($p['product_id'] ?? 0);
                $qty = (float) ($p['num'] ?? 0);
                $items[] = [
                    'name' => $names[$pid] ?? ('#' . $pid),
                    'qty'  => rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.'),
                    'sum'  => PosterMoney::fromDashCents($p['payed_sum'] ?? $p['product_price'] ?? 0),
                ];
            }
            $out[] = [
                'id'     => (int) ($c['transaction_id'] ?? 0),
                'time'   => $this->checkTime($c),
                'waiter' => (string) ($c['name'] ?? ''),
                'table'  => (string) ($c['table_name'] ?? $c['table_id'] ?? ''),
                'sum'    => PosterMoney::fromDashCents($c['sum'] ?? $c['payed_sum'] ?? 0),
                'cash'   => PosterMoney::fromDashCents($c['payed_cash'] ?? 0),
                'card'   => PosterMoney::fromDashCents($c['payed_card'] ?? 0),
                'items'  => $items,
            ];
        }
        usort($out, static fn ($a, $b) => strcmp($a['time'], $b['time']));
        return $out;
    }

    /** L1′: the finance rows behind one expense/income column for the day. */
    public function dayExpenses(string $date, string $columnKey): array
    {
        $cats  = [];
        $label = $columnKey;
        foreach (FinanceMap::columns() as $col) {
            if ($col['key'] === $columnKey) {
                $cats  = $col['cats'];
                $label = $col['label'];
                break;
            }
        }
        $catSet = array_fill_keys($cats, true);

        $ymd = str_replace('-', '', $date);
        $ops = $this->http->get('finance.getTransactions', ['dateFrom' => $ymd, 'dateTo' => $ymd]);
        $names = $this->financeCategoryNames();

        $rows = [];
        foreach ($ops as $op) {
            $cid = (int) ($op['category_id'] ?? -1);
            if ($cats !== [] && !isset($catSet[$cid])) {
                continue;
            }
            $rows[] = [
                'time'     => substr((string) ($op['date'] ?? ''), 11, 5),
                'category' => $names[$cid] ?? ('#' . $cid),
                'amount'   => PosterMoney::fromFinanceCents($op['amount'] ?? 0),
                'comment'  => (string) ($op['comment'] ?? ''),
            ];
        }
        usort($rows, static fn ($a, $b) => strcmp($a['time'], $b['time']));
        return ['date' => $date, 'column' => $label, 'rows' => $rows];
    }

    /** date_close_date ("Y-m-d H:i:s") preferred; else the ms timestamp. */
    private function checkTime(array $c): string
    {
        $d = (string) ($c['date_close_date'] ?? '');
        if (strlen($d) >= 16) {
            return substr($d, 11, 5);
        }
        $ms = (float) ($c['date_close'] ?? 0);
        if ($ms > 0) {
            $dt = (new \DateTimeImmutable('@' . (int) floor($ms / 1000)))->setTimezone(new \DateTimeZone(self::TZ));
            return $dt->format('H:i');
        }
        return '';
    }

    /** @return array<int,string> */
    private function productNames(): array
    {
        $map = [];
        try {
            $prods = $this->http->get('menu.getProducts', []);
        } catch (\Throwable) {
            return $map;
        }
        foreach ($prods as $p) {
            if (!is_array($p)) {
                continue;
            }
            $id = (int) ($p['product_id'] ?? 0);
            $n  = trim((string) ($p['product_name'] ?? ''));
            if ($id > 0 && $n !== '') {
                $map[$id] = $n;
            }
        }
        return $map;
    }

    /** @return array<int,string> */
    private function financeCategoryNames(): array
    {
        $map = [];
        try {
            $cats = $this->http->get('finance.getCategories', []);
        } catch (\Throwable) {
            return $map;
        }
        foreach ($cats as $c) {
            if (!is_array($c)) {
                continue;
            }
            $id = (int) ($c['category_id'] ?? 0);
            $n  = trim((string) ($c['name'] ?? ''));
            if ($id > 0 && $n !== '') {
                $map[$id] = $n;
            }
        }
        return $map;
    }
}

<?php

declare(strict_types=1);

namespace App\Cashflow\Services;

use App\Cashflow\Domain\FinanceMap;

/**
 * Composes the full monthly P&L grid: revenue (RevenueService) + expenses
 * (ExpenseService) → per-day columns as in Excel «Лист1», plus computed profit.
 *
 * profit(day) = (food + hookah + Σ income columns: Grab, events) − Σ(expense columns)
 * where food already excludes Grab orders at menu price (RevenueService).
 */
final class ReportService
{
    private const RU_MONTHS = [
        1 => 'Январь', 2 => 'Февраль', 3 => 'Март', 4 => 'Апрель', 5 => 'Май', 6 => 'Июнь',
        7 => 'Июль', 8 => 'Август', 9 => 'Сентябрь', 10 => 'Октябрь', 11 => 'Ноябрь', 12 => 'Декабрь',
    ];

    public function __construct(
        private readonly RevenueService $revenue,
        private readonly ExpenseService $expense
    ) {}

    /**
     * @return array{year:int,month:int,label:string,columns:list<array>,rows:list<array>,totals:array,reconcile:array,unmapped:array<int,int>,financeOk:bool,generatedAt:string}
     */
    public function month(int $year, int $month): array
    {
        $rev = $this->revenue->month($year, $month);
        $exp = $this->expense->month($year, $month);
        $byDay = $exp['byDay'];

        $financeKeys = FinanceMap::financeColumnKeys();   // events + all expense columns
        $expenseKeys = FinanceMap::expenseColumnKeys();

        $rows   = [];
        $totals = ['food' => 0, 'hookah' => 0, 'total' => 0, 'profit' => 0];
        foreach ($financeKeys as $k) {
            $totals[$k] = 0;
        }

        foreach ($rev['rows'] as $r) {
            $date   = $r['date'];
            $values = ['food' => $r['food'], 'hookah' => $r['hookah']];
            foreach ($financeKeys as $k) {
                $values[$k] = $byDay[$date][$k] ?? 0;
            }

            // Не от $r['total']: там сидят и чеки Grab по цене меню (депозит,
            // не деньги). Доход Grab — колонка «Grab (поступления)».
            $profit = $r['food'] + $r['hookah'];
            foreach ($financeKeys as $k) {
                $profit += in_array($k, $expenseKeys, true) ? -$values[$k] : $values[$k];
            }

            $rows[] = [
                'day'     => $r['day'],
                'date'    => $date,
                'weekday' => $r['weekday'],
                'total'   => $r['total'],
                'values'  => $values,
                'profit'  => $profit,
            ];

            $totals['food']   += $r['food'];
            $totals['hookah'] += $r['hookah'];
            $totals['total']  += $r['total'];
            $totals['profit'] += $profit;
            foreach ($financeKeys as $k) {
                $totals[$k] += $values[$k];
            }
        }

        return [
            'year'        => $year,
            'month'       => $month,
            'label'       => (self::RU_MONTHS[$month] ?? (string) $month) . ' ' . $year,
            'columns'     => FinanceMap::columns(),
            'rows'        => $rows,
            'totals'      => $totals,
            'reconcile'   => $rev['reconcile'],
            'unmapped'    => $exp['unmapped'],
            'financeOk'   => $exp['ok'],
            'generatedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Ho_Chi_Minh')))->format('Y-m-d H:i'),
        ];
    }

    /** For the standalone comparison/verify tooling. */
    public function expenseMonthTotals(int $year, int $month): array
    {
        return $this->expense->month($year, $month)['monthByColumn'];
    }
}

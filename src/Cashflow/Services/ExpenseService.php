<?php

declare(strict_types=1);

namespace App\Cashflow\Services;

use App\Cashflow\Domain\FinanceMap;
use App\Cashflow\Domain\PosterMoney;

/**
 * Per-day expense/income columns straight from Poster's finance module.
 *
 * One finance.getTransactions call for the whole month, bucketed by day and by
 * P&L column (FinanceMap). Amounts are signed đồng (expense negative, income
 * positive); a column's displayed value is normalised to a positive number:
 * expense = −Σsigned (a cost), income = +Σsigned. Refund/income legs inside an
 * expense category therefore net down that cost automatically.
 *
 * Categories outside the map and not excluded are surfaced as `unmapped` so a
 * new Poster category can't silently vanish from the P&L (TZ §10.5).
 */
final class ExpenseService
{
    private const TZ = 'Asia/Ho_Chi_Minh';

    public function __construct(private readonly PosterHttp $http) {}

    /**
     * @return array{byDay:array<string,array<string,int>>,monthByColumn:array<string,int>,unmapped:array<int,int>,ok:bool}
     */
    public function month(int $year, int $month): array
    {
        $tz    = new \DateTimeZone(self::TZ);
        $first = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), $tz);
        $from  = $first->format('Ymd');
        $to    = $first->modify('last day of this month')->format('Ymd');

        // Category → column index + per-column kind (expense/income only).
        $catToCol = [];
        $kindOf   = [];
        foreach (FinanceMap::columns() as $c) {
            if ($c['kind'] !== 'expense' && $c['kind'] !== 'income') {
                continue;
            }
            $kindOf[$c['key']] = $c['kind'];
            foreach ($c['cats'] as $cat) {
                $catToCol[$cat] = $c['key'];
            }
        }
        $excluded = array_fill_keys(FinanceMap::EXCLUDED_CATS, true);

        try {
            $ops = $this->http->get('finance.getTransactions', ['dateFrom' => $from, 'dateTo' => $to]);
        } catch (\Throwable) {
            return ['byDay' => [], 'monthByColumn' => [], 'unmapped' => [], 'ok' => false];
        }

        $signedByDay = [];   // [date][colKey] = signed đồng
        $unmapped    = [];   // catId => signed đồng
        foreach ($ops as $op) {
            $cat = (int) ($op['category_id'] ?? -1);
            if (isset($excluded[$cat])) {
                continue;
            }
            $amt = PosterMoney::fromFinanceCents($op['amount'] ?? 0);
            $day = substr((string) ($op['date'] ?? ''), 0, 10);
            if ($day === '') {
                continue;
            }
            if (!isset($catToCol[$cat])) {
                $unmapped[$cat] = ($unmapped[$cat] ?? 0) + $amt;
                continue;
            }
            $col = $catToCol[$cat];
            $signedByDay[$day][$col] = ($signedByDay[$day][$col] ?? 0) + $amt;
        }

        // Normalise signed sums to positive display values, accumulate month totals.
        $byDay         = [];
        $monthByColumn = [];
        foreach ($signedByDay as $day => $cols) {
            foreach ($cols as $col => $signed) {
                $value           = $kindOf[$col] === 'expense' ? -$signed : $signed;
                $byDay[$day][$col]      = $value;
                $monthByColumn[$col]    = ($monthByColumn[$col] ?? 0) + $value;
            }
        }

        return ['byDay' => $byDay, 'monthByColumn' => $monthByColumn, 'unmapped' => $unmapped, 'ok' => true];
    }
}

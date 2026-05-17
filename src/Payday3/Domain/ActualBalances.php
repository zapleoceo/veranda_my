<?php

declare(strict_types=1);

namespace App\Payday3\Domain;

/**
 * User-entered cash-on-hand snapshot. Used by the balances footer
 * card to compute the diff against Poster's reported account
 * balances.
 *
 * Persisted as one row per target_date in payday_actual_balances
 * (table created by Database::createPaydayTables).
 */
final class ActualBalances
{
    public function __construct(
        public readonly string $targetDate,    // 'Y-m-d'
        public readonly ?Money $andrey  = null,
        public readonly ?Money $vietnam = null,
        public readonly ?Money $cash    = null,
        public readonly ?Money $total   = null,
    ) {}

    public static function fromRow(array $r): self
    {
        $m = static fn($v) => $v === null || $v === '' ? null : Money::vnd((int)$v);
        return new self(
            targetDate: (string)($r['target_date'] ?? ''),
            andrey:     $m($r['bal_andrey']  ?? null),
            vietnam:    $m($r['bal_vietnam'] ?? null),
            cash:       $m($r['bal_cash']    ?? null),
            total:      $m($r['bal_total']   ?? null),
        );
    }

    public function toJsonShape(): array
    {
        $j = static fn(?Money $m) => $m === null ? null : $m->amount;
        return [
            'target_date' => $this->targetDate,
            'bal_andrey'  => $j($this->andrey),
            'bal_vietnam' => $j($this->vietnam),
            'bal_cash'    => $j($this->cash),
            'bal_total'   => $j($this->total),
        ];
    }
}

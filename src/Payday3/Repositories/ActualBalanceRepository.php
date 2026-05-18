<?php

declare(strict_types=1);

namespace App\Payday3\Repositories;

use App\Infrastructure\Database;
use App\Payday3\Contracts\ActualBalanceRepositoryInterface;
use App\Payday3\Domain\ActualBalances;

/**
 * Append-only store for user-entered cash snapshots. Schema is
 * created by App\Classes\Database::createPaydayTables() (table
 * payday_actual_balances). Each save() inserts a new row so the
 * history of corrections is preserved; latestFor() returns the
 * most recent row at-or-before the given date.
 */
final class ActualBalanceRepository implements ActualBalanceRepositoryInterface
{
    public function __construct(private readonly Database $db) {}

    public function latestFor(string $date): ?ActualBalances
    {
        $t = $this->db->t('payday_actual_balances');
        $row = $this->db->query(
            "SELECT target_date, bal_andrey, bal_vietnam, bal_cash, bal_total
             FROM {$t}
             WHERE target_date <= ?
             ORDER BY target_date DESC, created_at DESC
             LIMIT 1",
            [$date]
        )->fetch();
        return $row ? ActualBalances::fromRow($row) : null;
    }

    public function save(ActualBalances $bal): int
    {
        $t = $this->db->t('payday_actual_balances');
        // Store as cents (× 100) — matches payday2's parseCents
        // convention so older rows displayed by `latestFor()` and
        // newly-saved ones share one numeric scale. The `?->amount`
        // value is in plain VND integer (no fractional unit), so
        // we just multiply.
        $toCents = static fn(?\App\Payday3\Domain\Money $m) => $m === null ? null : $m->amount * 100;
        $this->db->query(
            "INSERT INTO {$t} (target_date, bal_andrey, bal_vietnam, bal_cash, bal_total)
             VALUES (?, ?, ?, ?, ?)",
            [
                $bal->targetDate,
                $toCents($bal->andrey),
                $toCents($bal->vietnam),
                $toCents($bal->cash),
                $toCents($bal->total),
            ]
        );
        return (int)$this->db->lastInsertId();
    }
}

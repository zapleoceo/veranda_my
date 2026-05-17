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
        $this->db->query(
            "INSERT INTO {$t} (target_date, bal_andrey, bal_vietnam, bal_cash, bal_total)
             VALUES (?, ?, ?, ?, ?)",
            [
                $bal->targetDate,
                $bal->andrey?->amount,
                $bal->vietnam?->amount,
                $bal->cash?->amount,
                $bal->total?->amount,
            ]
        );
        return (int)$this->db->lastInsertId();
    }
}

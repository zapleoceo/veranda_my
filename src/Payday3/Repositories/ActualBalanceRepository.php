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
    /** Column check runs once per PHP process, not per query. */
    private static bool $stashColumnChecked = false;

    public function __construct(private readonly Database $db) {}

    public function latestFor(string $date): ?ActualBalances
    {
        $this->ensureStashColumn();
        $t = $this->db->t('payday_actual_balances');
        $row = $this->db->query(
            "SELECT target_date, bal_andrey, bal_vietnam, bal_cash, bal_stash, bal_total
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
        $this->ensureStashColumn();
        $t = $this->db->t('payday_actual_balances');
        // Store as cents (× 100) — matches payday2's parseCents
        // convention so older rows displayed by `latestFor()` and
        // newly-saved ones share one numeric scale. The `?->amount`
        // value is in plain VND integer (no fractional unit), so
        // we just multiply.
        $toCents = static fn(?\App\Payday3\Domain\Money $m) => $m === null ? null : $m->amount * 100;
        $this->db->query(
            "INSERT INTO {$t} (target_date, bal_andrey, bal_vietnam, bal_cash, bal_stash, bal_total)
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                $bal->targetDate,
                $toCents($bal->andrey),
                $toCents($bal->vietnam),
                $toCents($bal->cash),
                $toCents($bal->stash),
                $toCents($bal->total),
            ]
        );
        return (int)$this->db->lastInsertId();
    }

    /**
     * bal_stash («Заначка») was added after the table already existed on
     * prod. Database::createPaydayTables() also migrates it, but that only
     * runs from the SePay webhook — so the repository adds the column
     * itself on first use rather than 500-ing on an unknown column.
     */
    private function ensureStashColumn(): void
    {
        if (self::$stashColumnChecked) return;
        $t = $this->db->t('payday_actual_balances');
        $has = $this->db->query("SHOW COLUMNS FROM {$t} LIKE 'bal_stash'")->fetch();
        if (!$has) {
            $this->db->query("ALTER TABLE {$t} ADD COLUMN bal_stash BIGINT NULL AFTER bal_cash");
        }
        self::$stashColumnChecked = true;
    }
}

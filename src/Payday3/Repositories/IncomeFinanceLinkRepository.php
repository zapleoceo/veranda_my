<?php

declare(strict_types=1);

namespace App\Payday3\Repositories;

use App\Infrastructure\Database;
use App\Payday3\Contracts\IncomeFinanceLinkRepositoryInterface;
use App\Payday3\Domain\DateRange;
use App\Payday3\Domain\IncomeFinanceLink;

/**
 * Persistence for incoming bank (SePay) ↔ Poster finance income edges —
 * table sepay_finance_links. Same shape and range semantics as out_links:
 * a row belongs to the day it was created for (date_to).
 *
 * The table is created on first use: Database::createPaydayTables() also
 * declares it, but that only runs from the SePay webhook.
 */
final class IncomeFinanceLinkRepository implements IncomeFinanceLinkRepositoryInterface
{
    private static bool $tableChecked = false;

    public function __construct(private readonly Database $db) {}

    /** @return IncomeFinanceLink[] */
    public function listInRange(DateRange $r): array
    {
        $t = $this->table();
        $rows = $this->db->query(
            "SELECT sepay_id, finance_id, link_type FROM {$t} WHERE date_to BETWEEN ? AND ?",
            [$r->from, $r->to]
        )->fetchAll();
        return array_map(static fn(array $row) => IncomeFinanceLink::fromRow($row), $rows);
    }

    public function add(IncomeFinanceLink $link, string $dateTo): void
    {
        $t = $this->table();
        $this->db->query(
            "INSERT IGNORE INTO {$t} (sepay_id, finance_id, link_type, date_to) VALUES (?, ?, ?, ?)",
            [$link->sepayId, $link->financeId, $link->linkType, $dateTo]
        );
    }

    public function remove(int $sepayId, int $financeId): void
    {
        $t = $this->table();
        $this->db->query("DELETE FROM {$t} WHERE sepay_id = ? AND finance_id = ?", [$sepayId, $financeId]);
    }

    public function clearInRange(DateRange $r): int
    {
        $t = $this->table();
        return $this->db->query("DELETE FROM {$t} WHERE date_to BETWEEN ? AND ?", [$r->from, $r->to])->rowCount();
    }

    private function table(): string
    {
        $t = $this->db->t('sepay_finance_links');
        if (!self::$tableChecked) {
            $this->db->query(
                "CREATE TABLE IF NOT EXISTS {$t} (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    sepay_id BIGINT UNSIGNED NOT NULL,
                    finance_id BIGINT UNSIGNED NOT NULL,
                    link_type VARCHAR(16) NOT NULL DEFAULT 'manual',
                    date_to DATE NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_sepay_finance (sepay_id, finance_id),
                    KEY idx_date_to (date_to)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            self::$tableChecked = true;
        }
        return $t;
    }
}

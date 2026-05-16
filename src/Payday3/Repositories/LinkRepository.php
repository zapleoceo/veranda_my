<?php

declare(strict_types=1);

namespace App\Payday3\Repositories;

use App\Infrastructure\Database;
use App\Payday3\Contracts\LinkRepositoryInterface;
use App\Payday3\Domain\DateRange;
use App\Payday3\Domain\ReconciliationLink;

/**
 * Persistence for the sepay↔poster reconciliation graph (table
 * check_payment_links). Both sides of an edge live in their own
 * primary table; this repository owns only the edge.
 */
final class LinkRepository implements LinkRepositoryInterface
{
    public function __construct(private readonly Database $db) {}

    /** @return ReconciliationLink[] */
    public function listInRange(DateRange $r): array
    {
        $pl = $this->db->t('check_payment_links');
        $pc = $this->db->t('poster_checks');
        $st = $this->db->t('sepay_transactions');
        // Join both endpoints so we only return edges where the row on
        // each side falls into the visible window (same predicate as
        // SepayRepository/PosterRepository).
        $rows = $this->db->query(
            "SELECT l.sepay_id, l.poster_transaction_id, l.link_type,
                    CASE WHEN l.link_type = 'manual' THEN 1 ELSE 0 END AS is_manual
             FROM {$pl} l
             JOIN {$pc} p ON p.transaction_id = l.poster_transaction_id
             JOIN {$st} s ON s.sepay_id = l.sepay_id
             WHERE p.day_date BETWEEN ? AND ?
               AND s.transaction_date BETWEEN ? AND ?",
            [$r->from, $r->to, $r->from . ' 00:00:00', $r->to . ' 23:59:59']
        )->fetchAll();

        return array_map(static fn(array $row) => ReconciliationLink::fromRow($row), $rows);
    }

    public function exists(int $sepayId, int $posterTransactionId): bool
    {
        $pl = $this->db->t('check_payment_links');
        $row = $this->db->query(
            "SELECT 1 FROM {$pl} WHERE sepay_id = ? AND poster_transaction_id = ? LIMIT 1",
            [$sepayId, $posterTransactionId]
        )->fetch();
        return $row !== false && $row !== null;
    }

    public function add(ReconciliationLink $link): void
    {
        if ($this->exists($link->sepayId, $link->posterTransactionId)) {
            return;
        }
        $pl = $this->db->t('check_payment_links');
        $this->db->query(
            "INSERT INTO {$pl} (sepay_id, poster_transaction_id, link_type) VALUES (?, ?, ?)",
            [$link->sepayId, $link->posterTransactionId, $link->linkType]
        );
    }

    public function remove(int $sepayId, int $posterTransactionId): void
    {
        $pl = $this->db->t('check_payment_links');
        $this->db->query(
            "DELETE FROM {$pl} WHERE sepay_id = ? AND poster_transaction_id = ?",
            [$sepayId, $posterTransactionId]
        );
    }

    public function clearInRange(DateRange $r): int
    {
        $pl = $this->db->t('check_payment_links');
        $pc = $this->db->t('poster_checks');
        $stmt = $this->db->query(
            "DELETE l FROM {$pl} l
             JOIN {$pc} p ON p.transaction_id = l.poster_transaction_id
             WHERE p.day_date BETWEEN ? AND ?",
            [$r->from, $r->to]
        );
        return $stmt->rowCount();
    }
}

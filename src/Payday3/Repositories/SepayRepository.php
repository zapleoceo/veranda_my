<?php

declare(strict_types=1);

namespace App\Payday3\Repositories;

use App\Infrastructure\Database;
use App\Payday3\Contracts\SepayRepositoryInterface;
use App\Payday3\Domain\DateRange;
use App\Payday3\Domain\SepayTransaction;

/**
 * Reads inbound bank transactions from sepay_transactions.
 * Only the two columns of business meaning to PayDay (open vs hidden)
 * are exposed; the SQL is centralised here so it stops leaking into
 * view.php and ajax.php as it did in payday2.
 */
final class SepayRepository implements SepayRepositoryInterface
{
    public function __construct(private readonly Database $db) {}

    /** @return SepayTransaction[] */
    public function listOpenInRange(DateRange $r): array
    {
        $st = $this->db->t('sepay_transactions');
        $sh = $this->db->t('sepay_hidden');
        $rows = $this->db->query(
            "SELECT s.sepay_id, s.transaction_date, s.transfer_amount, s.payment_method, s.content, s.reference_code
             FROM {$st} s
             WHERE s.transaction_date BETWEEN ? AND ?
               AND s.transfer_type = 'in'
               AND (s.payment_method IS NULL OR s.payment_method IN ('Card','Bybit'))
               AND COALESCE(s.was_deleted, 0) = 0
               AND NOT EXISTS (SELECT 1 FROM {$sh} h WHERE h.sepay_id = s.sepay_id)
             ORDER BY s.transaction_date ASC",
            [$r->from . ' 00:00:00', $r->to . ' 23:59:59']
        )->fetchAll();

        return array_map(static fn(array $row) => SepayTransaction::fromRow($row), $rows);
    }

    public function hide(int $sepayId, string $comment = ''): void
    {
        if ($sepayId <= 0) return;
        $sh = $this->db->t('sepay_hidden');
        // INSERT IGNORE so a second click on an already-hidden row is a
        // silent no-op (matches the eye-toggle/unhide round-trip the
        // operator may trigger from the OUT-mode UI pattern).
        $this->db->query(
            "INSERT IGNORE INTO {$sh} (sepay_id, comment) VALUES (?, ?)",
            [$sepayId, $comment]
        );
    }

    public function unhide(int $sepayId): void
    {
        if ($sepayId <= 0) return;
        $sh = $this->db->t('sepay_hidden');
        $this->db->query(
            "DELETE FROM {$sh} WHERE sepay_id = ?",
            [$sepayId]
        );
    }

    public function isHidden(int $sepayId): bool
    {
        if ($sepayId <= 0) return false;
        $sh = $this->db->t('sepay_hidden');
        $row = $this->db->query(
            "SELECT 1 FROM {$sh} WHERE sepay_id = ? LIMIT 1",
            [$sepayId]
        )->fetch();
        return $row !== false && $row !== null;
    }

    /** @return SepayTransaction[] */
    public function listHiddenInRange(DateRange $r): array
    {
        $st = $this->db->t('sepay_transactions');
        $sh = $this->db->t('sepay_hidden');
        $rows = $this->db->query(
            "SELECT s.sepay_id, s.transaction_date, s.transfer_amount, s.payment_method, s.content, s.reference_code,
                    h.comment AS hidden_comment
             FROM {$st} s
             JOIN {$sh} h ON h.sepay_id = s.sepay_id
             WHERE s.transaction_date BETWEEN ? AND ?
               AND s.transfer_type = 'in'
               AND (s.payment_method IS NULL OR s.payment_method IN ('Card','Bybit'))
               AND COALESCE(s.was_deleted, 0) = 0
             ORDER BY s.transaction_date ASC",
            [$r->from . ' 00:00:00', $r->to . ' 23:59:59']
        )->fetchAll();

        return array_map(static fn(array $row) => SepayTransaction::fromRow($row), $rows);
    }
}

<?php

declare(strict_types=1);

namespace App\Payday3\Repositories;

use App\Infrastructure\Database;
use App\Payday3\Contracts\PosterRepositoryInterface;
use App\Payday3\Domain\DateRange;
use App\Payday3\Domain\PosterTransaction;

/**
 * Reads closed POS transactions from poster_checks (joined with
 * poster_payment_methods for display). Filtered to the same set the
 * payday2 reconciliation view uses: pay_type in {2,3}, card+third > 0.
 */
final class PosterRepository implements PosterRepositoryInterface
{
    public function __construct(private readonly Database $db) {}

    /** @return PosterTransaction[] */
    public function listClosedInRange(DateRange $r): array
    {
        $pc  = $this->db->t('poster_checks');
        $ppm = $this->db->t('poster_payment_methods');
        $rows = $this->db->query(
            "SELECT p.transaction_id, p.receipt_number, p.date_close, p.payed_card, p.payed_third_party, p.tip_sum,
                    pm.title AS payment_method_display,
                    p.waiter_name, p.table_id, p.spot_id, p.poster_payment_method_id
             FROM {$pc} p
             LEFT JOIN {$ppm} pm ON pm.payment_method_id = p.poster_payment_method_id
             WHERE p.day_date BETWEEN ? AND ?
               AND COALESCE(p.was_deleted, 0) = 0
               AND p.pay_type IN (2,3)
               AND (p.payed_card + p.payed_third_party) > 0
             ORDER BY date_close ASC",
            [$r->from, $r->to]
        )->fetchAll();

        return array_map(static fn(array $row) => PosterTransaction::fromRow($row), $rows);
    }
}

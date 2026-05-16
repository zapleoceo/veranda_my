<?php

declare(strict_types=1);

namespace App\Payday3\Services;

use App\Infrastructure\Database;
use App\Payday3\Contracts\DayResetServiceInterface;
use App\Payday3\Domain\DateRange;

/**
 * Same soft-reset semantics as payday2/post/clear_day.php:
 *   poster_checks.was_deleted        = 1   in [dateFrom..dateTo]
 *   sepay_transactions.was_deleted   = 1   in [dateFrom 00:00 .. dateTo 23:59]
 *
 * Both queries run inside a transaction so the table pair never ends
 * up half-reset. Callers (cron, manual sync) restore the rows on the
 * next pass.
 */
final class DayResetService implements DayResetServiceInterface
{
    public function __construct(private readonly Database $db) {}

    public function softReset(DateRange $range): array
    {
        $pc = $this->db->t('poster_checks');
        $st = $this->db->t('sepay_transactions');

        $pdo = $this->db->getPdo();
        $pdo->beginTransaction();
        try {
            $sp = $this->db->query(
                "UPDATE {$pc} SET was_deleted = 1, deleted_at = NOW() WHERE day_date BETWEEN ? AND ?",
                [$range->from, $range->to]
            );
            $ss = $this->db->query(
                "UPDATE {$st} SET was_deleted = 1, deleted_at = NOW() WHERE transaction_date BETWEEN ? AND ?",
                [$range->from . ' 00:00:00', $range->to . ' 23:59:59']
            );
            $pdo->commit();
            return [
                'sepay'  => $ss->rowCount(),
                'poster' => $sp->rowCount(),
            ];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}

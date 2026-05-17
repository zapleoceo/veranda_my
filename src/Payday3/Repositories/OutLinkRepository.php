<?php

declare(strict_types=1);

namespace App\Payday3\Repositories;

use App\Infrastructure\Database;
use App\Payday3\Contracts\OutLinkRepositoryInterface;
use App\Payday3\Domain\DateRange;
use App\Payday3\Domain\OutLink;

/**
 * Persistence for OUT-direction reconciliation edges (mail ↔ Poster
 * finance tx). The 'out_links' table is created by payday2's bootstrap
 * (see payday2/functions.php) — we read/write the same schema.
 *
 * Range filtering is by date_to (the upper end of the visible window
 * when the link was created); a row is included if its date_to falls
 * inside the requested range. This matches payday2's behaviour and
 * avoids relying on per-side mutable date fields.
 */
final class OutLinkRepository implements OutLinkRepositoryInterface
{
    public function __construct(private readonly Database $db) {}

    /** @return OutLink[] */
    public function listInRange(DateRange $r): array
    {
        $ol = $this->db->t('out_links');
        $rows = $this->db->query(
            "SELECT mail_uid, finance_id, link_type,
                    CASE WHEN link_type = 'manual' THEN 1 ELSE 0 END AS is_manual
             FROM {$ol}
             WHERE date_to BETWEEN ? AND ?",
            [$r->from, $r->to]
        )->fetchAll();
        return array_map(static fn(array $row) => OutLink::fromRow($row), $rows);
    }

    public function exists(int $mailUid, int $financeId): bool
    {
        $ol = $this->db->t('out_links');
        $row = $this->db->query(
            "SELECT 1 FROM {$ol} WHERE mail_uid = ? AND finance_id = ? LIMIT 1",
            [$mailUid, $financeId]
        )->fetch();
        return $row !== false && $row !== null;
    }

    public function add(OutLink $link, ?string $dateTo = null): void
    {
        $ol = $this->db->t('out_links');
        $this->db->query(
            "INSERT IGNORE INTO {$ol} (mail_uid, finance_id, link_type, date_to)
             VALUES (?, ?, ?, ?)",
            [$link->mailUid, $link->financeId, $link->linkType, $dateTo ?? date('Y-m-d')]
        );
    }

    public function remove(int $mailUid, int $financeId): void
    {
        $ol = $this->db->t('out_links');
        $this->db->query(
            "DELETE FROM {$ol} WHERE mail_uid = ? AND finance_id = ?",
            [$mailUid, $financeId]
        );
    }

    public function clearInRange(DateRange $r): int
    {
        $ol = $this->db->t('out_links');
        return $this->db->query(
            "DELETE FROM {$ol} WHERE date_to BETWEEN ? AND ?",
            [$r->from, $r->to]
        )->rowCount();
    }
}

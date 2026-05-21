<?php

declare(strict_types=1);

namespace App\PosterApp\Repositories;

use App\Infrastructure\Database;
use App\PosterApp\Contracts\WorkShiftRepositoryInterface;
use App\PosterApp\Domain\WorkShift;
use App\PosterApp\Infrastructure\Schema;

final class WorkShiftRepository implements WorkShiftRepositoryInterface
{
    public function __construct(
        private readonly Database $db,
        private readonly Schema   $schema,
    ) {
        $this->schema->ensure();
    }

    public function findOpenForUser(int $posterUserId): ?WorkShift
    {
        if ($posterUserId <= 0) return null;
        $t = $this->db->t('neworder_work_shift');
        $row = $this->db->query(
            "SELECT id, poster_user_id, poster_shift_id, started_at, ended_at, source
             FROM {$t}
             WHERE poster_user_id = ? AND ended_at IS NULL
             ORDER BY started_at DESC LIMIT 1",
            [$posterUserId],
        )->fetch();
        return $row ? WorkShift::fromRow($row) : null;
    }

    public function open(int $posterUserId, ?int $posterShiftId, string $source): int
    {
        if ($posterUserId <= 0) return 0;
        $t = $this->db->t('neworder_work_shift');
        $this->db->query(
            "INSERT INTO {$t} (poster_user_id, poster_shift_id, started_at, source)
             VALUES (?, ?, NOW(), ?)",
            [$posterUserId, $posterShiftId, $source],
        );
        return (int)$this->db->pdo()->lastInsertId();
    }

    public function closeById(int $shiftId): void
    {
        if ($shiftId <= 0) return;
        $t = $this->db->t('neworder_work_shift');
        $this->db->query(
            "UPDATE {$t} SET ended_at = NOW() WHERE id = ? AND ended_at IS NULL",
            [$shiftId],
        );
    }

    public function closeOpenForUser(int $posterUserId): void
    {
        if ($posterUserId <= 0) return;
        $t = $this->db->t('neworder_work_shift');
        $this->db->query(
            "UPDATE {$t} SET ended_at = NOW()
             WHERE poster_user_id = ? AND ended_at IS NULL",
            [$posterUserId],
        );
    }

    public function closeByPosterShiftId(int $posterShiftId): void
    {
        if ($posterShiftId <= 0) return;
        $t = $this->db->t('neworder_work_shift');
        $this->db->query(
            "UPDATE {$t} SET ended_at = NOW()
             WHERE poster_shift_id = ? AND ended_at IS NULL",
            [$posterShiftId],
        );
    }

    public function listInRange(string $dateFrom, string $dateTo): array
    {
        $t = $this->db->t('neworder_work_shift');
        $rows = $this->db->query(
            "SELECT id, poster_user_id, poster_shift_id, started_at, ended_at, source
             FROM {$t}
             WHERE (started_at BETWEEN ? AND ?)
                OR (ended_at  BETWEEN ? AND ?)
             ORDER BY started_at ASC",
            [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59',
             $dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'],
        )->fetchAll();
        return array_map(static fn($r) => WorkShift::fromRow($r), $rows ?: []);
    }
}

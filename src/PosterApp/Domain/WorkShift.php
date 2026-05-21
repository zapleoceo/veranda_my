<?php

declare(strict_types=1);

namespace App\PosterApp\Domain;

/**
 * One row in `neworder_work_shift` — an employee's start/end timesheet
 * window. Independent of Poster's own cash-shift; we may or may not
 * tie this to a Poster shift_id depending on whether the operator
 * opens our shift via the POS widget (shiftOpen event) or via a
 * direct /neworder PIN entry (no Poster shift link).
 */
final class WorkShift
{
    public function __construct(
        public readonly int     $id,
        public readonly int     $posterUserId,
        public readonly ?int    $posterShiftId,   // Poster CashShift.id if known
        public readonly string  $startedAt,        // 'Y-m-d H:i:s'
        public readonly ?string $endedAt,
        public readonly string  $source,           // 'pos_widget' / 'neworder_pin'
    ) {}

    public static function fromRow(array $r): self
    {
        return new self(
            id:             (int)($r['id'] ?? 0),
            posterUserId:   (int)($r['poster_user_id'] ?? 0),
            posterShiftId:  isset($r['poster_shift_id']) && $r['poster_shift_id'] !== null
                ? (int)$r['poster_shift_id']
                : null,
            startedAt:      (string)($r['started_at'] ?? ''),
            endedAt:        isset($r['ended_at']) && $r['ended_at'] !== null
                ? (string)$r['ended_at']
                : null,
            source:         (string)($r['source'] ?? 'pos_widget'),
        );
    }

    public function isOpen(): bool
    {
        return $this->endedAt === null;
    }

    public function toJson(): array
    {
        return [
            'id'              => $this->id,
            'poster_user_id'  => $this->posterUserId,
            'poster_shift_id' => $this->posterShiftId,
            'started_at'      => $this->startedAt,
            'ended_at'        => $this->endedAt,
            'source'          => $this->source,
        ];
    }
}

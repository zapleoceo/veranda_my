<?php

declare(strict_types=1);

namespace App\Payday3\Contracts;

use App\Payday3\Domain\DateRange;
use App\Payday3\Domain\OutLink;

interface OutLinkRepositoryInterface
{
    /** @return OutLink[] */
    public function listInRange(DateRange $range): array;

    public function exists(int $mailUid, int $financeId): bool;
    /**
     * Idempotent insert (INSERT IGNORE on the unique pair). $dateTo is the
     * day the edge belongs to (upper end of the visible range) — required,
     * so a back-dated manual link can't silently land on "today".
     */
    public function add(OutLink $link, string $dateTo): void;
    public function remove(int $mailUid, int $financeId): void;
    public function clearInRange(DateRange $range): int;
}

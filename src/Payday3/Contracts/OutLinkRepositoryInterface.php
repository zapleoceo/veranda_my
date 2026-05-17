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
    public function add(OutLink $link): void;
    public function remove(int $mailUid, int $financeId): void;
    public function clearInRange(DateRange $range): int;
}

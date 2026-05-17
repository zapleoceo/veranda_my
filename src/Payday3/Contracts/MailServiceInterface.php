<?php

declare(strict_types=1);

namespace App\Payday3\Contracts;

use App\Payday3\Domain\DateRange;

interface MailServiceInterface
{
    /**
     * Fetch BIDV outgoing-bank-mail rows from the configured IMAP
     * inbox for the date window. Mail rows are NOT persisted — every
     * call hits IMAP fresh.
     *
     * @return \App\Payday3\Domain\MailTransaction[]
     */
    public function fetch(DateRange $range, bool $includeHidden = false): array;

    /** Hide a mail message permanently (writes to mail_hidden). */
    public function hide(int $mailUid, string $dateTo, string $comment = ''): void;
}

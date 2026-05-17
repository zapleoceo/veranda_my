<?php

declare(strict_types=1);

namespace App\Payday3\Domain;

/**
 * One outbound bank transaction extracted from a BIDV mail. Lives
 * only in memory — the IMAP fetch is the source of truth, never
 * persisted as a row. mail_uid is the IMAP unique-id of the message.
 */
final class MailTransaction
{
    public function __construct(
        public readonly int    $mailUid,
        public readonly string $date,             // 'Y-m-d H:i:s'
        public readonly Money  $amount,
        public readonly string $content,          // mail subject
        public readonly string $txTime,           // raw time string from body
        public readonly bool   $isHidden = false,
        public readonly string $hiddenComment = '',
    ) {}

    public function toJsonShape(): array
    {
        return [
            'mail_uid'       => $this->mailUid,
            'date'           => $this->date,
            'amount'         => $this->amount->amount,
            'amount_fmt'     => $this->amount->format(),
            'content'        => $this->content,
            'tx_time'        => $this->txTime,
            'is_hidden'      => $this->isHidden ? 1 : 0,
            'hidden_comment' => $this->hiddenComment,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Payday3\Domain;

/**
 * Edge in the OUT-direction reconciliation graph:
 * mail_uid (BIDV outgoing email) ↔ finance_id (Poster finance tx).
 * Backed by the out_links table.
 */
final class OutLink
{
    public function __construct(
        public readonly int    $mailUid,
        public readonly int    $financeId,
        public readonly string $linkType, // 'auto_green' | 'auto_yellow' | 'manual'
        public readonly bool   $isManual,
    ) {}

    public static function fromRow(array $r): self
    {
        $type = (string)($r['link_type'] ?? 'manual');
        return new self(
            mailUid:   (int)($r['mail_uid']   ?? 0),
            financeId: (int)($r['finance_id'] ?? 0),
            linkType:  $type,
            isManual:  $type === 'manual' || (int)($r['is_manual'] ?? 0) === 1,
        );
    }

    public function toJsonShape(): array
    {
        return [
            'mail_uid'   => $this->mailUid,
            'finance_id' => $this->financeId,
            'link_type'  => $this->linkType,
            'is_manual'  => $this->isManual ? 1 : 0,
        ];
    }
}

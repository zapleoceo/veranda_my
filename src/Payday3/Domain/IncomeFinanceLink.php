<?php

declare(strict_types=1);

namespace App\Payday3\Domain;

/**
 * Edge: incoming bank row (SePay) ↔ Poster finance INCOME transaction —
 * money that reached the bank without a sales check (compensation,
 * a deposit, an income created with the «+» button…).
 * Backed by the sepay_finance_links table.
 */
final class IncomeFinanceLink
{
    public function __construct(
        public readonly int    $sepayId,
        public readonly int    $financeId,
        public readonly string $linkType, // 'auto_green' | 'auto_yellow' | 'manual'
        public readonly bool   $isManual,
    ) {}

    public static function fromRow(array $r): self
    {
        $type = (string)($r['link_type'] ?? 'manual');
        return new self(
            sepayId:   (int)($r['sepay_id']   ?? 0),
            financeId: (int)($r['finance_id'] ?? 0),
            linkType:  $type,
            isManual:  $type === 'manual',
        );
    }

    public function toJsonShape(): array
    {
        return [
            'sepay_id'   => $this->sepayId,
            'finance_id' => $this->financeId,
            'link_type'  => $this->linkType,
            'is_manual'  => $this->isManual ? 1 : 0,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Payday3\Domain;

/**
 * One inbound bank transaction (table sepay_transactions).
 * Pure data — no DB, no formatting helpers beyond Money/DateRange.
 */
final class SepayTransaction
{
    public function __construct(
        public readonly int    $id,
        public readonly string $transactionDate, // ISO-ish 'Y-m-d H:i:s'
        public readonly Money  $amount,
        public readonly string $paymentMethod,   // 'Card' | 'Bybit' | ...
        public readonly string $content,
        public readonly string $referenceCode,
        public readonly ?string $hiddenComment = null,
    ) {}

    public function isHidden(): bool { return $this->hiddenComment !== null; }

    public static function fromRow(array $r): self
    {
        return new self(
            id:              (int)($r['sepay_id'] ?? 0),
            transactionDate: (string)($r['transaction_date'] ?? ''),
            amount:          Money::parse($r['transfer_amount'] ?? 0),
            paymentMethod:   (string)($r['payment_method'] ?? ''),
            content:         (string)($r['content'] ?? ''),
            referenceCode:   (string)($r['reference_code'] ?? ''),
            hiddenComment:   array_key_exists('hidden_comment', $r) ? (string)$r['hidden_comment'] : null,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Payday3\Domain;

/**
 * One closed Poster (POS) transaction — the "right-hand" side of
 * the reconciliation graph. Mirrors poster_closed_transactions.
 */
final class PosterTransaction
{
    public function __construct(
        public readonly int     $transactionId,
        public readonly string  $receiptNumber,
        public readonly string  $dateClose,                 // 'Y-m-d H:i:s'
        public readonly Money   $payedCard,
        public readonly Money   $payedThirdParty,
        public readonly Money   $tipSum,
        public readonly ?string $paymentMethodDisplay,
        public readonly string  $waiterName,
        public readonly int     $tableId,
        public readonly int     $spotId,
        public readonly int     $posterPaymentMethodId,
    ) {}

    public function totalPayed(): Money
    {
        return $this->payedCard->plus($this->payedThirdParty);
    }

    public static function fromRow(array $r): self
    {
        return new self(
            transactionId:         (int)($r['transaction_id'] ?? 0),
            receiptNumber:         (string)($r['receipt_number'] ?? ''),
            dateClose:             (string)($r['date_close'] ?? ''),
            payedCard:             Money::parse($r['payed_card'] ?? 0),
            payedThirdParty:       Money::parse($r['payed_third_party'] ?? 0),
            tipSum:                Money::parse($r['tip_sum'] ?? 0),
            paymentMethodDisplay:  array_key_exists('payment_method_display', $r) && $r['payment_method_display'] !== null
                                       ? (string)$r['payment_method_display'] : null,
            waiterName:            (string)($r['waiter_name'] ?? ''),
            tableId:               (int)($r['table_id'] ?? 0),
            spotId:                (int)($r['spot_id'] ?? 0),
            posterPaymentMethodId: (int)($r['poster_payment_method_id'] ?? 0),
        );
    }
}

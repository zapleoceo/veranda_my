<?php

declare(strict_types=1);

namespace App\Order\Domain;

/**
 * One open check on a specific table — the operator can choose to add
 * the cart's items to it (vs. opening a brand-new transaction).
 */
final class OpenCheck
{
    /** @param string[] $itemSummary one-line title per item, e.g. "Beer × 2" */
    public function __construct(
        public readonly int    $transactionId,
        public readonly int    $sumVnd,
        public readonly string $openedAt,
        public readonly array  $itemSummary,
    ) {}

    public function toJson(): array
    {
        return [
            'transaction_id' => $this->transactionId,
            'sum'            => $this->sumVnd,
            'opened_at'      => $this->openedAt,
            'items'          => $this->itemSummary,
        ];
    }
}

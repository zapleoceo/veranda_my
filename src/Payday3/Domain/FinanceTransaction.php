<?php

declare(strict_types=1);

namespace App\Payday3\Domain;

/**
 * One Poster finance transaction (not a sales check — a
 * money-account transaction, i.e. withdrawal/deposit/transfer).
 * Fetched live from finance.getTransactions; not persisted in
 * payday3's own tables.
 */
final class FinanceTransaction
{
    public function __construct(
        public readonly int    $transactionId,
        public readonly int    $userId,
        public readonly int    $categoryId,
        public readonly int    $type,
        public readonly Money  $amount,
        public readonly Money  $balance,
        public readonly string $date,             // 'Y-m-d H:i:s' or empty
        public readonly string $comment,
    ) {}

    public function toJsonShape(): array
    {
        return [
            'transaction_id' => $this->transactionId,
            'user_id'        => $this->userId,
            'category_id'    => $this->categoryId,
            'type'           => $this->type,
            'amount'         => $this->amount->amount,
            'amount_fmt'     => $this->amount->format(),
            'balance'        => $this->balance->amount,
            'date'           => $this->date,
            'comment'        => $this->comment,
        ];
    }
}

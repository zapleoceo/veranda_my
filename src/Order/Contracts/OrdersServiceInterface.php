<?php

declare(strict_types=1);

namespace App\Order\Contracts;

use App\Order\Domain\CartLine;

interface OrdersServiceInterface
{
    /**
     * Create a fresh incoming order via Poster's POST /api/orders.
     *
     * @param CartLine[] $lines
     * @return array{order_id:int}
     * @throws \RuntimeException on transport / Poster error
     * @throws \InvalidArgumentException on validation
     */
    public function createOrder(int $spotId, int $tableId, string $comment, array $lines): array;

    /**
     * Append cart lines to an existing transaction via
     * transactions.addTransactionProduct (one call per line).
     * Also updates the transaction-level comment if provided.
     *
     * @param CartLine[] $lines
     * @return array{added:int}
     */
    public function appendToTransaction(int $spotId, int $tabletId, int $transactionId, string $comment, array $lines): array;
}

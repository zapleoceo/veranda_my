<?php

declare(strict_types=1);

namespace App\Payday3\Services;

use App\Payday3\Contracts\PosterApiProviderInterface;
use App\Payday3\Contracts\PosterTransactionCreateServiceInterface;

/**
 * Calls Poster's finance.createTransactions for the "+" popup that
 * the operator opens from an OUT-mail row.
 *
 * Direct port of payday2's `?ajax=create_poster_transaction` — same
 * UI semantics (1 = income / 2 = expense / 3 = transfer) translated
 * to the Poster wire format (1=income, 0=expense, 2=transfer).
 *
 * Amount is sent as a plain integer (VND, no cents). Poster
 * accepts that.
 */
final class PosterTransactionCreateService implements PosterTransactionCreateServiceInterface
{
    public function __construct(private readonly PosterApiProviderInterface $poster) {}

    public function create(array $input): array
    {
        $type        = (int)($input['type']         ?? 0);
        $amount      = (int)($input['amount']       ?? 0);
        $date        = trim((string)($input['date'] ?? ''));
        $comment     = trim((string)($input['comment']     ?? ''));
        $categoryId  = (int)($input['category_id']  ?? 0);
        $accountFrom = (int)($input['account_from'] ?? 0);
        $accountTo   = (int)($input['account_to']   ?? 0);

        if ($type < 1 || $type > 3)                    throw new \InvalidArgumentException('Invalid type');
        if ($amount <= 0)                              throw new \InvalidArgumentException('Invalid amount');
        if ($date === '')                              throw new \InvalidArgumentException('Invalid date');

        $payload = [
            'type'    => $type === 1 ? 1 : ($type === 2 ? 0 : 2), // UI → Poster wire
            'date'    => $date,
            'comment' => $comment,
        ];
        if ($categoryId > 0) {
            $payload['category'] = $categoryId;
        }

        if ($type === 1) {                              // income
            if ($accountTo <= 0) throw new \InvalidArgumentException('Не выбран Account To');
            $payload['account_to'] = $accountTo;
            $payload['amount_to']  = $amount;
        } elseif ($type === 2) {                        // expense
            if ($accountFrom <= 0) throw new \InvalidArgumentException('Не выбран Account From');
            $payload['account_from'] = $accountFrom;
            $payload['amount_from']  = $amount;
        } else {                                        // transfer (3)
            if ($accountFrom <= 0 || $accountTo <= 0) throw new \InvalidArgumentException('Не выбраны оба счёта');
            if ($accountFrom === $accountTo)          throw new \InvalidArgumentException('Счета From и To должны различаться');
            $payload['account_from'] = $accountFrom;
            $payload['amount_from']  = $amount;
            $payload['account_to']   = $accountTo;
            $payload['amount_to']    = $amount;
        }

        try {
            $resp = $this->poster->client()->request('finance.createTransactions', $payload, 'POST');
        } catch (\Throwable $e) {
            throw new \RuntimeException('Poster: ' . $e->getMessage(), 0, $e);
        }
        return ['ok' => true, 'response' => $resp];
    }
}

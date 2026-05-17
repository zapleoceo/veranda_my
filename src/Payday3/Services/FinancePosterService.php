<?php

declare(strict_types=1);

namespace App\Payday3\Services;

use App\Classes\PosterAPI;
use App\Payday2\LocalSettings;
use App\Payday3\Contracts\FinanceServiceInterface;
use App\Payday3\Domain\DateRange;
use App\Payday3\Domain\FinanceTransaction;
use App\Payday3\Domain\Money;

/**
 * Fetches Poster finance.getTransactions for the Andrey and Tips
 * accounts (configured via payday2's LocalSettings JSON). Dedupes
 * by transaction_id across the two account-type calls.
 *
 * Port of payday2/ajax.php `finance_out` action.
 */
final class FinancePosterService implements FinanceServiceInterface
{
    /** @return FinanceTransaction[] */
    public function fetch(DateRange $range): array
    {
        $token = trim((string)($_ENV['POSTER_API_TOKEN'] ?? ''));
        if ($token === '') {
            throw new \RuntimeException('POSTER_API_TOKEN is not configured');
        }
        $api = new PosterAPI($token);

        $rows = [];
        $accounts = [
            LocalSettings::accountAndreyId(),
            LocalSettings::accountTipsId(),
        ];
        foreach ($accounts as $accType) {
            try {
                $batch = $api->request('finance.getTransactions', [
                    'dateFrom'     => date('Ymd', strtotime($range->from)),
                    'dateTo'       => date('Ymd', strtotime($range->to)),
                    'account_type' => $accType,
                    'timezone'     => 'client',
                ]);
                if (is_array($batch)) $rows = array_merge($rows, $batch);
            } catch (\Throwable $e) {
                // Skip this account; other one might still return data.
            }
        }

        $out = [];
        $seen = [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $txId = (int)($r['transaction_id'] ?? 0);
            if ($txId > 0) {
                if (isset($seen[$txId])) continue;
                $seen[$txId] = true;
            }
            $out[] = new FinanceTransaction(
                transactionId: $txId,
                userId:        (int)($r['user_id']     ?? 0),
                categoryId:    (int)($r['category_id'] ?? 0),
                type:          (int)($r['type']        ?? 0),
                amount:        Money::vnd((int)($r['amount']  ?? 0)),
                balance:       Money::vnd((int)($r['balance'] ?? 0)),
                date:          (string)($r['date']     ?? ''),
                comment:       (string)($r['comment']  ?? ''),
            );
        }
        return $out;
    }
}

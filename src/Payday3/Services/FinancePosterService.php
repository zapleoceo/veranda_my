<?php

declare(strict_types=1);

namespace App\Payday3\Services;

use App\Infrastructure\Logger;
use App\Payday3\Contracts\FinanceServiceInterface;
use App\Payday3\Contracts\LocalSettingsRepositoryInterface;
use App\Payday3\Contracts\PosterApiProviderInterface;
use App\Payday3\Domain\DateRange;
use App\Payday3\Domain\FinanceTransaction;
use App\Payday3\Domain\Money;

/**
 * Fetches Poster finance.getTransactions for the Andrey and Tips
 * accounts, deduped by transaction_id. Both account IDs come from
 * the injected LocalSettings repository — no payday2 import.
 */
final class FinancePosterService implements FinanceServiceInterface
{
    public function __construct(
        private readonly PosterApiProviderInterface       $poster,
        private readonly LocalSettingsRepositoryInterface $settings,
    ) {}

    /** @return FinanceTransaction[] */
    public function fetch(DateRange $range): array
    {
        $api = $this->poster->client();
        $cfg = $this->settings->load();

        $rows = [];
        // account_id, а НЕ account_type: в настройках лежат идентификаторы
        // счетов (1 = «Счет Андрея», 8 = «Tips»), а account_type принимает
        // только 1|2|3 (банк / карта / наличные).
        //
        // Что было: id подставлялся в account_type. Для счёта Андрея (1) это
        // случайно «работало» — тип 1 возвращает ВСЕ банковские счета разом,
        // включая Tips, поэтому чаевые попадали в выборку побочным эффектом.
        // Второй вызов с account_type=8 всегда падал с ошибкой 216 и молча
        // глотался catch'ем. Данные сходились только по совпадению: смени
        // счёт Андрея на счёт другого типа — и чаевые исчезли бы без следа.
        //
        // Проверено на проде (июль): account_type=1 → 312 строк (счета 1 и 8),
        // account_id=1 → 266, account_id=8 → 46. 266 + 46 = 312, то есть
        // выборка не меняется, но перестаёт зависеть от типа счёта и не
        // затягивает чужие счета того же типа.
        foreach ([$cfg->accountAndreyId, $cfg->accountTipsId] as $accountId) {
            if ((int) $accountId <= 0) continue;
            try {
                $batch = $api->request('finance.getTransactions', [
                    'dateFrom'   => date('Ymd', strtotime($range->from)),
                    'dateTo'     => date('Ymd', strtotime($range->to)),
                    'account_id' => (int) $accountId,
                    'timezone'   => 'client',
                ]);
                if (is_array($batch)) $rows = array_merge($rows, $batch);
            } catch (\Throwable $e) {
                // Один счёт мог не ответить — второй всё ещё может дать данные.
                Logger::get()->warning('payday3.finance_fetch_failed', [
                    'account_id' => (int) $accountId,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        $out  = [];
        $seen = [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $txId = (int)($r['transaction_id'] ?? 0);
            if ($txId > 0) {
                if (isset($seen[$txId])) continue;
                $seen[$txId] = true;
            }
            // Poster `finance.getTransactions` returns amount/balance
            // in cents (1 cent = 0.01 VND). Without the divide the
            // OUT-mode finance table rendered every figure 100× too
            // big.
            $out[] = new FinanceTransaction(
                transactionId: $txId,
                userId:        (int)($r['user_id']     ?? 0),
                categoryId:    (int)($r['category_id'] ?? 0),
                type:          (int)($r['type']        ?? 0),
                amount:        Money::vnd(Money::posterMinorToVnd($r['amount']  ?? 0)),
                balance:       Money::vnd(Money::posterMinorToVnd($r['balance'] ?? 0)),
                date:          (string)($r['date']     ?? ''),
                comment:       (string)($r['comment']  ?? ''),
            );
        }
        return $out;
    }
}

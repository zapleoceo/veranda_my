<?php

declare(strict_types=1);

namespace App\Payday3\Services;

use App\Payday3\Contracts\LocalSettingsRepositoryInterface;
use App\Payday3\Contracts\PosterApiProviderInterface;
use App\Payday3\Contracts\PosterBalanceServiceInterface;
use App\Payday3\Domain\Money;

/**
 * Snapshot of the three configured Poster accounts' current balances,
 * plus the computed total. Maps account_id → balance once and looks
 * the three operator-configured IDs out of that map; missing IDs
 * yield null so the UI renders '—'.
 *
 * Poster's finance.getAccounts returns balance in cents (1 cent =
 * 0.01 VND) — same convention as poster_checks. Without dividing by
 * 100 the UI rendered every figure 100× too big.
 */
final class PosterBalanceService implements PosterBalanceServiceInterface
{
    public function __construct(
        private readonly PosterApiProviderInterface       $poster,
        private readonly LocalSettingsRepositoryInterface $settings,
    ) {}

    /** payday2 hard-codes "Касса" as Poster account_id = 2. */
    private const CASH_ACCOUNT_ID = 2;

    public function snapshot(): array
    {
        $rows = $this->poster->client()->request('finance.getAccounts', []);
        $byId     = [];
        $accounts = [];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                if (!is_array($r)) continue;
                $id = (int)($r['account_id'] ?? 0);
                if ($id <= 0) continue;
                $balanceVnd  = Money::posterMinorToVnd($r['balance'] ?? 0);
                $byId[$id]   = $balanceVnd;
                $accounts[]  = [
                    'account_id' => $id,
                    'name'       => trim((string)($r['name'] ?? '')),
                    'balance'    => $balanceVnd,
                ];
            }
        }
        $cfg = $this->settings->load();

        // Mirrors payday2/view.php exactly:
        //   Андрей  = accountAndreyId + accountTipsId combined
        //   Вьет.   = accountVietnamId
        //   Касса   = account_id 2 (hard-coded in payday2)
        //   Total   = SUM of every account Poster returned
        $andreyParts = [];
        if (isset($byId[$cfg->accountAndreyId])) $andreyParts[] = $byId[$cfg->accountAndreyId];
        if (isset($byId[$cfg->accountTipsId]))   $andreyParts[] = $byId[$cfg->accountTipsId];
        $a = $andreyParts === [] ? null : array_sum($andreyParts);

        $v = $byId[$cfg->accountVietnamId]  ?? null;
        $c = $byId[self::CASH_ACCOUNT_ID]   ?? null;

        // Total: sum of EVERY account, not just the three above — matches
        // payday2's "Total" row (`$sum += $r['balance']` across all rows).
        $total = $byId === [] ? null : array_sum($byId);

        // Stable ID-ascending order so the list table doesn't jitter.
        usort($accounts, static fn($x, $y) => $x['account_id'] <=> $y['account_id']);

        return [
            'andrey'   => $a,
            'vietnam'  => $v,
            'cash'     => $c,
            'total'    => $total,
            'accounts' => $accounts,
        ];
    }
}

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

    public function snapshot(): array
    {
        $rows = $this->poster->client()->request('finance.getAccounts', []);
        $byId = [];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                if (!is_array($r)) continue;
                $id = (int)($r['account_id'] ?? 0);
                if ($id > 0) $byId[$id] = Money::posterMinorToVnd($r['balance'] ?? 0);
            }
        }
        $cfg = $this->settings->load();
        $a = $byId[$cfg->accountAndreyId]  ?? null;
        $v = $byId[$cfg->accountVietnamId] ?? null;
        $c = $byId[$cfg->accountTipsId]    ?? null;
        $total = null;
        if ($a !== null || $v !== null || $c !== null) {
            $total = (int)($a ?? 0) + (int)($v ?? 0) + (int)($c ?? 0);
        }
        return ['andrey' => $a, 'vietnam' => $v, 'cash' => $c, 'total' => $total];
    }
}

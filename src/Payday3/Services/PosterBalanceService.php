<?php

declare(strict_types=1);

namespace App\Payday3\Services;

use App\Payday3\Contracts\LocalSettingsRepositoryInterface;
use App\Payday3\Contracts\PosterApiProviderInterface;
use App\Payday3\Contracts\PosterBalanceServiceInterface;

/**
 * Snapshot of the three configured Poster accounts' current balances,
 * plus the computed total. Maps account_id → balance once and looks
 * the three operator-configured IDs out of that map; missing IDs
 * yield null so the UI renders '—'.
 *
 * Poster's finance.getAccounts returns balance as VND directly (not
 * cents), confirmed against live data. No 100× conversion needed.
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
                if ($id > 0) $byId[$id] = (int)($r['balance'] ?? 0);
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

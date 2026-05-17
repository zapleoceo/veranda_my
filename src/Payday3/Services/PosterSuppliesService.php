<?php

declare(strict_types=1);

namespace App\Payday3\Services;

use App\Classes\PosterSupplyManager;
use App\Payday3\Contracts\PosterApiProviderInterface;
use App\Payday3\Contracts\PosterSuppliesServiceInterface;
use App\Payday3\Domain\DateRange;

/**
 * Supplies (storage.getSupplies) + finance.getAccounts, plus the
 * "change account" mutation that goes through PosterSupplyManager
 * (legacy class kept; only the Service wrapping changes).
 */
final class PosterSuppliesService implements PosterSuppliesServiceInterface
{
    public function __construct(private readonly PosterApiProviderInterface $poster) {}

    public function listWithAccounts(DateRange $range): array
    {
        $api = $this->poster->client();
        $supplies = $api->request('storage.getSupplies', [
            'dateFrom' => str_replace('-', '', $range->from),
            'dateTo'   => str_replace('-', '', $range->to),
        ]);
        $accounts = $api->request('finance.getAccounts', []);
        return [
            'supplies' => is_array($supplies) ? $supplies : [],
            'accounts' => is_array($accounts) ? $accounts : [],
        ];
    }

    public function changeAccount(int $supplyId, int $newAccountId): array
    {
        if ($supplyId    <= 0) throw new \InvalidArgumentException('Invalid supply_id');
        if ($newAccountId <= 0) throw new \InvalidArgumentException('Invalid account_id');
        $manager = new PosterSupplyManager($this->poster->client());
        $resp = $manager->changeSupplyAccount($supplyId, $newAccountId);
        return is_array($resp) ? $resp : ['ok' => true];
    }
}

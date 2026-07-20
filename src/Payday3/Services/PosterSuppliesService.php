<?php

declare(strict_types=1);

namespace App\Payday3\Services;

use App\Classes\PosterSupplyManager;
use App\Payday3\Contracts\PosterApiProviderInterface;
use App\Payday3\Contracts\PosterSuppliesServiceInterface;
use App\Payday3\Domain\DateRange;
use App\Payday3\Domain\Money;

/**
 * Supplies (storage.getSupplies) + finance.getAccounts, plus the
 * "change account" mutation that goes through PosterSupplyManager
 * (legacy class kept; only the Service wrapping changes).
 *
 * Both endpoints return monetary fields in Poster cents — we convert
 * supply_sum / total_sum on supplies and balance on accounts to VND
 * here, mirroring what payday2's JS used to do in the browser.
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

        // storage.getSupplies has no filter parameter — it returns
        // deleted supplies alongside active ones (marked with
        // `"delete": "1"`, see https://dev.joinposter.com/docs/v3/web/storage/getSupplies).
        // Drop them here so the operator never sees a supply that was
        // deleted on the Poster side.
        $suppliesOut = [];
        if (is_array($supplies)) {
            foreach ($supplies as $row) {
                if (!is_array($row)) continue;
                if ((int)($row['delete'] ?? 0) === 1) continue;
                $suppliesOut[] = self::normaliseSupply($row);
            }
        }

        return [
            'supplies' => $suppliesOut,
            'accounts' => is_array($accounts) ? array_map([self::class, 'normaliseAccount'], $accounts) : [],
        ];
    }

    private static function normaliseSupply(mixed $row): mixed
    {
        if (!is_array($row)) return $row;
        foreach (['supply_sum', 'supply_sum_netto', 'total_sum', 'sum'] as $k) {
            if (array_key_exists($k, $row) && !is_array($row[$k])) {
                $row[$k] = Money::posterMinorToVnd($row[$k]);
            }
        }
        // `payed_sum` for supplies is a nested array of per-account
        // payments — drill in and convert each `sum` field.
        if (is_array($row['payed_sum'] ?? null)) {
            $row['payed_sum'] = array_map(static function ($p) {
                if (is_array($p) && array_key_exists('sum', $p)) {
                    $p['sum'] = Money::posterMinorToVnd($p['sum']);
                }
                return $p;
            }, $row['payed_sum']);
        }
        return $row;
    }

    private static function normaliseAccount(mixed $row): mixed
    {
        if (!is_array($row)) return $row;
        foreach (['balance', 'balance_start'] as $k) {
            if (array_key_exists($k, $row) && !is_array($row[$k])) {
                $row[$k] = Money::posterMinorToVnd($row[$k]);
            }
        }
        return $row;
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

<?php

declare(strict_types=1);

namespace App\Order\Services;

use App\Order\Contracts\OpenChecksProviderInterface;
use App\Order\Domain\OpenCheck;
use App\Payday3\Contracts\PosterApiProviderInterface;

/**
 * Reads open checks (status=1) on a specific table.
 *
 * Two-step:
 *   1. dash.getTransactions {status=1, spot_id, service_mode=1} returns
 *      all open transactions in the spot (cheap, single call).
 *   2. For each transaction matching table_id, dash.getTransactionProducts
 *      returns the per-line summary used in the UI banner / modal.
 */
final class OpenChecksProvider implements OpenChecksProviderInterface
{
    public function __construct(private readonly PosterApiProviderInterface $poster) {}

    /** @return OpenCheck[] */
    public function fetchForTable(int $spotId, int $tableId): array
    {
        if ($spotId <= 0 || $tableId <= 0) return [];

        $api = $this->poster->client();
        $txs = $api->request('dash.getTransactions', [
            'status'           => 1,
            'spot_id'          => $spotId,
            'service_mode'     => 1,
            'include_products' => 'false',
            'include_history'  => 'false',
            'timezone'         => 'client',
        ], 'GET');
        if (!is_array($txs)) return [];

        $out = [];
        foreach ($txs as $tr) {
            if (!is_array($tr)) continue;
            if ((int)($tr['table_id'] ?? 0) !== $tableId) continue;
            $trId = (int)($tr['transaction_id'] ?? 0);
            if ($trId <= 0) continue;

            $products = $api->request('dash.getTransactionProducts', [
                'transaction_id' => $trId,
            ], 'GET');
            if (!is_array($products)) $products = [];

            $summary = [];
            foreach ($products as $p) {
                if (!is_array($p)) continue;
                $name = trim((string)($p['product_name'] ?? ''));
                $qty  = $this->normaliseQty($p['num'] ?? '1');
                $summary[] = $name . ($qty !== '' ? ' × ' . $qty : '');
            }

            $sumRaw = $tr['sum'] ?? ($tr['payed_sum'] ?? '0');
            $sumVnd = is_numeric($sumRaw) ? (int)round(((float)$sumRaw) / 100) : 0;

            $out[] = new OpenCheck(
                transactionId: $trId,
                sumVnd:        $sumVnd,
                openedAt:      (string)($tr['date_start'] ?? ''),
                itemSummary:   $summary,
            );
        }
        return $out;
    }

    private function normaliseQty(mixed $raw): string
    {
        if (!is_numeric($raw)) return '1';
        $f = (float)$raw;
        // Strip trailing .000 so "2" stays "2" not "2.000".
        return rtrim(rtrim(number_format($f, 3, '.', ''), '0'), '.');
    }
}

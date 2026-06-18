<?php

declare(strict_types=1);

namespace App\Bloggers\Services;

use App\Bloggers\Contracts\PosterClientsGatewayInterface;
use App\Bloggers\Support\PosterText;
use App\Payday3\Contracts\PosterApiProviderInterface;

/**
 * Poster clients.* + finance.* adapter. Owns the Poster-specific quirks
 * (verified live):
 *   - client name via single `client_name` ("Фамилия Имя"; one token → lastname);
 *     firstname/lastname params ignored. Real name → `comment`; group →
 *     `client_groups_id_client`; discount % → `discount_per`.
 *   - dash.getClientsSales: `revenue` = paid after discount, `clients` = checks.
 *   - finance.getTransactions: `amount` is minor units (cents), signed (expense
 *     negative); category is `category_id`. finance.createTransactions wants
 *     `category` + `amount_from` in VND (major units).
 */
final class PosterClientsGateway implements PosterClientsGatewayInterface
{
    /** Poster user recorded as the payer on payout transactions (as /employees). */
    private const PAYER_USER_ID = 10;

    public function __construct(private readonly PosterApiProviderInterface $poster) {}

    public function listGroupClients(int $groupId): array
    {
        $resp = $this->poster->client()->request('clients.getClients', [
            'group_id' => $groupId,
            'num'      => 1000,
        ]);
        return is_array($resp) ? array_values($resp) : [];
    }

    public function createClient(int $groupId, string $name, string $comment, string $email, float $discountPct): int
    {
        $resp = $this->poster->client()->request('clients.createClient', [
            'client_name'             => $name,
            'client_groups_id_client' => $groupId,
            'discount_per'            => $discountPct,
            'email'                   => $email,
            'comment'                 => PosterText::safe($comment),
        ], 'POST');

        return (int) (is_array($resp) ? ($resp[0] ?? 0) : $resp);
    }

    public function updateClient(int $groupId, int $clientId, string $name, string $comment, string $email, float $discountPct): void
    {
        $this->poster->client()->request('clients.updateClient', [
            'client_id'               => $clientId,
            'client_name'             => $name,
            'client_groups_id_client' => $groupId,
            'discount_per'            => $discountPct,
            'email'                   => $email,
            'comment'                 => PosterText::safe($comment),
        ], 'POST');
    }

    public function clientsSales(string $dateFrom, string $dateTo): array
    {
        $resp = $this->poster->client()->request('dash.getClientsSales', [
            'dateFrom' => str_replace('-', '', $dateFrom),
            'dateTo'   => str_replace('-', '', $dateTo),
        ]);

        $out = [];
        if (is_array($resp)) {
            foreach ($resp as $r) {
                $id = (int) ($r['client_id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $out[$id] = [
                    'checks'  => (int) ($r['clients'] ?? 0),
                    'revenue' => (int) round((float) ($r['revenue'] ?? 0)),
                ];
            }
        }
        return $out;
    }

    public function payouts(int $categoryId, string $dateFrom, string $dateTo): array
    {
        $resp = $this->poster->client()->request('finance.getTransactions', [
            'dateFrom' => str_replace('-', '', $dateFrom),
            'dateTo'   => str_replace('-', '', $dateTo),
            'type'     => 0, // expenses
        ]);

        $out = [];
        if (is_array($resp)) {
            foreach ($resp as $t) {
                if ((int) ($t['category_id'] ?? 0) !== $categoryId) {
                    continue;
                }
                if (!preg_match('/\bID=(\d+)/', (string) ($t['comment'] ?? ''), $m)) {
                    continue;
                }
                $cid = (int) $m[1];
                // amount is minor units (cents), negative for expenses.
                $out[$cid] = ($out[$cid] ?? 0) + (int) round(abs((float) ($t['amount'] ?? 0)));
            }
        }
        return $out;
    }

    public function createPayout(int $categoryId, int $accountId, int $amountVnd, string $comment): int
    {
        $resp = $this->poster->client()->request('finance.createTransactions', [
            'id'           => (int) (time() * 1000 + random_int(0, 999)),
            'type'         => 0, // expense
            'category'     => $categoryId,
            'user_id'      => self::PAYER_USER_ID,
            'amount_from'  => $amountVnd,
            'account_from' => $accountId,
            'date'         => date('Y-m-d H:i:s'),
            'comment'      => PosterText::safe($comment),
        ], 'POST');

        return (int) (is_array($resp) ? ($resp[0] ?? 0) : $resp);
    }

    public function financeAccounts(): array
    {
        $resp = $this->poster->client()->request('finance.getAccounts');
        $out  = [];
        if (is_array($resp)) {
            foreach ($resp as $a) {
                $id   = (int) ($a['account_id'] ?? 0);
                $name = trim((string) ($a['name'] ?? ''));
                if ($id > 0 && $name !== '') {
                    $out[$id] = $name;
                }
            }
        }
        return $out;
    }
}

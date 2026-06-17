<?php

declare(strict_types=1);

namespace App\Bloggers\Services;

use App\Bloggers\Contracts\PosterClientsGatewayInterface;
use App\Bloggers\Support\PosterText;
use App\Payday3\Contracts\PosterApiProviderInterface;

/**
 * Poster clients.* adapter for the bloggers group. Owns every Poster-specific
 * quirk (verified live):
 *   - name is set via the single `client_name` param ("Фамилия Имя" order; a
 *     single token lands in lastname). firstname/lastname params are ignored.
 *   - real name → `comment`; group → `client_groups_id_client`; discount % →
 *     `discount_per`.
 *   - dash.getClientsSales: `revenue` = paid after discount, `clients` = closed
 *     checks.
 */
final class PosterClientsGateway implements PosterClientsGatewayInterface
{
    /** Poster client group that holds every blogger. */
    public const GROUP_ID = 10;

    public function __construct(private readonly PosterApiProviderInterface $poster) {}

    public function listGroupClients(): array
    {
        $resp = $this->poster->client()->request('clients.getClients', [
            'group_id' => self::GROUP_ID,
            'num'      => 1000,
        ]);
        return is_array($resp) ? array_values($resp) : [];
    }

    public function createClient(string $name, string $comment, string $email, float $discountPct): int
    {
        $resp = $this->poster->client()->request('clients.createClient', [
            'client_name'             => $name,
            'client_groups_id_client' => self::GROUP_ID,
            'discount_per'            => $discountPct,
            'email'                   => $email,
            'comment'                 => PosterText::safe($comment),
        ], 'POST');

        return (int) (is_array($resp) ? ($resp[0] ?? 0) : $resp);
    }

    public function updateClient(int $clientId, string $name, string $comment, string $email, float $discountPct): void
    {
        $this->poster->client()->request('clients.updateClient', [
            'client_id'               => $clientId,
            'client_name'             => $name,
            'client_groups_id_client' => self::GROUP_ID,
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
}

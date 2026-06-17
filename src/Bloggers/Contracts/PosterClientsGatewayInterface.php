<?php

declare(strict_types=1);

namespace App\Bloggers\Contracts;

/**
 * Poster clients.* operations scoped to the bloggers group. Abstracts the
 * Poster API behind an interface so BloggerService can be unit-tested with a
 * fake, and so the Poster-specific field mapping lives in one place.
 */
interface PosterClientsGatewayInterface
{
    /**
     * Every client in the bloggers group (raw Poster rows).
     *
     * @return list<array<string,mixed>>
     */
    public function listGroupClients(): array;

    /**
     * Create a blogger client in the group. $name is the promocode (stored via
     * client_name), $comment is the real blogger name.
     *
     * @return int new client_id (0 on failure)
     */
    public function createClient(string $name, string $comment, string $email, float $discountPct): int;

    public function updateClient(int $clientId, string $name, string $comment, string $email, float $discountPct): void;

    /**
     * Per-client sales for the period (dash.getClientsSales).
     * revenue = paid after discount (cashback base); checks = closed checks.
     *
     * @return array<int,array{checks:int,revenue:int}> keyed by client_id
     */
    public function clientsSales(string $dateFrom, string $dateTo): array;
}

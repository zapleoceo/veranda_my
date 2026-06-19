<?php

declare(strict_types=1);

namespace App\Bloggers\Contracts;

/**
 * Poster clients.* + finance.* operations the bloggers module needs. The group
 * and payout-category ids are passed in (they come from the module config), so
 * this gateway holds no policy and stays trivially testable with a fake.
 */
interface PosterClientsGatewayInterface
{
    /**
     * Every client in the given group (raw Poster rows).
     *
     * @return list<array<string,mixed>>
     */
    public function listGroupClients(int $groupId): array;

    /**
     * Create a blogger client in the group. $name is the promocode (stored via
     * client_name), $comment is the real blogger name. Returns new client_id.
     */
    public function createClient(int $groupId, string $name, string $comment, string $email, float $discountPct): int;

    public function updateClient(int $groupId, int $clientId, string $name, string $comment, string $email, float $discountPct): void;

    /**
     * Per-client sales for the period (dash.getClientsSales).
     * revenue = paid after discount (cashback base); checks = closed checks.
     *
     * @return array<int,array{checks:int,revenue:int}> keyed by client_id
     */
    public function clientsSales(string $dateFrom, string $dateTo): array;

    /**
     * Sum of payouts already made per blogger in the period: reads expense
     * finance transactions of the payout category and matches the blogger by
     * the `ID=<client_id>` tag in the comment (same scheme as /employees).
     *
     * @return array<int,int> paid amount in minor units (cents), keyed by client_id
     */
    public function payouts(int $categoryId, string $dateFrom, string $dateTo): array;

    /**
     * Record a cashback payout as a Poster expense finance transaction.
     * $amountVnd is in major units (VND). Returns the new transaction id.
     */
    public function createPayout(int $categoryId, int $accountId, int $amountVnd, string $comment): int;

    /** @return array<int,string> finance accounts: id => name */
    public function financeAccounts(): array;

    /**
     * Closed checks for the period filtered by client_id. Each row is the raw
     * Poster dash.getTransactions entry (date_close, payed_sum, discount_sum …).
     *
     * @return list<array<string,mixed>>
     */
    public function clientChecks(string $dateFrom, string $dateTo, int $clientId): array;
}

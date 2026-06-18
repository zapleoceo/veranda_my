<?php

declare(strict_types=1);

namespace Tests\Unit\Bloggers;

use App\Bloggers\Services\PosterClientsGateway;
use App\Classes\PosterAPI;
use App\Payday3\Contracts\PosterApiProviderInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PosterClientsGatewayTest extends TestCase
{
    private PosterApiProviderInterface&MockObject $provider;
    private PosterAPI&MockObject                  $api;

    protected function setUp(): void
    {
        $this->provider = $this->createMock(PosterApiProviderInterface::class);
        $this->api      = $this->createMock(PosterAPI::class);
        $this->provider->method('client')->willReturn($this->api);
    }

    private function make(): PosterClientsGateway
    {
        return new PosterClientsGateway($this->provider);
    }

    public function test_listGroupClients_filters_the_given_group(): void
    {
        $this->api->expects($this->once())->method('request')
            ->with('clients.getClients', ['group_id' => 10, 'num' => 1000])
            ->willReturn([['client_id' => '1'], ['client_id' => '2']]);

        $this->assertCount(2, $this->make()->listGroupClients(10));
    }

    public function test_createClient_sends_group_discount_comment_and_returns_id(): void
    {
        $this->api->expects($this->once())->method('request')
            ->with(
                'clients.createClient',
                $this->callback(static function (array $p): bool {
                    return $p['client_name'] === 'PROMO'
                        && $p['client_groups_id_client'] === 10
                        && $p['discount_per'] === 7.0
                        && $p['email'] === 'a@x.com'
                        && $p['comment'] === 'Real Name';
                }),
                'POST'
            )
            ->willReturn(308);

        $this->assertSame(308, $this->make()->createClient(10, 'PROMO', 'Real Name', 'a@x.com', 7.0));
    }

    public function test_createClient_strips_emoji_from_comment(): void
    {
        $this->api->expects($this->once())->method('request')
            ->with('clients.createClient', $this->callback(static fn (array $p): bool => $p['comment'] === 'Anna'), 'POST')
            ->willReturn(1);

        $this->make()->createClient(10, 'PROMO', "Anna\u{1F600}", 'a@x.com', 0.0);
    }

    public function test_clientsSales_parses_revenue_and_checks_keyed_by_id(): void
    {
        $this->api->method('request')->willReturn([
            ['client_id' => '85', 'revenue' => '4878000', 'clients' => '33', 'sum' => '5450000'],
            ['client_id' => '0',  'revenue' => '1', 'clients' => '1'],
        ]);

        $out = $this->make()->clientsSales('2026-06-01', '2026-06-30');

        $this->assertArrayHasKey(85, $out);
        $this->assertSame(33, $out[85]['checks']);
        $this->assertSame(4878000, $out[85]['revenue']);
        $this->assertArrayNotHasKey(0, $out);
    }

    public function test_payouts_sums_by_client_id_filtered_by_category(): void
    {
        $this->api->expects($this->once())->method('request')
            ->with('finance.getTransactions', ['dateFrom' => '20260601', 'dateTo' => '20260630', 'type' => 0])
            ->willReturn([
                ['category_id' => '24', 'comment' => 'BLOGGER ANNA ID=42 by m', 'amount' => '-5000000'],
                ['category_id' => '24', 'comment' => 'BLOGGER ANNA ID=42 by m', 'amount' => '-2000000'],
                ['category_id' => '24', 'comment' => 'BLOGGER BOB ID=7 by m',   'amount' => '-1000000'],
                ['category_id' => '6',  'comment' => 'SLR x ID=99 by m',        'amount' => '-9999999'], // wrong category
                ['category_id' => '24', 'comment' => 'no id tag here',          'amount' => '-100'],     // no ID=
            ]);

        $out = $this->make()->payouts(24, '2026-06-01', '2026-06-30');

        $this->assertSame(7000000, $out[42]); // 5,000,000 + 2,000,000 (abs cents)
        $this->assertSame(1000000, $out[7]);
        $this->assertArrayNotHasKey(99, $out);
        $this->assertCount(2, $out);
    }

    public function test_createPayout_sends_expense_in_category_with_account_and_amount(): void
    {
        $this->api->expects($this->once())->method('request')
            ->with(
                'finance.createTransactions',
                $this->callback(static function (array $p): bool {
                    return (int) $p['type'] === 0
                        && (int) $p['category'] === 24
                        && (int) $p['account_from'] === 7
                        && (int) $p['amount_from'] === 500000
                        && str_contains((string) $p['comment'], 'ID=42')
                        && isset($p['date']);
                }),
                'POST'
            )
            ->willReturn(900);

        $this->assertSame(900, $this->make()->createPayout(24, 7, 500000, 'BLOGGER ANNA ID=42 by m'));
    }

    public function test_financeAccounts_maps_id_to_name(): void
    {
        $this->api->method('request')->willReturn([
            ['account_id' => '1', 'name' => 'Счет Андрея'],
            ['account_id' => '2', 'name' => 'Касса'],
            ['account_id' => '0', 'name' => 'skip'],
            ['account_id' => '5', 'name' => ''],
        ]);

        $out = $this->make()->financeAccounts();

        $this->assertSame('Счет Андрея', $out[1]);
        $this->assertSame('Касса', $out[2]);
        $this->assertArrayNotHasKey(0, $out);
        $this->assertArrayNotHasKey(5, $out);
    }
}

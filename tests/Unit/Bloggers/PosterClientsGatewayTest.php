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

    public function test_listGroupClients_filters_the_bloggers_group(): void
    {
        $this->api->expects($this->once())->method('request')
            ->with('clients.getClients', ['group_id' => 10, 'num' => 1000])
            ->willReturn([['client_id' => '1'], ['client_id' => '2']]);

        $this->assertCount(2, $this->make()->listGroupClients());
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

        $this->assertSame(308, $this->make()->createClient('PROMO', 'Real Name', 'a@x.com', 7.0));
    }

    public function test_createClient_strips_emoji_from_comment(): void
    {
        $this->api->expects($this->once())->method('request')
            ->with(
                'clients.createClient',
                $this->callback(static fn (array $p): bool => $p['comment'] === 'Anna'),
                'POST'
            )
            ->willReturn(1);

        $this->make()->createClient('PROMO', "Anna\u{1F600}", 'a@x.com', 0.0);
    }

    public function test_updateClient_sends_client_id_and_group(): void
    {
        $this->api->expects($this->once())->method('request')
            ->with(
                'clients.updateClient',
                $this->callback(static function (array $p): bool {
                    return $p['client_id'] === 42
                        && $p['client_name'] === 'PROMO'
                        && $p['client_groups_id_client'] === 10;
                }),
                'POST'
            )
            ->willReturn('42');

        $this->make()->updateClient(42, 'PROMO', 'name', 'e@x.com', 5.0);
    }

    public function test_clientsSales_sends_compact_dates(): void
    {
        $this->api->expects($this->once())->method('request')
            ->with('dash.getClientsSales', ['dateFrom' => '20260601', 'dateTo' => '20260630'])
            ->willReturn([]);

        $this->make()->clientsSales('2026-06-01', '2026-06-30');
    }

    public function test_clientsSales_parses_revenue_and_checks_keyed_by_id(): void
    {
        $this->api->method('request')->willReturn([
            ['client_id' => '85', 'revenue' => '4878000', 'clients' => '33', 'sum' => '5450000'],
            ['client_id' => '0',  'revenue' => '1', 'clients' => '1'], // id 0 → skipped
        ]);

        $out = $this->make()->clientsSales('2026-06-01', '2026-06-30');

        $this->assertArrayHasKey(85, $out);
        $this->assertSame(33, $out[85]['checks']);
        $this->assertSame(4878000, $out[85]['revenue']);
        $this->assertArrayNotHasKey(0, $out);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Bloggers;

use App\Bloggers\Contracts\BloggerRepositoryInterface;
use App\Bloggers\Contracts\PosterClientsGatewayInterface;
use App\Bloggers\Services\BloggerService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BloggerServiceTest extends TestCase
{
    private PosterClientsGatewayInterface&MockObject $poster;
    private BloggerRepositoryInterface&MockObject    $repo;

    protected function setUp(): void
    {
        $this->poster = $this->createMock(PosterClientsGatewayInterface::class);
        $this->repo   = $this->createMock(BloggerRepositoryInterface::class);
        // Default module config; overridable per-test is not needed (group 10 / cat 24).
        $this->repo->method('loadConfig')->willReturn(['group_id' => 10, 'payout_category_id' => 24]);
    }

    private function make(): BloggerService
    {
        return new BloggerService($this->poster, $this->repo);
    }

    // ─── pure helpers ───────────────────────────────────────────────────

    public function test_cashback_is_percent_of_revenue(): void
    {
        $this->assertSame(70000, BloggerService::cashback(1000000, 7.0));
        $this->assertSame(0,     BloggerService::cashback(1000000, 0.0));
        $this->assertSame(50,    BloggerService::cashback(1000, 5.0));
    }

    public function test_promocode_reconstructed_from_name_parts(): void
    {
        $this->assertSame('TESTBLOG',      BloggerService::promocodeOf(['lastname' => 'TESTBLOG', 'firstname' => '']));
        $this->assertSame('Anna Promokod', BloggerService::promocodeOf(['lastname' => 'Anna', 'firstname' => 'Promokod']));
        $this->assertSame('',              BloggerService::promocodeOf([]));
    }

    // ─── listBloggers ───────────────────────────────────────────────────

    public function test_listBloggers_merges_poster_and_local_and_sorts(): void
    {
        $this->poster->method('listGroupClients')->willReturn([
            ['client_id' => '308', 'firstname' => '', 'lastname' => 'TESTBLOG', 'comment' => 'Test', 'email' => 't@x.com', 'discount_per' => '10', 'total_payed_sum' => '0'],
            ['client_id' => '77',  'firstname' => '', 'lastname' => 'ANNA',     'comment' => 'Anna I', 'email' => '', 'discount_per' => '5', 'total_payed_sum' => '500000'],
        ]);
        $this->repo->method('allByClientId')->willReturn([
            77 => ['cashback_pct' => 7.0, 'is_active' => 1, 'created_by' => 'mgr'],
        ]);

        $list = $this->make()->listBloggers();

        $this->assertCount(2, $list);
        $this->assertSame('ANNA', $list[0]['promocode']);
        $this->assertSame(7.0, $list[0]['cashback_pct']);
        $this->assertTrue($list[0]['tracked']);
        $this->assertSame('TESTBLOG', $list[1]['promocode']);
        $this->assertSame(0.0, $list[1]['cashback_pct']);
        $this->assertSame(1, $list[1]['is_active']);
        $this->assertFalse($list[1]['tracked']);
    }

    // ─── report (accrued / paid / to-pay) ───────────────────────────────

    public function test_report_computes_paid_and_topay_and_totals(): void
    {
        $this->poster->method('listGroupClients')->willReturn([
            ['client_id' => '1', 'lastname' => 'A', 'comment' => '', 'discount_per' => '0'],
            ['client_id' => '2', 'lastname' => 'B', 'comment' => '', 'discount_per' => '0'],
            ['client_id' => '3', 'lastname' => 'C', 'comment' => '', 'discount_per' => '0'],
        ]);
        $this->repo->method('allByClientId')->willReturn([
            1 => ['cashback_pct' => 10.0, 'is_active' => 1, 'created_by' => ''],
            2 => ['cashback_pct' => 5.0,  'is_active' => 1, 'created_by' => ''],
            3 => ['cashback_pct' => 20.0, 'is_active' => 0, 'created_by' => ''],
        ]);
        $this->poster->method('clientsSales')->willReturn([
            1 => ['checks' => 10, 'revenue' => 1000000], // accrued 100000
            2 => ['checks' => 5,  'revenue' => 400000],  // accrued 20000
            3 => ['checks' => 99, 'revenue' => 9999999], // inactive
        ]);
        // client 1 already paid 30000 (cents); client 2 nothing
        $this->poster->method('payouts')->willReturn([1 => 30000]);

        $rep = $this->make()->report('2026-06-01', '2026-06-30');

        $this->assertCount(3, $rep['rows']);
        // active first, sorted by to-pay desc: client1 (70000) then client2 (20000)
        $this->assertSame(1, $rep['rows'][0]['client_id']);
        $this->assertSame(100000, $rep['rows'][0]['cashback']);
        $this->assertSame(30000,  $rep['rows'][0]['paid']);
        $this->assertSame(70000,  $rep['rows'][0]['topay']);
        $this->assertSame(2, $rep['rows'][1]['client_id']);
        $this->assertSame(20000, $rep['rows'][1]['topay']);
        $this->assertSame(3, $rep['rows'][2]['client_id']); // inactive last
        $this->assertSame(0, $rep['rows'][2]['is_active']);

        // totals cover active only
        $this->assertSame(2,      $rep['totals']['bloggers']);
        $this->assertSame(120000, $rep['totals']['cashback']);
        $this->assertSame(30000,  $rep['totals']['paid']);
        $this->assertSame(90000,  $rep['totals']['topay']);
    }

    public function test_report_scoped_to_one_client_returns_only_that_row(): void
    {
        $this->poster->method('listGroupClients')->willReturn([
            ['client_id' => '1', 'lastname' => 'A', 'comment' => '', 'discount_per' => '0'],
            ['client_id' => '2', 'lastname' => 'B', 'comment' => '', 'discount_per' => '0'],
        ]);
        $this->repo->method('allByClientId')->willReturn([
            1 => ['cashback_pct' => 10.0, 'is_active' => 1, 'created_by' => ''],
            2 => ['cashback_pct' => 5.0,  'is_active' => 1, 'created_by' => ''],
        ]);
        $this->poster->method('clientsSales')->willReturn([
            1 => ['checks' => 3, 'revenue' => 200000],
            2 => ['checks' => 9, 'revenue' => 900000],
        ]);
        $this->poster->method('payouts')->willReturn([]);

        $rep = $this->make()->report('2026-06-01', '2026-06-30', 2);

        $this->assertCount(1, $rep['rows']);
        $this->assertSame(2, $rep['rows'][0]['client_id']);
        $this->assertSame(45000, $rep['rows'][0]['cashback']); // 900,000 × 5%
        $this->assertSame(1,      $rep['totals']['bloggers']);
        $this->assertSame(900000, $rep['totals']['revenue']);
    }

    public function test_report_topay_never_negative_when_overpaid(): void
    {
        $this->poster->method('listGroupClients')->willReturn([
            ['client_id' => '1', 'lastname' => 'A', 'comment' => '', 'discount_per' => '0'],
        ]);
        $this->repo->method('allByClientId')->willReturn([
            1 => ['cashback_pct' => 10.0, 'is_active' => 1, 'created_by' => ''],
        ]);
        $this->poster->method('clientsSales')->willReturn([1 => ['checks' => 1, 'revenue' => 100000]]); // accrued 10000
        $this->poster->method('payouts')->willReturn([1 => 999999]); // overpaid

        $rep = $this->make()->report('2026-06-01', '2026-06-30');
        $this->assertSame(0, $rep['rows'][0]['topay']);
    }

    // ─── create / update ────────────────────────────────────────────────

    public function test_create_passes_group_and_calls_gateway_and_repo(): void
    {
        $this->poster->method('listGroupClients')->willReturn([]);
        $this->poster->expects($this->once())->method('createClient')
            ->with(10, 'ANNA2026', 'Anna Ivanova', 'anna@gmail.com', 10.0)
            ->willReturn(500);
        $this->repo->expects($this->once())->method('create')
            ->with(500, 7.0, 'mgr@x.com');

        $id = $this->make()->create('ANNA2026', 'Anna Ivanova', 'anna@gmail.com', 10.0, 7.0, 'mgr@x.com');
        $this->assertSame(500, $id);
    }

    public function test_create_rejects_promocode_with_space(): void
    {
        $this->poster->method('listGroupClients')->willReturn([]);
        $this->poster->expects($this->never())->method('createClient');
        $this->expectException(\RuntimeException::class);
        $this->make()->create('ANNA 2026', 'x', '', 0, 0, 'mgr');
    }

    public function test_create_rejects_duplicate_promocode(): void
    {
        $this->poster->method('listGroupClients')->willReturn([
            ['client_id' => '9', 'lastname' => 'ANNA2026', 'firstname' => ''],
        ]);
        $this->poster->expects($this->never())->method('createClient');
        $this->expectException(\RuntimeException::class);
        $this->make()->create('anna2026', 'x', '', 0, 0, 'mgr');
    }

    public function test_update_passes_group_and_persists(): void
    {
        $this->poster->method('listGroupClients')->willReturn([
            ['client_id' => '42', 'lastname' => 'ANNA', 'firstname' => ''],
        ]);
        $this->poster->expects($this->once())->method('updateClient')
            ->with(10, 42, 'ANNA', 'Anna I', 'a@gmail.com', 12.0);
        $this->repo->expects($this->once())->method('saveCashback')
            ->with(42, 8.0);

        $this->make()->update(42, 'ANNA', 'Anna I', 'a@gmail.com', 12.0, 8.0);
    }

    // ─── pay ────────────────────────────────────────────────────────────

    public function test_pay_creates_payout_in_configured_category_with_id_tag(): void
    {
        $this->poster->method('listGroupClients')->willReturn([
            ['client_id' => '42', 'lastname' => 'ANNA', 'firstname' => ''],
        ]);
        $this->poster->expects($this->once())->method('createPayout')
            ->with(
                24,       // configured payout category
                7,        // account
                500000,   // VND
                $this->callback(static fn (string $c): bool =>
                    str_contains($c, 'ID=42') && str_contains($c, 'ANNA') && str_contains($c, 'by mgr@x.com'))
            )
            ->willReturn(900);

        $this->assertSame(900, $this->make()->pay(42, 500000, 7, 'mgr@x.com'));
    }

    public function test_pay_rejects_zero_amount(): void
    {
        $this->poster->expects($this->never())->method('createPayout');
        $this->expectException(\RuntimeException::class);
        $this->make()->pay(42, 0, 7, 'mgr');
    }

    public function test_pay_rejects_missing_account(): void
    {
        $this->poster->expects($this->never())->method('createPayout');
        $this->expectException(\RuntimeException::class);
        $this->make()->pay(42, 500000, 0, 'mgr');
    }

    public function test_pay_keeps_custom_comment_but_enforces_id_tag(): void
    {
        $this->poster->method('listGroupClients')->willReturn([
            ['client_id' => '42', 'lastname' => 'ANNA', 'firstname' => ''],
        ]);
        // Custom comment WITHOUT an ID tag → the service must append ID=42.
        $this->poster->expects($this->once())->method('createPayout')
            ->with(24, 7, 500000, $this->callback(static fn (string $c): bool =>
                str_contains($c, 'кешбек за май') && str_contains($c, 'ID=42')))
            ->willReturn(901);

        $this->assertSame(901, $this->make()->pay(42, 500000, 7, 'mgr@x.com', 'кешбек за май'));
    }

    // ─── setActive / config ─────────────────────────────────────────────

    public function test_setActive_delegates_to_repository(): void
    {
        $this->repo->expects($this->once())->method('setActive')->with(7, false);
        $this->make()->setActive(7, false);
    }

    public function test_saveConfig_persists(): void
    {
        $this->repo->expects($this->once())->method('saveConfig')->with(5, 30);
        $this->make()->saveConfig(5, 30);
    }

    public function test_saveConfig_rejects_nonpositive(): void
    {
        $this->repo->expects($this->never())->method('saveConfig');
        $this->expectException(\RuntimeException::class);
        $this->make()->saveConfig(0, 30);
    }
}

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
            77 => ['cashback_pct' => 7.0, 'gmail' => 'anna@gmail.com', 'is_active' => 1, 'created_by' => 'mgr'],
        ]);

        $list = $this->make()->listBloggers();

        $this->assertCount(2, $list);
        // sorted by promocode (case-insensitive): ANNA, then TESTBLOG
        $this->assertSame('ANNA', $list[0]['promocode']);
        $this->assertSame(7.0, $list[0]['cashback_pct']);
        $this->assertTrue($list[0]['tracked']);

        // 308 has no local row → cashback 0, active by default, not tracked
        $this->assertSame('TESTBLOG', $list[1]['promocode']);
        $this->assertSame('Test', $list[1]['name']);
        $this->assertSame(10.0, $list[1]['discount_pct']);
        $this->assertSame(0.0, $list[1]['cashback_pct']);
        $this->assertSame(1, $list[1]['is_active']);
        $this->assertFalse($list[1]['tracked']);
    }

    // ─── report ─────────────────────────────────────────────────────────

    public function test_report_computes_cashback_totals_sorts_and_excludes_inactive(): void
    {
        $this->poster->method('listGroupClients')->willReturn([
            ['client_id' => '1', 'lastname' => 'A', 'comment' => '', 'discount_per' => '0'],
            ['client_id' => '2', 'lastname' => 'B', 'comment' => '', 'discount_per' => '0'],
            ['client_id' => '3', 'lastname' => 'C', 'comment' => '', 'discount_per' => '0'],
        ]);
        $this->repo->method('allByClientId')->willReturn([
            1 => ['cashback_pct' => 10.0, 'gmail' => '', 'is_active' => 1, 'created_by' => ''],
            2 => ['cashback_pct' => 5.0,  'gmail' => '', 'is_active' => 1, 'created_by' => ''],
            3 => ['cashback_pct' => 20.0, 'gmail' => '', 'is_active' => 0, 'created_by' => ''],
        ]);
        $this->poster->method('clientsSales')
            ->with('2026-06-01', '2026-06-30')
            ->willReturn([
                1 => ['checks' => 10, 'revenue' => 1000000],
                2 => ['checks' => 5,  'revenue' => 400000],
                3 => ['checks' => 99, 'revenue' => 9999999], // inactive → must be ignored
            ]);

        $rep = $this->make()->report('2026-06-01', '2026-06-30');

        $this->assertCount(2, $rep['rows']);
        // sorted by revenue desc
        $this->assertSame(1, $rep['rows'][0]['client_id']);
        $this->assertSame(100000, $rep['rows'][0]['cashback']); // 1,000,000 × 10%
        $this->assertSame(2, $rep['rows'][1]['client_id']);
        $this->assertSame(20000, $rep['rows'][1]['cashback']);  // 400,000 × 5%

        $this->assertSame(2,       $rep['totals']['bloggers']);
        $this->assertSame(15,      $rep['totals']['checks']);
        $this->assertSame(1400000, $rep['totals']['revenue']);
        $this->assertSame(120000,  $rep['totals']['cashback']);
    }

    public function test_report_blogger_without_sales_is_zero(): void
    {
        $this->poster->method('listGroupClients')->willReturn([
            ['client_id' => '1', 'lastname' => 'A', 'comment' => '', 'discount_per' => '0'],
        ]);
        $this->repo->method('allByClientId')->willReturn([
            1 => ['cashback_pct' => 10.0, 'gmail' => '', 'is_active' => 1, 'created_by' => ''],
        ]);
        $this->poster->method('clientsSales')->willReturn([]);

        $rep = $this->make()->report('2026-06-01', '2026-06-30');

        $this->assertSame(0, $rep['rows'][0]['checks']);
        $this->assertSame(0, $rep['rows'][0]['revenue']);
        $this->assertSame(0, $rep['rows'][0]['cashback']);
    }

    // ─── create ─────────────────────────────────────────────────────────

    public function test_create_validates_then_calls_gateway_and_repo(): void
    {
        $this->poster->method('listGroupClients')->willReturn([]); // promocode free
        $this->poster->expects($this->once())->method('createClient')
            ->with('ANNA2026', 'Anna Ivanova', 'anna@gmail.com', 10.0)
            ->willReturn(500);
        $this->repo->expects($this->once())->method('create')
            ->with(500, 'anna@gmail.com', 7.0, 'mgr@x.com');

        $id = $this->make()->create('ANNA2026', 'Anna Ivanova', 'anna@gmail.com', 10.0, 7.0, 'mgr@x.com');
        $this->assertSame(500, $id);
    }

    public function test_create_clamps_percentages(): void
    {
        $this->poster->method('listGroupClients')->willReturn([]);
        $this->poster->method('createClient')->with('X', 'n', '', 100.0)->willReturn(1);
        $this->repo->expects($this->once())->method('create')->with(1, '', 0.0, 'm');

        $this->make()->create('X', 'n', '', 150.0, -5.0, 'm'); // 150→100, -5→0
    }

    public function test_create_rejects_blank_promocode(): void
    {
        $this->poster->expects($this->never())->method('createClient');
        $this->expectException(\RuntimeException::class);
        $this->make()->create('   ', 'x', '', 0, 0, 'mgr');
    }

    public function test_create_rejects_promocode_with_space(): void
    {
        $this->poster->method('listGroupClients')->willReturn([]);
        $this->poster->expects($this->never())->method('createClient');
        $this->expectException(\RuntimeException::class);
        $this->make()->create('ANNA 2026', 'x', '', 0, 0, 'mgr');
    }

    public function test_create_rejects_duplicate_promocode_case_insensitive(): void
    {
        $this->poster->method('listGroupClients')->willReturn([
            ['client_id' => '9', 'lastname' => 'ANNA2026', 'firstname' => ''],
        ]);
        $this->poster->expects($this->never())->method('createClient');
        $this->expectException(\RuntimeException::class);
        $this->make()->create('anna2026', 'x', '', 0, 0, 'mgr');
    }

    // ─── update / setActive ─────────────────────────────────────────────

    public function test_update_allows_same_promocode_for_self_and_persists(): void
    {
        $this->poster->method('listGroupClients')->willReturn([
            ['client_id' => '42', 'lastname' => 'ANNA', 'firstname' => ''],
        ]);
        $this->poster->expects($this->once())->method('updateClient')
            ->with(42, 'ANNA', 'Anna I', 'a@gmail.com', 12.0);
        $this->repo->expects($this->once())->method('saveCashbackAndGmail')
            ->with(42, 'a@gmail.com', 8.0);

        $this->make()->update(42, 'ANNA', 'Anna I', 'a@gmail.com', 12.0, 8.0);
    }

    public function test_update_rejects_promocode_taken_by_another(): void
    {
        $this->poster->method('listGroupClients')->willReturn([
            ['client_id' => '42', 'lastname' => 'ANNA',  'firstname' => ''],
            ['client_id' => '99', 'lastname' => 'BORIS', 'firstname' => ''],
        ]);
        $this->poster->expects($this->never())->method('updateClient');
        $this->expectException(\RuntimeException::class);
        $this->make()->update(42, 'BORIS', 'x', '', 0, 0);
    }

    public function test_setActive_delegates_to_repository(): void
    {
        $this->repo->expects($this->once())->method('setActive')->with(7, false);
        $this->make()->setActive(7, false);
    }
}

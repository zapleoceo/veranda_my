<?php

declare(strict_types=1);

namespace Tests\Unit\Payday3;

use App\Infrastructure\Database;
use App\Payday3\Domain\DateRange;
use App\Payday3\Domain\PosterIds;
use App\Payday3\Services\FinancePosterService;
use App\Payday3\Services\ManualLinker;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Payday3\Fakes\FixedSettings;
use Tests\Unit\Payday3\Fakes\ScriptedPoster;

/**
 * - ручные связки (🎯): одна транзакция, ошибки пар не глотаются молча;
 * - FinancePosterService: один вызов Poster на (период, счёт) за запрос;
 * - VC/BB по id метода оплаты, не по названию.
 */
final class LinksAndMemoTest extends TestCase
{
    private function db(int &$transactions): Database
    {
        $db = $this->createMock(Database::class);
        $db->method('transaction')->willReturnCallback(function (callable $fn) use (&$transactions) {
            $transactions++;
            return $fn();
        });
        return $db;
    }

    public function test_manual_linker_links_every_pair_in_one_transaction(): void
    {
        $tx = 0;
        $pairs = [];
        $res = (new ManualLinker($this->db($tx)))->link([1, 2], [10, 20], function (int $a, int $b) use (&$pairs) {
            $pairs[] = [$a, $b];
        });
        $this->assertSame(1, $tx);
        $this->assertSame(4, $res['added']);
        $this->assertSame([], $res['errors']);
        $this->assertSame([[1, 10], [1, 20], [2, 10], [2, 20]], $pairs);
    }

    public function test_manual_linker_reports_failures_instead_of_swallowing(): void
    {
        $tx = 0;
        $prev = ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');
        try {
            $res = (new ManualLinker($this->db($tx)))->link([1, 2], [10], function (int $a) {
                if ($a === 1) throw new \InvalidArgumentException('ids must be positive');
                throw new \PDOException('SQLSTATE[HY000]: gone away');
            });
        } finally {
            ini_set('error_log', (string)$prev);
        }
        $this->assertSame(0, $res['added']);
        $this->assertCount(2, $res['errors']);
        $this->assertStringContainsString('ids must be positive', $res['errors'][0]);
        $this->assertStringNotContainsString('SQLSTATE', $res['errors'][1], 'infra details stay in the log');
    }

    public function test_manual_linker_ids_filter(): void
    {
        $this->assertSame([3, 5], ManualLinker::ids(['3', 0, -1, 'x', 5]));
        $this->assertSame([], ManualLinker::ids(null));
    }

    public function test_finance_fetch_is_memoised_per_range_and_account(): void
    {
        $poster = new ScriptedPoster(['finance.getTransactions' => static fn(array $p) => [
            ['transaction_id' => $p['account_id'] * 100, 'type' => 0, 'amount' => -100000, 'date' => '2026-09-18 10:00:00'],
        ]]);
        $svc = new FinancePosterService($poster, FixedSettings::defaults());
        $r = DateRange::of('2026-09-18', '2026-09-18');

        $first  = $svc->fetch($r);
        $second = $svc->fetch($r);
        $this->assertEquals($first, $second);
        $this->assertCount(2, $poster->callsTo('finance.getTransactions'), 'Andrey + Tips once each');

        $svc->fetch(DateRange::of('2026-09-17', '2026-09-17'));
        $this->assertCount(4, $poster->callsTo('finance.getTransactions'), 'other range → live again');
    }

    public function test_method_lite_is_by_id(): void
    {
        $this->assertSame('VC', PosterIds::methodLite(PosterIds::METHOD_VIETNAM));
        $this->assertSame('BB', PosterIds::methodLite(PosterIds::METHOD_BYBIT));
        $this->assertNull(PosterIds::methodLite(3));
        $this->assertNull(PosterIds::methodLite(null));
    }

    public function test_in_data_poster_shape_exposes_method_id_and_lite_by_id(): void
    {
        $p = \App\Payday3\Domain\PosterTransaction::fromRow([
            'transaction_id' => 5, 'poster_payment_method_id' => PosterIds::METHOD_VIETNAM,
            'payment_method_display' => 'Renamed method', 'date_close' => '2026-09-18 10:00:00',
        ]);
        $m = new \ReflectionMethod(\App\Payday3\Http\Actions\InDataAction::class, 'posterShape');
        $shape = $m->invoke(null, $p);
        $this->assertSame(PosterIds::METHOD_VIETNAM, $shape['payment_method_id']);
        $this->assertSame('VC', $shape['payment_method_lite'], 'rename in Poster must not move the check out of VC');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Payday3;

use App\Payday3\Contracts\LinkRepositoryInterface;
use App\Payday3\Domain\DateRange;
use App\Payday3\Domain\PosterIds;
use App\Payday3\Services\FinanceTransferFetcher;
use App\Payday3\Services\FinanceTransferService;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Payday3\Fakes\FixedSettings;
use Tests\Unit\Payday3\Fakes\PassThroughLock;
use Tests\Unit\Payday3\Fakes\ScriptedPoster;

/**
 * Финансовые транзакции (Vietnam / Tips):
 *   - чистые правила сумм;
 *   - access.getEmployees / finance.getAccounts — один раз на запрос;
 *   - dmY-ретрай только при ОШИБКЕ Ymd, не на пустом дне;
 *   - createTransfer — под именованной блокировкой (гонка двух кликов).
 */
final class FinanceTransferServiceTest extends TestCase
{
    private PassThroughLock $lock;

    private function checks(): array
    {
        return [
            // Vietnam: 100 000 + 5 000 tips
            ['transaction_id' => 1, 'pay_type' => 2, 'payment_method_id' => PosterIds::METHOD_VIETNAM,
             'payed_card' => 10000000, 'payed_third_party' => 0, 'tip_sum' => 500000],
            // Card check with 20 000 tips (10 000 service + 10 000 card tips), linked
            ['transaction_id' => 2, 'pay_type' => 3, 'payment_method_id' => 3,
             'payed_card' => 30000000, 'payed_third_party' => 0, 'tip_sum' => 1000000, 'tips_card' => 1000000],
            // Card check with tips but NOT linked
            ['transaction_id' => 3, 'pay_type' => 2, 'payment_method_id' => 3,
             'payed_card' => 5000000, 'tip_sum' => 700000],
            // Cash check — ignored
            ['transaction_id' => 4, 'pay_type' => 1, 'payment_method_id' => PosterIds::METHOD_VIETNAM,
             'payed_card' => 0, 'tip_sum' => 900000],
        ];
    }

    private function service(ScriptedPoster $poster): FinanceTransferService
    {
        $links = $this->createMock(LinkRepositoryInterface::class);
        $links->method('linkedPosterIds')->willReturnCallback(
            static fn(array $ids) => array_values(array_intersect($ids, [2]))
        );
        $this->lock = new PassThroughLock();
        return new FinanceTransferService(new FinanceTransferFetcher($poster), $links,
            FixedSettings::defaults(), $poster, $this->lock);
    }

    public function test_expected_sums(): void
    {
        $poster = new ScriptedPoster(['dash.getTransactions' => $this->checks()]);
        $svc    = $this->service($poster);
        $range  = DateRange::of('2026-09-18', '2026-09-18');

        $this->assertSame(105_000, $svc->vietnam($range)['total_vnd']);
        $this->assertSame(20_000, $svc->tips($range)['total_vnd'], 'only linked non-Vietnam card checks');
        $this->assertCount(1, $poster->callsTo('dash.getTransactions'), 'checks fetched once per request');
    }

    public function test_lookups_are_memoised_and_empty_day_has_no_dmy_retry(): void
    {
        $poster = new ScriptedPoster([
            'dash.getTransactions'    => [],
            'finance.getTransactions' => [[
                'transaction_id' => 9, 'type' => '2', 'account_to' => 9, 'account_from' => 1,
                'amount' => 10500000, 'date' => '2026-09-18 23:55:00', 'user_id' => 4, 'comment' => 'x',
            ]],
            'access.getEmployees' => [['user_id' => 4, 'name' => 'Service']],
            'finance.getAccounts' => [['account_id' => 9, 'name' => 'Vietnam']],
        ]);
        $svc   = $this->service($poster);
        $range = DateRange::of('2026-09-18', '2026-09-18');
        $v = $svc->vietnam($range);
        $svc->tips($range);

        $this->assertCount(1, $poster->callsTo('access.getEmployees'));
        $this->assertCount(1, $poster->callsTo('finance.getAccounts'));
        foreach ($poster->callsTo('finance.getTransactions') as $c) {
            $this->assertSame('20260918', $c['params']['dateFrom'], 'no dmY retry');
        }
        $this->assertSame(105_000, $v['found'][0]['sum_minor']);
        $this->assertSame('Service', $v['found'][0]['user']);
        $this->assertSame('Vietnam', $v['found'][0]['account']);
    }

    public function test_dmy_retry_only_on_error_and_history_is_filtered_by_date(): void
    {
        $poster = new ScriptedPoster([
            'finance.getTransactions' => static function (array $p) {
                if ($p['dateFrom'] === '20260918') throw new \Exception('bad date format');
                return [
                    ['type' => '1', 'account_id' => 8, 'amount' => 100, 'date' => '2026-09-18 10:00:00'],
                    ['type' => '1', 'account_id' => 8, 'amount' => 100, 'date' => '2025-01-01 10:00:00'],
                ];
            },
        ]);
        $fetcher = new FinanceTransferFetcher($poster);
        $prev = ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');
        try {
            $rows = $fetcher->financeTransactions(DateRange::of('2026-09-18', '2026-09-18'), ['account_id' => 8]);
        } finally {
            ini_set('error_log', (string)$prev);
        }
        $this->assertCount(1, $rows, 'the dmY history dump is cut to the range');
        $this->assertSame('18092026', $poster->callsTo('finance.getTransactions')[1]['params']['dateFrom']);
    }

    public function test_create_transfer_runs_under_lock_and_is_idempotent(): void
    {
        $poster = new ScriptedPoster([
            'dash.getTransactions'      => $this->checks(),
            'finance.getTransactions'   => [[
                'type' => '2', 'account_to' => 9, 'amount' => 10500000, 'date' => '2026-09-18 23:55:00',
                'comment' => 'Перевод чеков вьетнаской компании by op',
            ]],
            'finance.createTransactions' => ['id' => 1],
        ]);
        $res = $this->service($poster)->createTransfer('vietnam', DateRange::of('2026-09-18', '2026-09-18'), 'op');

        $this->assertTrue($res['already']);
        $this->assertSame(['payday_fin_transfer_vietnam2026-09-18'], $this->lock->names);
        $this->assertSame([], $poster->callsTo('finance.createTransactions'));
    }

    public function test_create_transfer_posts_when_absent(): void
    {
        $poster = new ScriptedPoster([
            'dash.getTransactions'       => $this->checks(),
            'finance.getTransactions'    => [],
            'finance.createTransactions' => ['id' => 1],
        ]);
        $res = $this->service($poster)->createTransfer('tips', DateRange::of('2026-09-18', '2026-09-18'), 'op');
        $this->assertFalse($res['already']);
        $this->assertSame(20_000, $res['amount_vnd']);
        $create = $poster->callsTo('finance.createTransactions');
        $this->assertCount(1, $create);
        $this->assertSame(8, $create[0]['params']['account_to']);
    }

    public function test_poster_failure_is_reported_not_hidden(): void
    {
        $poster = new ScriptedPoster([
            'dash.getTransactions' => static fn() => throw new \Exception('down'),
        ]);
        $prev = ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');
        try {
            $v = $this->service($poster)->vietnam(DateRange::of('2026-09-18', '2026-09-18'));
        } finally {
            ini_set('error_log', (string)$prev);
        }
        $this->assertNull($v['total_vnd']);
        $this->assertArrayHasKey('error', $v);
    }
}

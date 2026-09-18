<?php

declare(strict_types=1);

namespace Tests\Unit\Payday3;

use App\Payday3\Contracts\ActualBalanceRepositoryInterface;
use App\Payday3\Contracts\PosterBalanceServiceInterface;
use App\Payday3\Domain\ActualBalances;
use App\Payday3\Domain\Money;
use App\Payday3\Services\BalanceSyncService;
use App\Payday3\Services\FinanceTransferFetcher;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Payday3\Fakes\FixedSettings;
use Tests\Unit\Payday3\Fakes\InMemorySessionStore;
use Tests\Unit\Payday3\Fakes\ScriptedPoster;

/**
 * UPLD (коррекция баланса):
 *   - сумма считается на сервере (сохранённый Факт. − живой Poster), а не
 *     берётся из браузера;
 *   - проверка «уже создано сегодня» сравнивает в VND: finance.* отдаёт
 *     копейки, и старое сравнение строки "1234.00" с "123400" никогда не
 *     срабатывало → повторный commit создавал вторую коррекцию.
 */
final class BalanceSyncServiceTest extends TestCase
{
    private InMemorySessionStore $session;

    private function service(ScriptedPoster $poster, ?int $factAndrey, ?int $posterAndrey): BalanceSyncService
    {
        $this->session = new InMemorySessionStore();
        $actual = $this->createMock(ActualBalanceRepositoryInterface::class);
        $actual->method('latestFor')->willReturn(
            $factAndrey === null ? null : new ActualBalances(date('Y-m-d'), andrey: Money::vnd($factAndrey))
        );
        $bal = $this->createMock(PosterBalanceServiceInterface::class);
        $bal->method('snapshot')->willReturn([
            'andrey' => $posterAndrey, 'vietnam' => null, 'cash' => null, 'stash' => null, 'total' => null,
            'accounts' => [['account_id' => 8, 'name' => 'Tips', 'balance' => 0]], 'unmapped' => [],
        ]);
        return new BalanceSyncService($poster, FixedSettings::defaults(), $this->session, $actual, $bal,
            new FinanceTransferFetcher($poster));
    }

    public function test_plan_uses_server_side_difference(): void
    {
        $svc  = $this->service(new ScriptedPoster(), 1_012_000, 1_000_000);
        $plan = $svc->plan(0, 'op@example.com');
        $this->assertSame(12_000, $plan['plan']['diff_vnd']);
        $this->assertSame(1, $plan['plan']['type']);
        $this->assertSame('Tips', $plan['plan']['account_name'], 'name from the snapshot, no extra Poster call');
    }

    public function test_plan_rejects_a_forged_client_difference(): void
    {
        $svc = $this->service(new ScriptedPoster(), 1_012_000, 1_000_000);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Разница изменилась');
        $svc->plan(900_000_000, 'op');
    }

    public function test_plan_requires_saved_fact(): void
    {
        $this->expectException(\DomainException::class);
        $this->service(new ScriptedPoster(), null, 1_000_000)->plan(5, 'op');
    }

    public function test_commit_posts_the_stored_amount_once(): void
    {
        $poster = new ScriptedPoster(['finance.getTransactions' => [], 'finance.createTransactions' => ['id' => 1]]);
        $svc    = $this->service($poster, 1_000_000, 1_012_000);
        $nonce  = $svc->plan(-12_000, 'op')['nonce'];

        $res = $svc->commit($nonce);
        $this->assertTrue($res['ok']);
        $create = $poster->callsTo('finance.createTransactions');
        $this->assertCount(1, $create);
        $this->assertSame(0, $create[0]['params']['type'], 'shortage = expense');
        $this->assertSame('12000.00', $create[0]['params']['amount_from']);

        $this->expectException(\DomainException::class);
        $svc->commit($nonce);   // nonce is one-shot
    }

    public function test_commit_detects_existing_correction_in_cents(): void
    {
        $poster = new ScriptedPoster([
            'finance.getTransactions' => [[
                'type'         => 1,
                'account_to'   => 8,
                'amount_to'    => '1200000',          // 12 000 VND in Poster cents
                'comment'      => 'Коррекция излишек - недостачи за счет чая by op',
                'date'         => date('Y-m-d H:i:s'),
            ]],
        ]);
        $svc   = $this->service($poster, 1_012_000, 1_000_000);
        $nonce = $svc->plan(12_000, 'op')['nonce'];

        $res = $svc->commit($nonce);
        $this->assertTrue($res['already'] ?? false);
        $this->assertSame([], $poster->callsTo('finance.createTransactions'));
    }

    public function test_same_amount_on_other_account_is_not_a_duplicate(): void
    {
        $poster = new ScriptedPoster([
            'finance.getTransactions' => [[
                'type' => 1, 'account_to' => 1, 'amount_to' => 1200000,
                'comment' => 'Коррекция излишек - недостачи за счет чая', 'date' => date('Y-m-d H:i:s'),
            ]],
            'finance.createTransactions' => ['id' => 2],
        ]);
        $svc = $this->service($poster, 1_012_000, 1_000_000);
        $svc->commit($svc->plan(12_000, 'op')['nonce']);
        $this->assertCount(1, $poster->callsTo('finance.createTransactions'));
    }
}

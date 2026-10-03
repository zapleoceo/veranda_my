<?php

declare(strict_types=1);

namespace Tests\Unit\Payday3;

use App\Payday3\Contracts\ActualBalanceRepositoryInterface;
use App\Payday3\Contracts\FinanceTransferServiceInterface;
use App\Payday3\Contracts\PosterBalanceServiceInterface;
use App\Payday3\Domain\ActualBalances;
use App\Payday3\Domain\Actor;
use App\Payday3\Domain\DateRange;
use App\Payday3\Domain\Money;
use App\Payday3\Domain\PosterIds;
use App\Payday3\Services\FinanceTransferFetcher;
use App\Payday3\Services\FinanceTransferService;
use App\Payday3\Services\GrabTopUpService;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Payday3\Fakes\FixedSettings;
use Tests\Unit\Payday3\Fakes\InMemoryAuditLog;
use Tests\Unit\Payday3\Fakes\PassThroughLock;
use Tests\Unit\Payday3\Fakes\ScriptedPoster;

/**
 * «Пополнить Grab»: излишек = Факт. Вьет. − Poster Вьет., гейт по сведённым
 * Vietnam/Tips и дублю GRAB за день, серверная перепроверка на create,
 * payload finance.createTransactions (приход, кат. 25, счёт 9).
 */
final class GrabTopUpServiceTest extends TestCase
{
    private const DAY = '2026-10-03';

    private PassThroughLock $lock;
    private InMemoryAuditLog $audit;

    /** Reconciled card: expected 100 000, found 100 000. */
    private static function okCard(int $total = 100_000): array
    {
        return ['total_vnd' => $total, 'found' => $total > 0 ? [['sum_minor' => $total, 'transaction_id' => 1]] : []];
    }

    private function service(
        ScriptedPoster $poster,
        ?int $factVnd = 1_000_000,
        ?int $posterVnd = 263_830,
        ?array $vietnam = null,
        ?array $tips = null,
        ?string $factDate = null,
    ): GrabTopUpService {
        $vietnam ??= self::okCard();
        $tips    ??= self::okCard(20_000);
        $transfers = new class($vietnam, $tips) implements FinanceTransferServiceInterface {
            public function __construct(private array $v, private array $t) {}
            public function vietnam(DateRange $range): array { return $this->v; }
            public function tips(DateRange $range): array { return $this->t; }
            public function createTransfer(string $kind, DateRange $range, string $byLabel = ''): array { throw new \LogicException(); }
        };
        $actual = new class($factVnd, $factDate) implements ActualBalanceRepositoryInterface {
            public function __construct(private ?int $v, private ?string $d) {}
            public function latestFor(string $date): ?ActualBalances
            {
                return new ActualBalances($this->d ?? $date, vietnam: $this->v === null ? null : Money::vnd($this->v));
            }
            public function save(ActualBalances $bal): int { return 1; }
        };
        $balances = new class($posterVnd) implements PosterBalanceServiceInterface {
            public function __construct(private ?int $v) {}
            public function snapshot(): array
            {
                return ['andrey' => 0, 'vietnam' => $this->v, 'cash' => 0, 'stash' => 0, 'total' => 0, 'accounts' => [], 'unmapped' => []];
            }
        };
        $this->lock  = new PassThroughLock();
        $this->audit = new InMemoryAuditLog();
        return new GrabTopUpService($transfers, new FinanceTransferFetcher($poster), $actual, $balances,
            FixedSettings::defaults(), $poster, $this->lock, $this->audit);
    }

    private static function range(): DateRange { return DateRange::of(self::DAY, self::DAY); }

    public function test_surplus_is_fact_minus_poster_and_allows_create(): void
    {
        $st = $this->service(new ScriptedPoster())->status(self::range());
        $this->assertSame(736_170, $st['surplus_vnd']);
        $this->assertSame('ok', $st['reason']);
        $this->assertTrue($st['can_create']);
        $this->assertSame('Излишек 736 170 — можно пополнить', $st['message']);
    }

    public function test_decide_gating_order(): void
    {
        $this->assertSame('exists', GrabTopUpService::decide(10, 0, false, false, true));
        $this->assertSame('no_fact', GrabTopUpService::decide(null, 0, true, true, false));
        $this->assertSame('no_poster', GrabTopUpService::decide(10, null, true, true, false));
        $this->assertSame('not_reconciled', GrabTopUpService::decide(10, 0, true, false, false));
        $this->assertSame('not_reconciled', GrabTopUpService::decide(10, 0, false, true, false));
        $this->assertSame('no_surplus', GrabTopUpService::decide(10, 10, true, true, false));
        $this->assertSame('no_surplus', GrabTopUpService::decide(5, 10, true, true, false));
        $this->assertSame('ok', GrabTopUpService::decide(11, 10, true, true, false));
    }

    public function test_is_reconciled_rule(): void
    {
        $this->assertTrue(FinanceTransferService::isReconciled(self::okCard()));
        $this->assertTrue(FinanceTransferService::isReconciled(['total_vnd' => 0, 'found' => []]), 'nothing to transfer');
        $this->assertFalse(FinanceTransferService::isReconciled(['total_vnd' => 100_000, 'found' => []]));
        $this->assertFalse(FinanceTransferService::isReconciled(['total_vnd' => 100_000, 'found' => [['sum_minor' => 99_000]]]));
        $this->assertFalse(FinanceTransferService::isReconciled(['total_vnd' => null, 'found' => []]));
        $this->assertFalse(FinanceTransferService::isReconciled(self::okCard() + ['error' => 'x']));
    }

    public function test_unreconciled_tips_blocks_status_and_create(): void
    {
        $poster = new ScriptedPoster();
        $svc = $this->service($poster, tips: ['total_vnd' => 20_000, 'found' => []]);
        $st  = $svc->status(self::range());
        $this->assertFalse($st['tips_ok']);
        $this->assertSame('not_reconciled', $st['reason']);
        $this->assertFalse($st['can_create']);

        try {
            $svc->create(self::range(), new Actor('op@x'));
            $this->fail('expected rejection');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Сначала сведите Vietnam и Tips', $e->getMessage());
        }
        $this->assertSame([], $poster->callsTo('finance.createTransactions'));
    }

    public function test_no_surplus_is_rejected_on_create(): void
    {
        $poster = new ScriptedPoster();
        $svc = $this->service($poster, factVnd: 500_000, posterVnd: 500_000);
        $this->assertSame('no_surplus', $svc->status(self::range())['reason']);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Излишка');
        $svc->create(self::range(), new Actor('op@x'));
    }

    public function test_missing_fact_is_rejected(): void
    {
        $svc = $this->service(new ScriptedPoster(), factVnd: null);
        $this->assertSame('no_fact', $svc->status(self::range())['reason']);
        $this->expectException(\DomainException::class);
        $svc->create(self::range(), new Actor('op@x'));
    }

    public function test_fact_from_an_older_day_does_not_count(): void
    {
        $svc = $this->service(new ScriptedPoster(), factDate: '2026-10-02');
        $st  = $svc->status(self::range());
        $this->assertSame('no_fact', $st['reason']);
        $this->assertNull($st['surplus_vnd']);
        $this->assertSame('Факт. «Вьет.» не сохранён за 03.10.2026', $st['message']);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Факт. «Вьет.» не сохранён за 03.10.2026');
        $svc->create(self::range(), new Actor('op@x'));
    }

    public function test_existing_grab_income_is_found_and_blocks_create(): void
    {
        $poster = new ScriptedPoster([
            'finance.getTransactions' => [
                // Vietnam transfer on the same account — not GRAB.
                ['transaction_id' => 5, 'type' => '2', 'account_to' => 9, 'amount' => 10000000,
                 'date' => self::DAY . ' 23:55:00', 'comment' => 'Перевод чеков вьетнаской компании'],
                ['transaction_id' => 77, 'type' => '1', 'account_id' => 9, 'category_id' => PosterIds::CATEGORY_GRAB,
                 'amount' => 73617000, 'date' => self::DAY . ' 23:55:00', 'comment' => 'Пополнение Grab 03.10.2026'],
            ],
        ]);
        $svc = $this->service($poster);
        $st  = $svc->status(self::range());
        $this->assertSame('exists', $st['reason']);
        $this->assertFalse($st['can_create']);
        $this->assertCount(1, $st['found']);
        $this->assertSame(77, $st['found'][0]['transaction_id']);
        $this->assertSame(736_170, $st['found'][0]['sum_minor']);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('уже есть в Poster');
        $svc->create(self::range(), new Actor('op@x'));
    }

    public function test_grab_found_by_comment_when_category_missing(): void
    {
        $poster = new ScriptedPoster([
            'finance.getTransactions' => [
                ['transaction_id' => 78, 'type' => '1', 'account_id' => 9, 'amount' => 100,
                 'date' => self::DAY . ' 12:00:00', 'comment' => 'пополнение grab 03.10.2026'],
            ],
        ]);
        $this->assertSame('exists', $this->service($poster)->status(self::range())['reason']);
    }

    public function test_create_posts_income_with_grab_category_under_lock_and_audits(): void
    {
        $poster = new ScriptedPoster(['finance.createTransactions' => ['transaction_id' => 99]]);
        $svc = $this->service($poster);
        $res = $svc->create(self::range(), new Actor('op@x'));

        $this->assertSame(['payday_fin_transfer_grab' . self::DAY], $this->lock->names);
        $this->assertFalse($res['already']);
        $this->assertSame(736_170, $res['amount_vnd']);

        $calls = $poster->callsTo('finance.createTransactions');
        $this->assertCount(1, $calls);
        $p = $calls[0]['params'];
        $this->assertSame('POST', $calls[0]['http']);
        $this->assertSame(1, $p['type'], 'income');
        $this->assertSame(PosterIds::CATEGORY_GRAB, $p['category']);
        $this->assertSame(9, $p['account_to']);
        $this->assertSame('736170.00', $p['amount_to'], 'VND with 2 decimals, like the UPLD income');
        $this->assertSame(self::DAY . ' 23:55:00', $p['date']);
        $this->assertSame('client', $p['timezone']);
        $this->assertSame('Пополнение Grab 03.10.2026', $p['comment']);

        $this->assertCount(1, $this->audit->rows);
        $this->assertSame('finance.grab_topup', $this->audit->rows[0]['action']);
        $this->assertSame('op@x', $this->audit->rows[0]['email']);

        // A retry right after (Poster list lagging) is rejected by the audit fingerprint.
        $this->expectException(\DomainException::class);
        $svc->create(self::range(), new Actor('op@x'));
    }

    public function test_poster_error_in_cards_blocks_create(): void
    {
        $poster = new ScriptedPoster();
        $svc = $this->service($poster, vietnam: ['total_vnd' => null, 'found' => [], 'error' => 'Не удалось посчитать сумму из Poster']);
        $this->assertFalse($svc->status(self::range())['can_create']);
        $this->expectException(\RuntimeException::class);
        $svc->create(self::range(), new Actor('op@x'));
    }
}

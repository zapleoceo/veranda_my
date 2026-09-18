<?php

declare(strict_types=1);

namespace Tests\Unit\Payday3;

use App\Payday3\Contracts\FinanceServiceInterface;
use App\Payday3\Contracts\IncomeFinanceLinkRepositoryInterface;
use App\Payday3\Contracts\LinkRepositoryInterface;
use App\Payday3\Contracts\MailServiceInterface;
use App\Payday3\Contracts\OutLinkRepositoryInterface;
use App\Payday3\Contracts\PosterRepositoryInterface;
use App\Payday3\Contracts\SepayRepositoryInterface;
use App\Payday3\Domain\DateRange;
use App\Payday3\Domain\FinanceMatchPolicy;
use App\Payday3\Domain\FinanceTransaction;
use App\Payday3\Domain\IncomeFinanceLink;
use App\Payday3\Domain\MailTransaction;
use App\Payday3\Domain\Money;
use App\Payday3\Domain\OutLink;
use App\Payday3\Domain\PosterTransaction;
use App\Payday3\Domain\ReconciliationLink;
use App\Payday3\Domain\SepayTransaction;
use App\Payday3\Services\IncomeFinanceReconciliationService;
use App\Payday3\Services\OutReconciliationService;
use App\Payday3\Services\ReconciliationService;
use PHPUnit\Framework\TestCase;

/**
 * Глобальная сверка кассы: «доходы с доходами, расходы с расходами».
 *
 *   поступление SePay ↔ чек Poster          (ReconciliationService)
 *   поступление SePay ↔ приход Poster       (IncomeFinanceReconciliationService)
 *   расход из письма  ↔ расход Poster       (OutReconciliationService)
 *
 * Данные — реальные транзакции Poster за 16.09.2026. Переводы между
 * счетами (кат. 1) и итоги смены (кат. 2 «Кассовые смены») в авто-связь
 * не идут. Одно движение денег — одна пара.
 */
final class GlobalReconciliationTest extends TestCase
{
    private const DAY = '2026-09-16';

    /** @return list<FinanceTransaction> Poster finance, 16.09 (как отдаёт API — новые сверху) */
    private static function finance16(): array
    {
        $f = static fn(int $id, int $cat, int $amount, string $time, string $comment) =>
            new FinanceTransaction($id, 4, $cat, $amount > 0 ? 1 : 0, Money::vnd($amount), Money::vnd(0), self::DAY . ' ' . $time, $comment);
        return [
            $f(11064, 1, -35000,   '23:55:01', 'Перевод типсов'),
            $f(11062, 1, -709000,  '23:55:00', 'Перевод чеков вьетнаской компании'),
            $f(11076, 16, 1331200, '23:13:00', 'компенсация от игровой за соц страхование'),
            $f(11061, 2, 35000,    '22:19:09', 'Card tips per shift'),
            $f(11059, 2, 5384000,  '22:19:08', 'Card payments'),
            $f(11057, 2, 709000,   '22:19:07', 'Vietnam Company — Card payments'),
            $f(11053, 7, -2440000, '17:48:00', 'Реклама в инст'),
            $f(11041, 3, -3560000, '14:18:26', 'Supply #3379'),
            $f(11065, 1, 35000,    '23:55:00', 'Перевод типсов'),
        ];
    }

    private static function sepay(int $id, int $amount, string $time): SepayTransaction
    {
        return SepayTransaction::fromRow(['sepay_id' => $id, 'transaction_date' => self::DAY . ' ' . $time,
            'transfer_amount' => $amount, 'content' => 'transfer ' . $id]);
    }

    private function range(): DateRange { return DateRange::of(self::DAY, self::DAY); }

    /** @param list<object> $rows */
    private function repo(string $class, array $rows, ?array &$added = null): object
    {
        $added = [];
        $mock = $this->createMock($class);
        $mock->method('listInRange')->willReturn($rows);
        $mock->method('add')->willReturnCallback(function (...$args) use (&$added) { $added[] = $args[0]; });
        return $mock;
    }

    private function financeService(array $rows): FinanceServiceInterface
    {
        $m = $this->createMock(FinanceServiceInterface::class);
        $m->method('fetch')->willReturn($rows);
        return $m;
    }

    private function sepayRepo(array $rows): SepayRepositoryInterface
    {
        $m = $this->createMock(SepayRepositoryInterface::class);
        $m->method('listOpenInRange')->willReturn($rows);
        return $m;
    }

    // ─── правило отбора ──────────────────────────────────────────────

    public function test_policy_income_with_income_expense_with_expense_no_transfers_no_shift_totals(): void
    {
        $income  = [];
        $expense = [];
        foreach (self::finance16() as $f) {
            if (FinanceMatchPolicy::isIncomeCandidate($f))  $income[]  = $f->transactionId;
            if (FinanceMatchPolicy::isExpenseCandidate($f)) $expense[] = $f->transactionId;
        }
        $this->assertSame([11076], $income, 'из приходов — только «компенсация»: итоги смены и переводы не идут');
        $this->assertSame([11053, 11041], $expense, 'из расходов — реклама и поставка; переводы не идут');
    }

    // ─── поступление ↔ приход Poster ─────────────────────────────────

    public function test_income_without_a_check_links_to_poster_income(): void
    {
        // 16.09: SHIRIAEVA 1 331 200 в 23:12:44 — в чеках нет, в транзакциях есть.
        $svc = new IncomeFinanceReconciliationService(
            $this->sepayRepo([self::sepay(82328778, 1331200, '23:12:44'), self::sepay(82242440, 60000, '17:05:29')]),
            $this->financeService(self::finance16()),
            $this->repo(IncomeFinanceLinkRepositoryInterface::class, [], $added),
            $this->repo(LinkRepositoryInterface::class, []),
            $this->repo(OutLinkRepositoryInterface::class, []),
        );
        $res = $svc->autoLink($this->range());

        $this->assertSame(1, $res['added']);
        $this->assertEquals([new IncomeFinanceLink(82328778, 11076, 'auto_green', false)], $added);
    }

    public function test_income_already_linked_to_a_check_is_not_offered(): void
    {
        $svc = new IncomeFinanceReconciliationService(
            $this->sepayRepo([self::sepay(82328778, 1331200, '23:12:44')]),
            $this->financeService(self::finance16()),
            $this->repo(IncomeFinanceLinkRepositoryInterface::class, [], $added),
            $this->repo(LinkRepositoryInterface::class, [new ReconciliationLink(82328778, 777, 'manual', true)]),
            $this->repo(OutLinkRepositoryInterface::class, []),
        );
        $svc->autoLink($this->range());
        $this->assertSame([], $added, 'у поступления уже есть пара — чек');
    }

    public function test_shift_totals_are_never_matched_even_with_equal_amount_and_time(): void
    {
        // Поступление 35 000 в 22:19 — ровно как «Card tips per shift».
        $svc = new IncomeFinanceReconciliationService(
            $this->sepayRepo([self::sepay(1, 35000, '22:19:09'), self::sepay(2, 5384000, '22:19:08')]),
            $this->financeService(self::finance16()),
            $this->repo(IncomeFinanceLinkRepositoryInterface::class, [], $added),
            $this->repo(LinkRepositoryInterface::class, []),
            $this->repo(OutLinkRepositoryInterface::class, []),
        );
        $svc->autoLink($this->range());
        $this->assertSame([], $added, 'итоги смены уже сведены через чеки — второй раз нельзя');
    }

    public function test_poster_income_already_paired_with_an_expense_is_taken(): void
    {
        $svc = new IncomeFinanceReconciliationService(
            $this->sepayRepo([self::sepay(82328778, 1331200, '23:12:44')]),
            $this->financeService(self::finance16()),
            $this->repo(IncomeFinanceLinkRepositoryInterface::class, [], $added),
            $this->repo(LinkRepositoryInterface::class, []),
            $this->repo(OutLinkRepositoryInterface::class, [new OutLink(1, 11076, 'manual', true)]),
        );
        $svc->autoLink($this->range());
        $this->assertSame([], $added);
    }

    public function test_manual_income_link_is_stored_as_manual_for_the_day(): void
    {
        $repo = $this->createMock(IncomeFinanceLinkRepositoryInterface::class);
        $repo->expects($this->once())->method('add')
            ->with(new IncomeFinanceLink(82242440, 11057, 'manual', true), self::DAY);
        $svc = new IncomeFinanceReconciliationService(
            $this->sepayRepo([]), $this->financeService([]), $repo,
            $this->repo(LinkRepositoryInterface::class, []), $this->repo(OutLinkRepositoryInterface::class, []),
        );
        // Вручную менеджер может связать что угодно — даже итог смены.
        $svc->manualLink(82242440, 11057, self::DAY);
    }

    // ─── расход ↔ расход Poster ──────────────────────────────────────

    public function test_expense_links_only_to_poster_expense_never_to_income_of_same_size(): void
    {
        $mail = $this->createMock(MailServiceInterface::class);
        $mail->method('fetch')->willReturn([
            new MailTransaction(1168, self::DAY . ' 11:14:52', Money::vnd(3560000), 'Interbank transfer receipt', ''),
            new MailTransaction(900,  self::DAY . ' 22:19:09', Money::vnd(35000),   'Winthin BIDV transfer receipt', ''),
        ]);
        $svc = new OutReconciliationService(
            $mail,
            $this->financeService(self::finance16()),
            $this->repo(OutLinkRepositoryInterface::class, [], $added),
            $this->repo(IncomeFinanceLinkRepositoryInterface::class, []),
        );
        $res = $svc->autoLink($this->range());

        $pairs = array_map(static fn(OutLink $l) => [$l->mailUid, $l->financeId], $added);
        $this->assertSame([[1168, 11041]], $pairs,
            'поставка 3 560 000 связалась; расход 35 000 НЕ схватил приход «Card tips per shift» +35 000 и перевод −35 000');
        $this->assertSame(1, $res['added']);
    }

    public function test_poster_expense_already_paired_with_income_is_taken(): void
    {
        $mail = $this->createMock(MailServiceInterface::class);
        $mail->method('fetch')->willReturn([new MailTransaction(1168, self::DAY . ' 14:18:00', Money::vnd(3560000), 'receipt', '')]);
        $svc = new OutReconciliationService(
            $mail,
            $this->financeService(self::finance16()),
            $this->repo(OutLinkRepositoryInterface::class, [], $added),
            $this->repo(IncomeFinanceLinkRepositoryInterface::class, [new IncomeFinanceLink(5, 11041, 'manual', true)]),
        );
        $svc->autoLink($this->range());
        $this->assertSame([], $added);
    }

    // ─── поступление ↔ чек ───────────────────────────────────────────

    public function test_check_matcher_skips_income_already_paired_with_poster_income(): void
    {
        $poster = $this->createMock(PosterRepositoryInterface::class);
        $poster->method('listClosedInRange')->willReturn([PosterTransaction::fromRow([
            'transaction_id' => 28066, 'date_close' => self::DAY . ' 23:12:00', 'payed_card' => 133120000,
        ])]);
        $svc = new ReconciliationService(
            $this->sepayRepo([self::sepay(82328778, 1331200, '23:12:44')]),
            $poster,
            $this->repo(LinkRepositoryInterface::class, [], $added),
            $this->repo(IncomeFinanceLinkRepositoryInterface::class, [new IncomeFinanceLink(82328778, 11076, 'auto_green', false)]),
        );
        $svc->autoLink($this->range());
        $this->assertSame([], $added, 'одно поступление — одна пара');
    }

    public function test_check_matcher_still_links_card_payment_to_its_check(): void
    {
        $poster = $this->createMock(PosterRepositoryInterface::class);
        $poster->method('listClosedInRange')->willReturn([PosterTransaction::fromRow([
            'transaction_id' => 28032, 'date_close' => self::DAY . ' 14:09:10', 'payed_card' => 19600000,
        ])]);
        $svc = new ReconciliationService(
            $this->sepayRepo([self::sepay(81001, 196000, '14:09:35')]),
            $poster,
            $this->repo(LinkRepositoryInterface::class, [], $added),
            $this->repo(IncomeFinanceLinkRepositoryInterface::class, []),
        );
        $res = $svc->autoLink($this->range());
        $this->assertEquals([new ReconciliationLink(81001, 28032, 'auto_green', false)], $added);
        $this->assertSame(['added' => 1, 'total' => 1], $res);
    }
}

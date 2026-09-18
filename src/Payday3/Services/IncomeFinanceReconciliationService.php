<?php

declare(strict_types=1);

namespace App\Payday3\Services;

use App\Payday3\Contracts\FinanceServiceInterface;
use App\Payday3\Contracts\IncomeFinanceLinkRepositoryInterface;
use App\Payday3\Contracts\IncomeFinanceReconciliationServiceInterface;
use App\Payday3\Contracts\LinkRepositoryInterface;
use App\Payday3\Contracts\OutLinkRepositoryInterface;
use App\Payday3\Contracts\SepayRepositoryInterface;
use App\Payday3\Domain\AmountTimeMatcher;
use App\Payday3\Domain\DateRange;
use App\Payday3\Domain\FinanceMatchPolicy;
use App\Payday3\Domain\IncomeFinanceLink;

/**
 * Incoming bank row ↔ Poster finance INCOME — money that reached the
 * bank without a sales check (e.g. «компенсация от игровой …»).
 *
 * Runs after the checks matcher: a SePay row already linked to a Poster
 * check is not offered here, and a finance row already linked to an
 * outgoing bank row is not offered either — one bank movement, one
 * counterpart. Which finance rows qualify: FinanceMatchPolicy.
 */
final class IncomeFinanceReconciliationService implements IncomeFinanceReconciliationServiceInterface
{
    public function __construct(
        private readonly SepayRepositoryInterface             $sepay,
        private readonly FinanceServiceInterface              $finance,
        private readonly IncomeFinanceLinkRepositoryInterface $links,
        private readonly LinkRepositoryInterface              $checkLinks,
        private readonly OutLinkRepositoryInterface           $outLinks,
    ) {}

    public function autoLink(DateRange $range): array
    {
        $existing = $this->links->listInRange($range);
        $linkedSepay = [];
        $linkedFinance = [];
        foreach ($existing as $l) {
            $linkedSepay[$l->sepayId] = true;
            $linkedFinance[$l->financeId] = true;
        }
        foreach ($this->checkLinks->listInRange($range) as $l) $linkedSepay[$l->sepayId] = true;
        foreach ($this->outLinks->listInRange($range) as $l)   $linkedFinance[$l->financeId] = true;

        $bank = [];
        foreach ($this->sepay->listOpenInRange($range) as $s) {
            if (isset($linkedSepay[$s->id])) continue;
            $bank[] = ['id' => $s->id, 'amount' => $s->amount->amount, 'ts' => AmountTimeMatcher::ts($s->transactionDate)];
        }

        $poster = [];
        foreach ($this->finance->fetch($range) as $f) {
            $poster[] = [
                'id'       => $f->transactionId,
                'amount'   => abs($f->amount->amount),
                'ts'       => AmountTimeMatcher::ts($f->date),
                'eligible' => FinanceMatchPolicy::isIncomeCandidate($f) && !isset($linkedFinance[$f->transactionId]),
            ];
        }

        $pairs = AmountTimeMatcher::match($bank, $poster);
        foreach ($pairs as $p) {
            $this->links->add(new IncomeFinanceLink($p['bank'], $p['poster'], $p['type'], false), $range->to);
        }
        return ['added' => count($pairs), 'total' => count($existing) + count($pairs)];
    }

    public function manualLink(int $sepayId, int $financeId, string $dateTo): void
    {
        if ($sepayId <= 0 || $financeId <= 0) {
            throw new \InvalidArgumentException('manualLink: ids must be positive');
        }
        $this->links->add(new IncomeFinanceLink($sepayId, $financeId, 'manual', true), $dateTo);
    }

    public function unlink(int $sepayId, int $financeId): void
    {
        $this->links->remove($sepayId, $financeId);
    }

    public function clearLinks(DateRange $range): int
    {
        return $this->links->clearInRange($range);
    }
}

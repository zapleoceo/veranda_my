<?php

declare(strict_types=1);

namespace App\Payday3\Services;

use App\Payday3\Contracts\FinanceServiceInterface;
use App\Payday3\Contracts\IncomeFinanceLinkRepositoryInterface;
use App\Payday3\Contracts\MailServiceInterface;
use App\Payday3\Contracts\OutLinkRepositoryInterface;
use App\Payday3\Contracts\OutReconciliationServiceInterface;
use App\Payday3\Domain\AmountTimeMatcher;
use App\Payday3\Domain\DateRange;
use App\Payday3\Domain\FinanceMatchPolicy;
use App\Payday3\Domain\OutLink;

/**
 * Outgoing bank row (BIDV mail) ↔ Poster finance EXPENSE.
 *
 * Only real expenses qualify (FinanceMatchPolicy): a Poster income of the
 * same size is never offered — before, amounts were compared by absolute
 * value, so a 35 000 expense could grab a «Card tips per shift» +35 000.
 * Transfers between Poster accounts and shift-close totals are skipped;
 * a finance row already paired with an incoming bank row is taken.
 * The matching itself is AmountTimeMatcher (shared by every pair).
 *
 * Mail rows never persist — every autoLink re-fetches IMAP. Finance rows
 * are fetched live. Edges go to out_links.
 */
final class OutReconciliationService implements OutReconciliationServiceInterface
{
    public function __construct(
        private readonly MailServiceInterface                 $mail,
        private readonly FinanceServiceInterface              $finance,
        private readonly OutLinkRepositoryInterface           $links,
        private readonly IncomeFinanceLinkRepositoryInterface $incomeLinks,
    ) {}

    public function autoLink(DateRange $range): array
    {
        $existing = $this->links->listInRange($range);
        $linkedM = [];
        $linkedF = [];
        foreach ($existing as $e) {
            $linkedM[$e->mailUid]   = true;
            $linkedF[$e->financeId] = true;
        }
        foreach ($this->incomeLinks->listInRange($range) as $l) $linkedF[$l->financeId] = true;

        // Bank e-mails carry a positive amount; the Poster expense is
        // negative — both compared by magnitude, the SIGN is checked by
        // FinanceMatchPolicy on the Poster side.
        $bank = [];
        foreach ($this->mail->fetch($range, includeHidden: false) as $m) {
            if ($m->isHidden || isset($linkedM[$m->mailUid])) continue;
            $bank[] = ['id' => $m->mailUid, 'amount' => abs($m->amount->amount), 'ts' => AmountTimeMatcher::ts($m->date)];
        }

        $poster = [];
        foreach ($this->finance->fetch($range) as $f) {
            $poster[] = [
                'id'       => $f->transactionId,
                'amount'   => abs($f->amount->amount),
                'ts'       => AmountTimeMatcher::ts($f->date),
                'eligible' => FinanceMatchPolicy::isExpenseCandidate($f) && !isset($linkedF[$f->transactionId]),
            ];
        }

        $pairs = AmountTimeMatcher::match($bank, $poster);
        foreach ($pairs as $p) {
            $this->links->add(new OutLink(
                mailUid:   $p['bank'],
                financeId: $p['poster'],
                linkType:  $p['type'],
                isManual:  false,
            ), $range->to);
        }
        return ['added' => count($pairs), 'total' => count($existing) + count($pairs)];
    }

    public function manualLink(int $mailUid, int $financeId, string $dateTo): void
    {
        if ($mailUid <= 0 || $financeId <= 0) {
            throw new \InvalidArgumentException('manualLink: ids must be positive');
        }
        $this->links->add(new OutLink(
            mailUid:   $mailUid,
            financeId: $financeId,
            linkType:  'manual',
            isManual:  true,
        ), $dateTo);
    }

    public function unlink(int $mailUid, int $financeId): void
    {
        $this->links->remove($mailUid, $financeId);
    }

    public function clearLinks(DateRange $range): int
    {
        return $this->links->clearInRange($range);
    }
}

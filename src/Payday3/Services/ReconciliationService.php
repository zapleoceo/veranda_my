<?php

declare(strict_types=1);

namespace App\Payday3\Services;

use App\Payday3\Contracts\IncomeFinanceLinkRepositoryInterface;
use App\Payday3\Contracts\LinkRepositoryInterface;
use App\Payday3\Contracts\PosterRepositoryInterface;
use App\Payday3\Contracts\ReconciliationServiceInterface;
use App\Payday3\Contracts\SepayRepositoryInterface;
use App\Payday3\Domain\AmountTimeMatcher;
use App\Payday3\Domain\DateRange;
use App\Payday3\Domain\ReconciliationLink;

/**
 * Incoming bank row (SePay) ↔ Poster sales check.
 *
 * Eligibility (same as payday2):
 *   - Poster check: card+third > 0 and payment method NOT Vietnam
 *     Company; the compared amount includes tips (card + third + tip —
 *     the "Card+Tips" column).
 *   - SePay row: open (not hidden — the repo filters) and not linked yet,
 *     neither to a check nor to a Poster finance income.
 * The matching itself is AmountTimeMatcher (shared by every pair).
 *
 * Auto-link is additive, never destructive; clearLinks() wipes first.
 * Manual links from the UI are tagged 'manual' (is_manual = 1).
 */
final class ReconciliationService implements ReconciliationServiceInterface
{
    /** Poster payment-method id for «Vietnam Company» — excluded from auto-match. */
    private const METHOD_VIETNAM = 11;

    public function __construct(
        private readonly SepayRepositoryInterface             $sepay,
        private readonly PosterRepositoryInterface            $poster,
        private readonly LinkRepositoryInterface              $links,
        private readonly IncomeFinanceLinkRepositoryInterface $incomeLinks,
    ) {}

    public function autoLink(DateRange $range): array
    {
        $existing = $this->links->listInRange($range);
        $linkedS = [];
        $linkedP = [];
        foreach ($existing as $e) {
            $linkedS[$e->sepayId]             = true;
            $linkedP[$e->posterTransactionId] = true;
        }
        // A SePay row already paired with a Poster finance income has its counterpart.
        foreach ($this->incomeLinks->listInRange($range) as $l) $linkedS[$l->sepayId] = true;

        $bank = [];
        foreach ($this->sepay->listOpenInRange($range) as $s) {
            if (isset($linkedS[$s->id])) continue;
            $bank[] = ['id' => $s->id, 'amount' => $s->amount->amount, 'ts' => AmountTimeMatcher::ts($s->transactionDate)];
        }

        $poster = [];
        foreach ($this->poster->listClosedInRange($range) as $p) {
            $poster[] = [
                'id'       => $p->transactionId,
                'amount'   => $p->payedCard->plus($p->payedThirdParty)->plus($p->tipSum)->amount,
                'ts'       => AmountTimeMatcher::ts($p->dateClose),
                'eligible' => $p->posterPaymentMethodId !== self::METHOD_VIETNAM
                              && !isset($linkedP[$p->transactionId]),
            ];
        }

        $pairs = AmountTimeMatcher::match($bank, $poster);
        foreach ($pairs as $pair) {
            $this->links->add(new ReconciliationLink(
                sepayId:             $pair['bank'],
                posterTransactionId: $pair['poster'],
                linkType:            $pair['type'],
                isManual:            false,
            ));
        }
        return ['added' => count($pairs), 'total' => count($existing) + count($pairs)];
    }

    public function manualLink(int $sepayId, int $posterTransactionId): void
    {
        if ($sepayId <= 0 || $posterTransactionId <= 0) {
            throw new \InvalidArgumentException('manualLink: ids must be positive');
        }
        $this->links->add(new ReconciliationLink(
            sepayId:             $sepayId,
            posterTransactionId: $posterTransactionId,
            linkType:            'manual',
            isManual:            true,
        ));
    }

    public function unlink(int $sepayId, int $posterTransactionId): void
    {
        $this->links->remove($sepayId, $posterTransactionId);
    }

    public function clearLinks(DateRange $range): int
    {
        return $this->links->clearInRange($range);
    }
}

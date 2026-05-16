<?php

declare(strict_types=1);

namespace App\Payday3\Services;

use App\Payday3\Contracts\LinkRepositoryInterface;
use App\Payday3\Contracts\PosterRepositoryInterface;
use App\Payday3\Contracts\ReconciliationServiceInterface;
use App\Payday3\Contracts\SepayRepositoryInterface;
use App\Payday3\Domain\DateRange;
use App\Payday3\Domain\ReconciliationLink;

/**
 * Auto-match algorithm:
 *
 *   GREEN  — exactly one unlinked sepay row and one unlinked poster row
 *            match on amount (sepay.transfer_amount == poster.payed_card +
 *            poster.payed_third_party) AND share the same calendar day.
 *   YELLOW — amounts match but there are multiple candidates on either
 *            side, so the matcher cannot decide. We still record the
 *            edge but flag it for human review.
 *
 * Already-linked rows are skipped — auto-link is additive, not
 * destructive. Use clearLinks() to wipe first if you want a fresh pass.
 *
 * Manual links from the UI are always tagged 'manual' (is_manual = 1).
 */
final class ReconciliationService implements ReconciliationServiceInterface
{
    public function __construct(
        private readonly SepayRepositoryInterface  $sepay,
        private readonly PosterRepositoryInterface $poster,
        private readonly LinkRepositoryInterface   $links,
    ) {}

    public function autoLink(DateRange $range): array
    {
        $existing  = $this->links->listInRange($range);
        $takenS    = []; // sepay_id  → true
        $takenP    = []; // poster_id → true
        foreach ($existing as $e) {
            $takenS[$e->sepayId]             = true;
            $takenP[$e->posterTransactionId] = true;
        }

        $sepayRows  = array_values(array_filter(
            $this->sepay->listOpenInRange($range),
            static fn($s) => !isset($takenS[$s->id]),
        ));
        $posterRows = array_values(array_filter(
            $this->poster->listClosedInRange($range),
            static fn($p) => !isset($takenP[$p->transactionId]),
        ));

        // Bucket both sides by (date, amount).
        $bySepay = [];
        foreach ($sepayRows as $s) {
            $day = substr($s->transactionDate, 0, 10);
            $key = $day . '|' . $s->amount->amount;
            $bySepay[$key][] = $s;
        }
        $byPoster = [];
        foreach ($posterRows as $p) {
            $day = substr($p->dateClose, 0, 10);
            $key = $day . '|' . $p->totalPayed()->amount;
            $byPoster[$key][] = $p;
        }

        $added = 0;
        foreach ($bySepay as $key => $sList) {
            if (!isset($byPoster[$key])) continue;
            $pList = $byPoster[$key];
            $type = (count($sList) === 1 && count($pList) === 1) ? 'auto_green' : 'auto_yellow';

            // Pair them in order. With YELLOW we accept that the pairing
            // may not be the right one, that's why the row is flagged.
            $n = min(count($sList), count($pList));
            for ($i = 0; $i < $n; $i++) {
                $s = $sList[$i];
                $p = $pList[$i];
                $this->links->add(new ReconciliationLink(
                    sepayId:             $s->id,
                    posterTransactionId: $p->transactionId,
                    linkType:            $type,
                    isManual:            false,
                ));
                $added++;
            }
        }

        return ['added' => $added, 'total' => count($existing) + $added];
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

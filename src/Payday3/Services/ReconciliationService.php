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
 * Auto-match algorithm — direct port of payday2/ajax.php?ajax=auto_link.
 *
 * Eligibility (same as payday2):
 *   - Poster check is candidate if it has card+third > 0 AND its payment
 *     method is NOT Vietnam Company. Tips are INCLUDED in the comparison
 *     amount (card + third + tip — matches the "Card+Tips" column).
 *   - Sepay row is candidate if it's open (not hidden) AND not already
 *     linked. Hidden + payment_method filtering is enforced by the repo.
 *
 * Three-pass greedy matcher:
 *   GREEN (tight)         amount matches and |Δtimestamp| ≤ 600s, pick
 *                          the sepay with the smallest time diff.
 *   GREEN (interpolation) Poster check i is sandwiched between two
 *                          already-GREEN checks (i-1 and i+1) — accept
 *                          the matching-amount sepay regardless of Δt
 *                          (the surrounding context vouches for it).
 *   YELLOW (loose)        amount matches but no time-window — best by
 *                          closest timestamp; flagged for human review.
 *
 * Already-linked rows are skipped — auto-link is additive, not
 * destructive. Use clearLinks() to wipe first if you want a fresh pass.
 *
 * Manual links from the UI are always tagged 'manual' (is_manual = 1).
 */
final class ReconciliationService implements ReconciliationServiceInterface
{
    /** Poster payment-method id for «Vietnam Company» — excluded from auto-match. */
    private const METHOD_VIETNAM = 11;
    /** ±10 minutes — same window as payday2. */
    private const GREEN_WINDOW_SECONDS = 600;

    public function __construct(
        private readonly SepayRepositoryInterface  $sepay,
        private readonly PosterRepositoryInterface $poster,
        private readonly LinkRepositoryInterface   $links,
    ) {}

    public function autoLink(DateRange $range): array
    {
        $existing = $this->links->listInRange($range);
        $linkedS  = []; // sepay_id  → true
        $linkedP  = []; // poster_id → true
        foreach ($existing as $e) {
            $linkedS[$e->sepayId]             = true;
            $linkedP[$e->posterTransactionId] = true;
        }

        // ─── Candidate pools ───────────────────────────────────────
        // Sepay: order by timestamp asc; index by amount (VND int).
        $sepayRows = $this->sepay->listOpenInRange($range);
        $sepayByAmount = [];
        foreach ($sepayRows as $s) {
            if (isset($linkedS[$s->id])) continue;
            $amt = $s->amount->amount;
            if ($amt <= 0) continue;
            $sepayByAmount[$amt][] = [
                'id'   => $s->id,
                'ts'   => $this->ts($s->transactionDate),
            ];
        }

        // Poster: ordered by date_close ASC (the repo already does it).
        $posterRows = $this->poster->listClosedInRange($range);

        // Per-poster snapshot: amount (card+third+tip), close-ts, eligibility.
        $checks = [];
        foreach ($posterRows as $p) {
            $pid = $p->transactionId;
            $amount = $p->payedCard->plus($p->payedThirdParty)->plus($p->tipSum)->amount;
            $ts     = $this->ts($p->dateClose);
            $eligible = $amount > 0
                && $p->posterPaymentMethodId !== self::METHOD_VIETNAM
                && !isset($linkedP[$pid])
                && $ts > 0;
            $checks[] = ['pid' => $pid, 'amount' => $amount, 'ts' => $ts, 'eligible' => $eligible];
        }

        $added = 0;
        $greenPosters = []; // posters that got a green link in this run (used by pass 2)

        // ─── Pass 1: GREEN (amount + ±10 min) ──────────────────────
        foreach ($checks as $i => &$c) {
            if (!$c['eligible']) continue;
            $best = $this->pickBestSepay($sepayByAmount[$c['amount']] ?? [], $linkedS, $c['ts'], self::GREEN_WINDOW_SECONDS);
            if ($best === null) continue;
            $this->links->add(new ReconciliationLink(
                sepayId:             $best,
                posterTransactionId: $c['pid'],
                linkType:            'auto_green',
                isManual:            false,
            ));
            $linkedS[$best] = true;
            $linkedP[$c['pid']] = true;
            $greenPosters[$c['pid']] = true;
            $c['eligible'] = false;
            $added++;
        }
        unset($c);

        // ─── Pass 2: GREEN by interpolation ────────────────────────
        // A poster check sandwiched between two already-green checks
        // (in the close-time-ordered list) is matched without a time
        // window — the neighbours vouch for it.
        for ($i = 1; $i < count($checks) - 1; $i++) {
            $c = &$checks[$i];
            if (!$c['eligible']) continue;
            $prev = $checks[$i - 1]['pid'];
            $next = $checks[$i + 1]['pid'];
            if (empty($greenPosters[$prev]) || empty($greenPosters[$next])) continue;

            $best = $this->pickBestSepay($sepayByAmount[$c['amount']] ?? [], $linkedS, $c['ts'], PHP_INT_MAX);
            if ($best === null) continue;
            $this->links->add(new ReconciliationLink(
                sepayId:             $best,
                posterTransactionId: $c['pid'],
                linkType:            'auto_green',
                isManual:            false,
            ));
            $linkedS[$best] = true;
            $linkedP[$c['pid']] = true;
            $greenPosters[$c['pid']] = true;
            $c['eligible'] = false;
            $added++;
            unset($c);
        }

        // ─── Pass 3: YELLOW (amount, no time window) ───────────────
        foreach ($checks as &$c) {
            if (!$c['eligible']) continue;
            $best = $this->pickBestSepay($sepayByAmount[$c['amount']] ?? [], $linkedS, $c['ts'], PHP_INT_MAX);
            if ($best === null) continue;
            $this->links->add(new ReconciliationLink(
                sepayId:             $best,
                posterTransactionId: $c['pid'],
                linkType:            'auto_yellow',
                isManual:            false,
            ));
            $linkedS[$best] = true;
            $linkedP[$c['pid']] = true;
            $c['eligible'] = false;
            $added++;
        }
        unset($c);

        return ['added' => $added, 'total' => count($existing) + $added];
    }

    /**
     * Pick the not-yet-linked sepay row with the smallest |Δt| from
     * the candidate list (already pre-filtered by amount), within the
     * given window. Returns the sepay id or null.
     */
    private function pickBestSepay(array $candidates, array $linkedS, int $posterTs, int $windowSeconds): ?int
    {
        $best = null;
        $bestDiff = PHP_INT_MAX;
        foreach ($candidates as $cand) {
            if (isset($linkedS[$cand['id']])) continue;
            $st = $cand['ts'];
            if ($st <= 0) continue;
            $diff = abs($st - $posterTs);
            if ($diff > $windowSeconds) continue;
            if ($diff < $bestDiff) {
                $best     = $cand['id'];
                $bestDiff = $diff;
            }
        }
        return $best;
    }

    /** Tolerant timestamp parse — accepts 'Y-m-d H:i:s' or anything strtotime grasps. */
    private function ts(string $raw): int
    {
        if ($raw === '') return 0;
        $t = strtotime($raw);
        return $t === false ? 0 : $t;
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

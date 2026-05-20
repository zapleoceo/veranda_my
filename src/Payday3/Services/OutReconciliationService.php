<?php

declare(strict_types=1);

namespace App\Payday3\Services;

use App\Payday3\Contracts\FinanceServiceInterface;
use App\Payday3\Contracts\MailServiceInterface;
use App\Payday3\Contracts\OutLinkRepositoryInterface;
use App\Payday3\Contracts\OutReconciliationServiceInterface;
use App\Payday3\Domain\DateRange;
use App\Payday3\Domain\OutLink;

/**
 * Auto-match algorithm for OUT-direction (mail ↔ Poster finance).
 *
 * Three-pass greedy matcher — mirror of ReconciliationService for IN:
 *   GREEN tight          amount matches and |Δt| ≤ 600 s; pick the
 *                        finance row with the smallest time diff.
 *   GREEN interpolation  Finance row is sandwiched between two
 *                        already-GREEN-linked rows on the date-sorted
 *                        list — accept the matching-amount mail
 *                        regardless of Δt.
 *   YELLOW loose         Amount matches but no time-window. Best by
 *                        Δt; flagged for human review.
 *
 * Mail rows never persist — every autoLink invocation re-fetches IMAP.
 * Finance rows fetched live. Edges go to out_links.
 *
 * Manual / unlink / clearLinks delegate to the repository.
 */
final class OutReconciliationService implements OutReconciliationServiceInterface
{
    /** ±10 minutes — same window as IN-mode / payday2. */
    private const GREEN_WINDOW_SECONDS = 600;

    public function __construct(
        private readonly MailServiceInterface       $mail,
        private readonly FinanceServiceInterface    $finance,
        private readonly OutLinkRepositoryInterface $links,
    ) {}

    public function autoLink(DateRange $range): array
    {
        $existing = $this->links->listInRange($range);
        $linkedM  = [];
        $linkedF  = [];
        foreach ($existing as $e) {
            $linkedM[$e->mailUid]   = true;
            $linkedF[$e->financeId] = true;
        }

        // ─── Candidate pools ───────────────────────────────────────
        // Mail rows index by amount → list of [uid, ts]. abs() because
        // bank email notifies positive value, finance row is negative
        // for an expense.
        $mailRows = $this->mail->fetch($range, includeHidden: false);
        $mailByAmount = [];
        foreach ($mailRows as $m) {
            if (isset($linkedM[$m->mailUid])) continue;
            if ($m->isHidden) continue;
            $amt = abs($m->amount->amount);
            if ($amt <= 0) continue;
            $mailByAmount[$amt][] = [
                'uid' => $m->mailUid,
                'ts'  => $this->ts($m->date),
            ];
        }

        // Finance rows in date_close ASC order (the API returns them
        // chronologically; we don't re-sort).
        $finRows = $this->finance->fetch($range);
        $finance = [];
        foreach ($finRows as $f) {
            $amount = abs($f->amount->amount);
            $ts     = $this->ts($f->date);
            $finance[] = [
                'fid'      => $f->transactionId,
                'amount'   => $amount,
                'ts'       => $ts,
                'eligible' => $amount > 0 && $ts > 0 && !isset($linkedF[$f->transactionId]),
            ];
        }

        $added = 0;
        $greenFins = []; // finance rows that got a green link in this run

        // ─── Pass 1: GREEN (amount + ±10 min) ──────────────────────
        foreach ($finance as $i => &$f) {
            if (!$f['eligible']) continue;
            $best = $this->pickBestMail($mailByAmount[$f['amount']] ?? [], $linkedM, $f['ts'], self::GREEN_WINDOW_SECONDS);
            if ($best === null) continue;
            $this->links->add(new OutLink(
                mailUid:   $best,
                financeId: $f['fid'],
                linkType:  'auto_green',
                isManual:  false,
            ), $range->to);
            $linkedM[$best]  = true;
            $linkedF[$f['fid']] = true;
            $greenFins[$f['fid']] = true;
            $f['eligible']   = false;
            $added++;
        }
        unset($f);

        // ─── Pass 2: GREEN by interpolation ────────────────────────
        for ($i = 1; $i < count($finance) - 1; $i++) {
            $f = &$finance[$i];
            if (!$f['eligible']) continue;
            $prev = $finance[$i - 1]['fid'];
            $next = $finance[$i + 1]['fid'];
            if (empty($greenFins[$prev]) || empty($greenFins[$next])) continue;

            $best = $this->pickBestMail($mailByAmount[$f['amount']] ?? [], $linkedM, $f['ts'], PHP_INT_MAX);
            if ($best === null) continue;
            $this->links->add(new OutLink(
                mailUid:   $best,
                financeId: $f['fid'],
                linkType:  'auto_green',
                isManual:  false,
            ), $range->to);
            $linkedM[$best]    = true;
            $linkedF[$f['fid']] = true;
            $greenFins[$f['fid']] = true;
            $f['eligible']     = false;
            $added++;
            unset($f);
        }

        // ─── Pass 3: YELLOW (amount, no time window) ───────────────
        foreach ($finance as &$f) {
            if (!$f['eligible']) continue;
            $best = $this->pickBestMail($mailByAmount[$f['amount']] ?? [], $linkedM, $f['ts'], PHP_INT_MAX);
            if ($best === null) continue;
            $this->links->add(new OutLink(
                mailUid:   $best,
                financeId: $f['fid'],
                linkType:  'auto_yellow',
                isManual:  false,
            ), $range->to);
            $linkedM[$best]    = true;
            $linkedF[$f['fid']] = true;
            $f['eligible']     = false;
            $added++;
        }
        unset($f);

        return ['added' => $added, 'total' => count($existing) + $added];
    }

    /**
     * Pick the not-yet-linked mail with the smallest |Δt| from the
     * candidate list (already pre-filtered by amount), within the
     * given window. Returns the mail uid or null.
     */
    private function pickBestMail(array $candidates, array $linkedM, int $finTs, int $windowSeconds): ?int
    {
        $best = null;
        $bestDiff = PHP_INT_MAX;
        foreach ($candidates as $cand) {
            if (isset($linkedM[$cand['uid']])) continue;
            $mt = $cand['ts'];
            if ($mt <= 0) continue;
            $diff = abs($mt - $finTs);
            if ($diff > $windowSeconds) continue;
            if ($diff < $bestDiff) {
                $best     = $cand['uid'];
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

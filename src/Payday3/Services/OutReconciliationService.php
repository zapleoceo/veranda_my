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
 * Auto-match algorithm for OUT-direction (mail → Poster finance):
 *
 *   GREEN  exactly one open mail row and one open finance row with the
 *          same absolute amount on the same calendar day.
 *   YELLOW amounts match but multiple candidates on either side.
 *
 * Mail rows never persist — every autoLink invocation re-fetches IMAP.
 * Finance rows also fetched live. The output edges are written to
 * out_links.
 *
 * Manual / unlink / clearLinks delegate to the repository.
 */
final class OutReconciliationService implements OutReconciliationServiceInterface
{
    public function __construct(
        private readonly MailServiceInterface       $mail,
        private readonly FinanceServiceInterface    $finance,
        private readonly OutLinkRepositoryInterface $links,
    ) {}

    public function autoLink(DateRange $range): array
    {
        $existing = $this->links->listInRange($range);
        $takenM   = [];
        $takenF   = [];
        foreach ($existing as $e) {
            $takenM[$e->mailUid]   = true;
            $takenF[$e->financeId] = true;
        }

        $mails   = array_values(array_filter(
            $this->mail->fetch($range, includeHidden: false),
            static fn($m) => !isset($takenM[$m->mailUid]) && !$m->isHidden,
        ));
        $fins    = array_values(array_filter(
            $this->finance->fetch($range),
            static fn($f) => !isset($takenF[$f->transactionId]),
        ));

        $byMail = [];
        foreach ($mails as $m) {
            $day = substr($m->date, 0, 10);
            $key = $day . '|' . abs($m->amount->amount);
            $byMail[$key][] = $m;
        }
        $byFin = [];
        foreach ($fins as $f) {
            $day = substr($f->date, 0, 10);
            $key = $day . '|' . abs($f->amount->amount);
            $byFin[$key][] = $f;
        }

        $added = 0;
        foreach ($byMail as $key => $mList) {
            if (!isset($byFin[$key])) continue;
            $fList = $byFin[$key];
            $type  = (count($mList) === 1 && count($fList) === 1) ? 'auto_green' : 'auto_yellow';
            $n     = min(count($mList), count($fList));
            for ($i = 0; $i < $n; $i++) {
                $this->links->add(new OutLink(
                    mailUid:   $mList[$i]->mailUid,
                    financeId: $fList[$i]->transactionId,
                    linkType:  $type,
                    isManual:  false,
                ), $range->to);
                $added++;
            }
        }
        return ['added' => $added, 'total' => count($existing) + $added];
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

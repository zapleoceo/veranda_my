<?php

declare(strict_types=1);

namespace App\Payday3\Services;

use App\Infrastructure\Database;
use App\Payday3\Contracts\MailServiceInterface;
use App\Payday3\Domain\DateRange;
use App\Payday3\Domain\MailTransaction;
use App\Payday3\Domain\Money;

/**
 * IMAP-fetch + parse of BIDV outgoing-payment mails.
 *
 * Port of payday2/ajax.php `mail_out` action (~140 lines → 110 here).
 * Connects to imap.gmail.com:993, filters by sender + date, parses
 * the visible HTML body for amount + transaction time. Hidden rows
 * (mail_hidden table) are decorated server-side so the UI can paint
 * them correctly on first render.
 *
 * Requires .env: MAIL_USER, MAIL_PASS. The PHP imap extension must
 * be enabled — without it the service throws RuntimeException.
 */
final class MailImapService implements MailServiceInterface
{
    public function __construct(private readonly Database $db) {}

    /** @return MailTransaction[] */
    public function fetch(DateRange $range, bool $includeHidden = false): array
    {
        if (!extension_loaded('imap')) {
            throw new \RuntimeException('PHP imap extension is not available');
        }
        $user = (string)($_ENV['MAIL_USER'] ?? '');
        $pass = (string)($_ENV['MAIL_PASS'] ?? '');
        if ($user === '' || $pass === '') {
            throw new \RuntimeException('MAIL_USER / MAIL_PASS not configured in .env');
        }

        $inbox = @imap_open('{imap.gmail.com:993/imap/ssl/novalidate-cert}INBOX', $user, $pass);
        if (!$inbox) {
            $err = function_exists('imap_last_error') ? (string)imap_last_error() : '';
            throw new \RuntimeException('IMAP open failed' . ($err !== '' ? ': ' . $err : ''));
        }
        try {
            $fromTs   = strtotime($range->from . ' 00:00:00');
            $toTs     = strtotime($range->to   . ' 23:59:59');
            $beforeTs = strtotime($range->to   . ' +1 day');
            if ($fromTs === false || $toTs === false || $beforeTs === false) {
                throw new \RuntimeException('Bad date range');
            }

            // IMAP-side date filter uses the SERVER's day (Gmail = UTC).
            // We widen the SINCE bound by one day on the lower edge so a
            // VN-local mail from early-morning hours (= late-prev-day UTC)
            // still reaches the post-filter step. The post-filter compares
            // calendar dates from the body where possible, so this widening
            // never produces false-positives on the user-facing side.
            $sinceTs = strtotime($range->from . ' -1 day');
            $query = 'FROM "bidvsmartbanking@bidv.com.vn"'
                   . ' SINCE "'  . date('d-M-Y', $sinceTs)  . '"'
                   . ' BEFORE "' . date('d-M-Y', $beforeTs) . '"';
            $nums = @imap_search($inbox, $query) ?: [];
            rsort($nums);

            $hidden = $this->loadHidden($range->to);
            $rows   = [];
            // 24-hour grace for udate-based filtering — see logic below.
            $graceSec      = 24 * 3600;
            $fromTsGrace   = $fromTs - $graceSec;
            $toTsGrace     = $toTs   + $graceSec;
            foreach ($nums as $num) {
                $h = @imap_headerinfo($inbox, $num);
                if (!$h) continue;
                $fromAddr = isset($h->from[0]) ? ($h->from[0]->mailbox . '@' . $h->from[0]->host) : '';
                if (strcasecmp($fromAddr, 'bidvsmartbanking@bidv.com.vn') !== 0) continue;

                $body = $this->fetchHtmlBody($inbox, $num);
                $src  = preg_replace('/\s+/u', ' ', $body);

                $txTime = ''; $txTs = 0; $bodyDate = '';
                if (preg_match('/\b(\d{2})\/(\d{2})\/(\d{4})\s+(\d{2}):(\d{2}):(\d{2})\b/u', $src, $m)) {
                    $txTime   = $m[0];
                    $txTs     = mktime((int)$m[4], (int)$m[5], (int)$m[6], (int)$m[2], (int)$m[1], (int)$m[3]);
                    $bodyDate = sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);  // 'Y-m-d'
                }
                $amount = 0;
                if (preg_match('/([\d.,]+)\s*VND\b/ui', $src, $m)) {
                    $amount = (int)str_replace([',', '.'], ['', ''], $m[1]);
                }

                // ─── Date filtering — bank-day-aware ─────────────────
                // The bank emits its body timestamp in Vietnam local time
                // ("21/05/2026 …"). When the body parses cleanly we filter
                // by that CALENDAR DATE — TZ-agnostic, matches what the
                // operator sees in the bank's view of the day.
                //
                // When the body has no parseable timestamp (malformed
                // template, image-only email, etc.) we fall back to the
                // IMAP udate (UTC). To avoid losing late-night VN mails
                // whose UTC udate spills into the next/prev day, the
                // udate path uses a 24-hour grace zone around [from, to].
                //
                // Header `udate` is missing on some Gmail accounts —
                // fall back to strtotime($h->date) like payday2 did so
                // we don't lose those mails entirely.
                $udate = isset($h->udate) ? (int)$h->udate
                       : (isset($h->date) ? (int)strtotime($h->date) : 0);

                if ($bodyDate !== '') {
                    if ($bodyDate < $range->from) break;      // sorted desc — earlier mails done
                    if ($bodyDate > $range->to)   continue;
                } else {
                    if ($udate > 0 && $udate < $fromTsGrace) break;
                    if ($udate > 0 && $udate > $toTsGrace)   continue;
                }

                $uid = (int)@imap_uid($inbox, $num);
                if ($uid <= 0) continue;
                $isHidden = isset($hidden[$uid]);
                if ($isHidden && !$includeHidden) continue;

                // For display we prefer txTs (the bank's reported time);
                // fall back to udate when body parsing failed.
                $displayTs = $txTs > 0 ? $txTs : $udate;
                $rows[] = new MailTransaction(
                    mailUid:       $uid,
                    date:          $displayTs > 0 ? date('Y-m-d H:i:s', $displayTs) : '',
                    amount:        Money::vnd($amount),
                    content:       self::decodeHeader($h->subject ?? ''),
                    txTime:        $txTime,
                    isHidden:      $isHidden,
                    hiddenComment: $hidden[$uid] ?? '',
                );
            }
            return $rows;
        } finally {
            @imap_close($inbox);
        }
    }

    public function hide(int $mailUid, string $dateTo, string $comment = ''): void
    {
        $mh = $this->db->t('mail_hidden');
        $this->db->query(
            "INSERT INTO {$mh} (mail_uid, date_to, comment)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE comment = VALUES(comment)",
            [$mailUid, $dateTo, $comment !== '' ? $comment : null]
        );
    }

    /** @return array<int,string> uid → comment */
    private function loadHidden(string $dateTo): array
    {
        $mh = $this->db->t('mail_hidden');
        $hidden = [];
        try {
            $rows = $this->db->query(
                "SELECT mail_uid, comment FROM {$mh} WHERE date_to = ?",
                [$dateTo]
            )->fetchAll();
            foreach ($rows as $r) {
                $uid = (int)($r['mail_uid'] ?? 0);
                if ($uid > 0) $hidden[$uid] = (string)($r['comment'] ?? '');
            }
        } catch (\Throwable $e) {
            // Table not yet created — treat as no hidden rows.
        }
        return $hidden;
    }

    private function fetchHtmlBody($inbox, int $num): string
    {
        $struct = @imap_fetchstructure($inbox, $num);
        $body = ''; $encoding = 0;
        if ($struct && !empty($struct->parts)) {
            foreach ($struct->parts as $i => $p) {
                if (isset($p->subtype) && strtoupper($p->subtype) === 'HTML') {
                    $body = (string)@imap_fetchbody($inbox, $num, $i + 1);
                    $encoding = $p->encoding ?? 0;
                    break;
                }
            }
            if ($body === '') {
                $body = (string)@imap_fetchbody($inbox, $num, 1);
                $encoding = $struct->parts[0]->encoding ?? 0;
            }
        } else {
            $body = (string)@imap_body($inbox, $num);
            $encoding = $struct->encoding ?? 0;
        }
        if ($encoding == 3) $body = base64_decode($body) ?: $body;
        elseif ($encoding == 4) $body = quoted_printable_decode($body);
        return $body;
    }

    private static function decodeHeader(string $s): string
    {
        if ($s === '' || !function_exists('imap_mime_header_decode')) return $s;
        $parts = @imap_mime_header_decode($s);
        if (!is_array($parts)) return $s;
        $out = '';
        foreach ($parts as $p) {
            $text = isset($p->text) ? (string)$p->text : '';
            $charset = isset($p->charset) ? strtolower((string)$p->charset) : 'default';
            if ($charset === 'default' || $charset === 'us-ascii' || $charset === 'utf-8') {
                $out .= $text;
            } else {
                $out .= @mb_convert_encoding($text, 'UTF-8', $charset) ?: $text;
            }
        }
        return $out;
    }
}

<?php

declare(strict_types=1);

namespace App\Payday3\Http\Actions;

use App\Payday3\Contracts\MailServiceInterface;
use App\Payday3\Domain\DateRange;
use App\Payday3\Http\JsonResponder;
use App\Payday3\Http\RequestThrottle;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /payday3/api/out/mail?dateFrom=&dateTo=&include_hidden=0|1
 *
 * IMAP fetch only — split out of the original /out/data so the front
 * end can dispatch it concurrently with /out/finance and /out/links.
 * IMAP is by far the slowest piece (1–3 s on a cold fetch); pulling
 * it out lets the other two return immediately while it's still
 * spinning.
 */
final class OutMailAction
{
    public function __construct(private readonly MailServiceInterface $mail) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $q = $request->getQueryParams();
            $range = DateRange::forRead($q);
            // IMAP is slow; throttle to once-per-5s per session so a
            // double-click doesn't open two parallel IMAP sessions.
            // (guard() releases the session lock before we go to IMAP.)
            RequestThrottle::guard('out-mail', 5);
            $includeHidden = (string)($q['include_hidden'] ?? '') === '1';
            $rows = $this->mail->fetch($range, $includeHidden);
        } catch (\Throwable $e) {
            return JsonResponder::fromException($response, $e, 502);
        }
        return JsonResponder::ok($response, [
            'range' => $range->asArray(),
            'mail'  => JsonResponder::shapes($rows),
        ]);
    }
}

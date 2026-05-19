<?php

declare(strict_types=1);

namespace App\Payday3\Http\Actions;

use App\Payday3\Contracts\MailServiceInterface;
use App\Payday3\Domain\DateRange;
use App\Payday3\Http\JsonResponder;
use App\Payday3\Http\RequestThrottle;
use App\Payday3\Http\TooManyRequestsException;
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
            // IMAP is slow; throttle to once-per-5s per session so a
            // double-click doesn't open two parallel IMAP sessions.
            RequestThrottle::guard('out-mail', 5);
            $q = $request->getQueryParams();
            $range = DateRange::fromQuery($q);
            $includeHidden = (string)($q['include_hidden'] ?? '') === '1';
            $rows = $this->mail->fetch($range, $includeHidden);
        } catch (TooManyRequestsException $e) {
            return JsonResponder::tooManyRequests($response, $e->getMessage(), $e->retryAfter);
        } catch (\InvalidArgumentException $e) {
            return JsonResponder::error($response, $e->getMessage(), 400);
        } catch (\RuntimeException $e) {
            return JsonResponder::error($response, $e->getMessage(), 500);
        }
        return JsonResponder::ok($response, [
            'range' => $range->asArray(),
            'mail'  => array_map(static fn($m) => $m->toJsonShape(), $rows),
        ]);
    }
}

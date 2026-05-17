<?php

declare(strict_types=1);

namespace App\Payday3\Http\Actions;

use App\Payday3\Contracts\MailServiceInterface;
use App\Payday3\Domain\DateRange;
use App\Payday3\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /payday3/api/out/mail/hide
 * Body: { mailUid: int, comment?: string }
 *
 * Marks a BIDV mail row as hidden for the current date_to so it
 * doesn't show up in subsequent OUT fetches.
 */
final class MailHideAction
{
    public function __construct(private readonly MailServiceInterface $mail) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body    = (array)$request->getParsedBody();
        $mailUid = (int)($body['mailUid'] ?? 0);
        $comment = (string)($body['comment'] ?? '');
        if ($mailUid <= 0) {
            return JsonResponder::error($response, 'mailUid required.', 400);
        }
        try {
            $range = DateRange::fromQuery($request->getQueryParams());
            $this->mail->hide($mailUid, $range->to, $comment);
        } catch (\Throwable $e) {
            return JsonResponder::error($response, $e->getMessage(), 500);
        }
        return JsonResponder::ok($response, ['mailUid' => $mailUid]);
    }
}

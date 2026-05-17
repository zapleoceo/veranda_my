<?php

declare(strict_types=1);

namespace App\Payday3\Http\Actions;

use App\Payday3\Contracts\FinanceServiceInterface;
use App\Payday3\Contracts\MailServiceInterface;
use App\Payday3\Contracts\OutLinkRepositoryInterface;
use App\Payday3\Domain\DateRange;
use App\Payday3\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /payday3/api/out/data?dateFrom=&dateTo=&include_hidden=0|1
 *
 * Returns everything the OUT-mode UI needs in one round-trip:
 *   mail:    list of MailTransaction (BIDV outgoing emails)
 *   finance: list of FinanceTransaction (Poster finance txs)
 *   links:   list of OutLink
 *
 * Mail is fetched live from IMAP; finance via Poster API. Both can be
 * slow on first call — front-end shows a spinner.
 */
final class OutDataAction
{
    public function __construct(
        private readonly MailServiceInterface       $mail,
        private readonly FinanceServiceInterface    $finance,
        private readonly OutLinkRepositoryInterface $links,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $q = $request->getQueryParams();
            $range = DateRange::fromQuery($q);
            $includeHidden = (string)($q['include_hidden'] ?? '') === '1';

            $mailRows    = $this->mail->fetch($range, $includeHidden);
            $financeRows = $this->finance->fetch($range);
            $linkRows    = $this->links->listInRange($range);
        } catch (\InvalidArgumentException $e) {
            return JsonResponder::error($response, $e->getMessage(), 400);
        } catch (\RuntimeException $e) {
            return JsonResponder::error($response, $e->getMessage(), 500);
        }
        return JsonResponder::ok($response, [
            'range'   => $range->asArray(),
            'mail'    => array_map(static fn($m) => $m->toJsonShape(), $mailRows),
            'finance' => array_map(static fn($f) => $f->toJsonShape(), $financeRows),
            'links'   => array_map(static fn($l) => $l->toJsonShape(), $linkRows),
        ]);
    }
}

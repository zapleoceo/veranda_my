<?php

declare(strict_types=1);

namespace App\Payday3\Http\Actions;

use App\Payday3\Contracts\OutLinkRepositoryInterface;
use App\Payday3\Contracts\OutReconciliationServiceInterface;
use App\Payday3\Domain\DateRange;
use App\Payday3\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /payday3/api/out/links/manual
 * Body: { mailUids: int[], financeIds: int[] }
 */
final class OutManualLinkAction
{
    public function __construct(
        private readonly OutReconciliationServiceInterface $service,
        private readonly OutLinkRepositoryInterface        $links,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array)$request->getParsedBody();
        $mailUids   = array_values(array_filter(array_map('intval', (array)($body['mailUids']   ?? [])), static fn($i) => $i > 0));
        $financeIds = array_values(array_filter(array_map('intval', (array)($body['financeIds'] ?? [])), static fn($i) => $i > 0));
        if ($mailUids === [] || $financeIds === []) {
            return JsonResponder::error($response, 'Select at least one mail and one finance row.', 400);
        }
        try {
            $range = DateRange::fromQuery($request->getQueryParams());
        } catch (\InvalidArgumentException $e) {
            return JsonResponder::error($response, $e->getMessage(), 400);
        }
        $added = 0;
        foreach ($mailUids as $uid) {
            foreach ($financeIds as $fid) {
                try {
                    $this->service->manualLink($uid, $fid, $range->to);
                    $added++;
                } catch (\Throwable $e) { /* per-pair swallow */ }
            }
        }
        return JsonResponder::ok($response, [
            'added' => $added,
            'links' => array_map(static fn($l) => $l->toJsonShape(), $this->links->listInRange($range)),
        ]);
    }
}

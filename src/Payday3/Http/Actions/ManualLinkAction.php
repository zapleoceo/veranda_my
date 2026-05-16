<?php

declare(strict_types=1);

namespace App\Payday3\Http\Actions;

use App\Payday3\Contracts\LinkRepositoryInterface;
use App\Payday3\Contracts\ReconciliationServiceInterface;
use App\Payday3\Domain\DateRange;
use App\Payday3\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /payday3/api/links/manual
 * Body: { sepayIds: int[], posterIds: int[] }
 *
 * Manual reconciliation: every sepayId is linked to every posterId
 * (cartesian product). Lets the operator pair 1↔1, 1↔N, N↔1 or N↔N
 * from the mid-col 🎯 button.
 */
final class ManualLinkAction
{
    public function __construct(
        private readonly ReconciliationServiceInterface $service,
        private readonly LinkRepositoryInterface        $links,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array)$request->getParsedBody();
        $sepayIds  = array_map('intval', (array)($body['sepayIds']  ?? []));
        $posterIds = array_map('intval', (array)($body['posterIds'] ?? []));
        $sepayIds  = array_values(array_filter($sepayIds,  static fn($i) => $i > 0));
        $posterIds = array_values(array_filter($posterIds, static fn($i) => $i > 0));

        if ($sepayIds === [] || $posterIds === []) {
            return JsonResponder::error($response, 'Select at least one sepay row and one poster row.', 400);
        }

        $added = 0;
        foreach ($sepayIds as $sid) {
            foreach ($posterIds as $pid) {
                try {
                    $this->service->manualLink($sid, $pid);
                    $added++;
                } catch (\Throwable $e) {
                    // Swallow per-pair errors and continue; the client
                    // will reload the link set and see the result.
                }
            }
        }

        $range = DateRange::fromQuery($request->getQueryParams());
        return JsonResponder::ok($response, [
            'added' => $added,
            'links' => array_map(static fn($l) => $l->toJsonShape(), $this->links->listInRange($range)),
        ]);
    }
}

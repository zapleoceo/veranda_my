<?php

declare(strict_types=1);

namespace App\Payday3\Http\Actions;

use App\Payday3\Contracts\LinkRepositoryInterface;
use App\Payday3\Contracts\ReconciliationServiceInterface;
use App\Payday3\Domain\DateRange;
use App\Payday3\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use App\Payday3\Services\ManualLinker;

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
        private readonly ManualLinker                   $linker,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body      = (array)$request->getParsedBody();
        $sepayIds  = ManualLinker::ids($body['sepayIds']  ?? []);
        $posterIds = ManualLinker::ids($body['posterIds'] ?? []);
        if ($sepayIds === [] || $posterIds === []) {
            return JsonResponder::error($response, 'Select at least one sepay row and one poster row.', 400);
        }

        try {
            $range  = DateRange::forRead($request->getQueryParams());
            $result = $this->linker->link($sepayIds, $posterIds,
                fn(int $sid, int $pid) => $this->service->manualLink($sid, $pid));
            $links  = JsonResponder::shapes($this->links->listInRange($range));
        } catch (\Throwable $e) {
            return JsonResponder::fromException($response, $e);
        }
        // `errors` lists per-pair failures (previously swallowed → "added 0").
        return JsonResponder::ok($response, [
            'added'  => $result['added'],
            'errors' => $result['errors'],
            'links'  => $links,
        ]);
    }
}

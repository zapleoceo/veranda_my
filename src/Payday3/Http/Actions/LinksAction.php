<?php

declare(strict_types=1);

namespace App\Payday3\Http\Actions;

use App\Payday3\Contracts\LinkRepositoryInterface;
use App\Payday3\Domain\DateRange;
use App\Payday3\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /payday3/api/links?dateFrom=YYYY-MM-DD&dateTo=YYYY-MM-DD
 *
 * Returns the current sepay↔poster reconciliation edges for the
 * requested window. Used by the JS line renderer on initial load
 * and after any mutation (auto/manual/unlink).
 */
final class LinksAction
{
    public function __construct(private readonly LinkRepositoryInterface $links) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $range = DateRange::fromQuery($request->getQueryParams());
        } catch (\InvalidArgumentException $e) {
            return JsonResponder::error($response, $e->getMessage(), 400);
        }

        $edges = $this->links->listInRange($range);
        $payload = array_map(static fn($l) => $l->toJsonShape(), $edges);

        return JsonResponder::ok($response, ['links' => $payload, 'range' => $range->asArray()]);
    }
}

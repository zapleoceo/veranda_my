<?php

declare(strict_types=1);

namespace App\Payday3\Http\Actions;

use App\Payday3\Contracts\OutLinkRepositoryInterface;
use App\Payday3\Domain\DateRange;
use App\Payday3\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /payday3/api/out/links?dateFrom=&dateTo=
 *
 * Just the persisted mail↔finance link rows for the range — split
 * out of /out/data so the JS can fetch it in parallel with mail
 * and finance. Cheap DB query, no API calls.
 */
final class OutLinksAction
{
    public function __construct(private readonly OutLinkRepositoryInterface $links) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $range = DateRange::fromQuery($request->getQueryParams());
            $rows  = $this->links->listInRange($range);
        } catch (\InvalidArgumentException $e) {
            return JsonResponder::error($response, $e->getMessage(), 400);
        } catch (\RuntimeException $e) {
            return JsonResponder::error($response, $e->getMessage(), 500);
        }
        return JsonResponder::ok($response, [
            'range' => $range->asArray(),
            'links' => array_map(static fn($l) => $l->toJsonShape(), $rows),
        ]);
    }
}

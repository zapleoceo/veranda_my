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
            $range = DateRange::forRead($request->getQueryParams());
            $links = JsonResponder::shapes($this->links->listInRange($range));
        } catch (\Throwable $e) {
            return JsonResponder::fromException($response, $e);
        }
        return JsonResponder::ok($response, ['links' => $links, 'range' => $range->asArray()]);
    }
}

<?php

declare(strict_types=1);

namespace App\Payday3\Http\Actions;

use App\Payday3\Contracts\PosterCheckServiceInterface;
use App\Payday3\Domain\DateRange;
use App\Payday3\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /payday3/api/poster/checks?dateFrom=&dateTo=&limit=200
 *
 * Recent checks list for the Check-Finder modal. Renders on modal
 * open so the operator picks visually instead of typing
 * transaction_ids.
 */
final class PosterCheckListAction
{
    public function __construct(private readonly PosterCheckServiceInterface $service) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $q     = $request->getQueryParams();
        $limit = (int)($q['limit'] ?? 200);
        try {
            $range = DateRange::fromQuery($q);
            $rows  = $this->service->listRecent($range, $limit > 0 ? $limit : 200);
        } catch (\Throwable $e) {
            return JsonResponder::error($response, $e->getMessage(), 500);
        }
        return JsonResponder::ok($response, ['checks' => $rows]);
    }
}

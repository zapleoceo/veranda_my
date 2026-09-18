<?php

declare(strict_types=1);

namespace App\Payday3\Http\Actions;

use App\Payday3\Contracts\ReconciliationServiceInterface;
use App\Payday3\Domain\DateRange;
use App\Payday3\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /payday3/api/links/clear?dateFrom=...&dateTo=...
 *
 * Drops every reconciliation edge in the date window (used by the
 * ⛓️‍💥 button after the operator has reviewed the situation and
 * wants to start over).
 */
final class ClearLinksAction
{
    public function __construct(private readonly ReconciliationServiceInterface $service) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $range   = DateRange::forDestructive($request->getQueryParams());
            $removed = $this->service->clearLinks($range);
        } catch (\Throwable $e) {
            return JsonResponder::fromException($response, $e);
        }
        return JsonResponder::ok($response, ['removed' => $removed, 'links' => []]);
    }
}

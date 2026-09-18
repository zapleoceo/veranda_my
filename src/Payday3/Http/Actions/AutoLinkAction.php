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
 * POST /payday3/api/links/auto?dateFrom=...&dateTo=...
 *
 * Runs the auto-matcher over the requested window. Returns the fresh
 * link set so the client can hand it straight to LineRenderer.setLinks().
 */
final class AutoLinkAction
{
    public function __construct(
        private readonly ReconciliationServiceInterface $service,
        private readonly LinkRepositoryInterface        $links,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $range  = DateRange::forRead($request->getQueryParams());
            $result = $this->service->autoLink($range);
            $links  = JsonResponder::shapes($this->links->listInRange($range));
        } catch (\Throwable $e) {
            return JsonResponder::fromException($response, $e);
        }
        return JsonResponder::ok($response, [
            'added' => $result['added'],
            'total' => $result['total'],
            'links' => $links,
        ]);
    }
}

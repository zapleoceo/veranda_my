<?php

declare(strict_types=1);

namespace App\Payday3\Http\Actions;

use App\Payday3\Contracts\OutLinkRepositoryInterface;
use App\Payday3\Contracts\OutReconciliationServiceInterface;
use App\Payday3\Domain\DateRange;
use App\Payday3\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** POST /payday3/api/out/links/auto?dateFrom=&dateTo= */
final class OutAutoLinkAction
{
    public function __construct(
        private readonly OutReconciliationServiceInterface $service,
        private readonly OutLinkRepositoryInterface        $links,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $range = DateRange::fromQuery($request->getQueryParams());
            $r     = $this->service->autoLink($range);
        } catch (\Throwable $e) {
            return JsonResponder::error($response, $e->getMessage(), 500);
        }
        return JsonResponder::ok($response, [
            'added' => $r['added'],
            'total' => $r['total'],
            'links' => array_map(static fn($l) => $l->toJsonShape(), $this->links->listInRange($range)),
        ]);
    }
}

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
            $range = DateRange::forRead($request->getQueryParams());
            $r     = $this->service->autoLink($range);
            $links = JsonResponder::shapes($this->links->listInRange($range));
        } catch (\Throwable $e) {
            return JsonResponder::fromException($response, $e);
        }
        return JsonResponder::ok($response, [
            'added' => $r['added'],
            'total' => $r['total'],
            'links' => $links,
        ]);
    }
}

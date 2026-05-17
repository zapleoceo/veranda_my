<?php

declare(strict_types=1);

namespace App\Payday3\Http\Actions;

use App\Payday3\Contracts\OutReconciliationServiceInterface;
use App\Payday3\Domain\DateRange;
use App\Payday3\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** POST /payday3/api/out/links/clear?dateFrom=&dateTo= */
final class OutClearLinksAction
{
    public function __construct(private readonly OutReconciliationServiceInterface $service) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $range = DateRange::fromQuery($request->getQueryParams());
        } catch (\InvalidArgumentException $e) {
            return JsonResponder::error($response, $e->getMessage(), 400);
        }
        $removed = $this->service->clearLinks($range);
        return JsonResponder::ok($response, ['removed' => $removed, 'links' => []]);
    }
}

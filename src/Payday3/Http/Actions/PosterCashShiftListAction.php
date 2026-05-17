<?php

declare(strict_types=1);

namespace App\Payday3\Http\Actions;

use App\Payday3\Contracts\PosterCashShiftServiceInterface;
use App\Payday3\Domain\DateRange;
use App\Payday3\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /payday3/api/poster/cashshifts?dateFrom=&dateTo= */
final class PosterCashShiftListAction
{
    public function __construct(private readonly PosterCashShiftServiceInterface $service) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $range = DateRange::fromQuery($request->getQueryParams());
            $rows  = $this->service->list($range);
        } catch (\Throwable $e) {
            return JsonResponder::error($response, $e->getMessage(), 500);
        }
        return JsonResponder::ok($response, ['shifts' => $rows, 'range' => $range->asArray()]);
    }
}

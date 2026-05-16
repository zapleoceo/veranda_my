<?php

declare(strict_types=1);

namespace App\Payday3\Http\Actions;

use App\Payday3\Contracts\DayResetServiceInterface;
use App\Payday3\Domain\DateRange;
use App\Payday3\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /payday3/api/day/clear?dateFrom=...&dateTo=...
 *
 * Soft-resets the selected period — the legacy clearDayBtn in the
 * payday2 mid-col. The next sepay/poster sync re-creates the rows.
 */
final class ClearDayAction
{
    public function __construct(private readonly DayResetServiceInterface $reset) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $range  = DateRange::fromQuery($request->getQueryParams());
            $result = $this->reset->softReset($range);
        } catch (\InvalidArgumentException $e) {
            return JsonResponder::error($response, $e->getMessage(), 400);
        }
        return JsonResponder::ok($response, [
            'range'  => $range->asArray(),
            'sepay'  => $result['sepay'],
            'poster' => $result['poster'],
        ]);
    }
}

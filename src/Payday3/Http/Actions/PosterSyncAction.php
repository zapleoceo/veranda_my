<?php

declare(strict_types=1);

namespace App\Payday3\Http\Actions;

use App\Payday3\Contracts\PosterSyncServiceInterface;
use App\Payday3\Domain\DateRange;
use App\Payday3\Http\JsonResponder;
use App\Payday3\Http\RequestThrottle;
use App\Payday3\Http\TooManyRequestsException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /payday3/api/poster/sync?dateFrom=...&dateTo=...
 *
 * Calls Poster's API and refreshes poster_checks for the selected
 * range. Front-end shows a spinner on the 📧 button and reloads the
 * page when this returns so the freshly-synced rows appear.
 */
final class PosterSyncAction
{
    public function __construct(private readonly PosterSyncServiceInterface $service) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            RequestThrottle::guard('poster-sync', 10);
            $range  = DateRange::fromQuery($request->getQueryParams());
            $result = $this->service->sync($range);
        } catch (TooManyRequestsException $e) {
            return JsonResponder::tooManyRequests($response, $e->getMessage(), $e->retryAfter);
        } catch (\InvalidArgumentException $e) {
            return JsonResponder::error($response, $e->getMessage(), 400);
        } catch (\RuntimeException $e) {
            return JsonResponder::error($response, $e->getMessage(), 500);
        }

        return JsonResponder::ok($response, [
            'range'    => $range->asArray(),
            'inserted' => $result['inserted'],
            'updated'  => $result['updated'],
            'skipped'  => $result['skipped'],
            'methods'  => $result['methods'],
        ]);
    }
}

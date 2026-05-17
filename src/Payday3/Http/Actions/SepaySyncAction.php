<?php

declare(strict_types=1);

namespace App\Payday3\Http\Actions;

use App\Payday3\Contracts\SepaySyncServiceInterface;
use App\Payday3\Domain\DateRange;
use App\Payday3\Http\JsonResponder;
use App\Payday3\Http\RequestThrottle;
use App\Payday3\Http\TooManyRequestsException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /payday3/api/sepay/sync?dateFrom=...&dateTo=...
 *
 * Pulls fresh bank transactions out of SePay's REST API and writes
 * them to sepay_transactions. Triggered by the 📧 button on the
 * Sepay table card. The front-end reloads the page when this returns
 * so new rows show up alongside the existing data.
 */
final class SepaySyncAction
{
    public function __construct(private readonly SepaySyncServiceInterface $service) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            RequestThrottle::guard('sepay-sync', 10);
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
            'apiRows'  => $result['apiRows'],
        ]);
    }
}

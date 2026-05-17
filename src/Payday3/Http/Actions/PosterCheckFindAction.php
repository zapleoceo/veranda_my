<?php

declare(strict_types=1);

namespace App\Payday3\Http\Actions;

use App\Payday3\Contracts\PosterCheckServiceInterface;
use App\Payday3\Domain\DateRange;
use App\Payday3\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /payday3/api/poster/checks/find?id=&dateFrom=&dateTo= */
final class PosterCheckFindAction
{
    public function __construct(private readonly PosterCheckServiceInterface $service) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $q   = $request->getQueryParams();
        $id  = (int)($q['id'] ?? $q['transaction_id'] ?? 0);
        try {
            $range = DateRange::fromQuery($q);
            $res   = $this->service->find($id, $range);
        } catch (\InvalidArgumentException $e) {
            return JsonResponder::error($response, $e->getMessage(), 400);
        } catch (\Throwable $e) {
            return JsonResponder::error($response, $e->getMessage(), 500);
        }
        return JsonResponder::ok($response, $res);
    }
}

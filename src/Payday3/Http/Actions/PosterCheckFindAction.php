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
            // ≤ 31 days: find() pages through Poster (up to 50×1000 rows).
            $range = DateRange::forRead($q);
            $res   = $this->service->find($id, $range);
        } catch (\Throwable $e) {
            return JsonResponder::fromException($response, $e, 502);
        }
        return JsonResponder::ok($response, $res);
    }
}

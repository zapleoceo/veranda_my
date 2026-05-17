<?php

declare(strict_types=1);

namespace App\Payday3\Http\Actions;

use App\Payday3\Contracts\PosterBalanceServiceInterface;
use App\Payday3\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /payday3/api/poster/balances
 *
 * Current Poster balances for the three configured accounts
 * (Andrey / Vietnam / Cash) plus the computed total. The "Итоговый
 * баланс" footer card uses this as the "Poster" column.
 */
final class PosterBalanceSnapshotAction
{
    public function __construct(private readonly PosterBalanceServiceInterface $service) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try { $snap = $this->service->snapshot(); }
        catch (\Throwable $e) { return JsonResponder::error($response, $e->getMessage(), 500); }
        return JsonResponder::ok($response, $snap);
    }
}

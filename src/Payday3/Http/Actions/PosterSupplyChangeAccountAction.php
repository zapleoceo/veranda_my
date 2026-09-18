<?php

declare(strict_types=1);

namespace App\Payday3\Http\Actions;

use App\Payday3\Contracts\PosterSuppliesServiceInterface;
use App\Payday3\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /payday3/api/poster/supplies/account
 * Body: { supply_id: int, account_id: int }
 */
final class PosterSupplyChangeAccountAction
{
    public function __construct(private readonly PosterSuppliesServiceInterface $service) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array)$request->getParsedBody();
        $supplyId  = (int)($body['supply_id']  ?? 0);
        $accountId = (int)($body['account_id'] ?? 0);
        try {
            $resp = $this->service->changeAccount($supplyId, $accountId);
        } catch (\Throwable $e) {
            return JsonResponder::fromException($response, $e, 502);
        }
        return JsonResponder::ok($response, ['response' => $resp]);
    }
}

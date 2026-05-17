<?php

declare(strict_types=1);

namespace App\Payday3\Http\Actions;

use App\Payday3\Contracts\BalanceSyncServiceInterface;
use App\Payday3\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /payday3/api/balances/sync/commit
 *
 * Body: { "nonce": "<hex>" }
 * Returns: { ok:true } or { ok:true, already:true } when the matching
 * transaction was already present in today's Poster finance feed.
 *
 * Step 2 of the UPLD flow — validates the nonce stashed by /plan and
 * fires the actual finance.createTransactions call.
 */
final class BalanceSyncCommitAction
{
    public function __construct(private readonly BalanceSyncServiceInterface $service) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body    = (string)$request->getBody();
        $payload = json_decode($body, true);
        if (!is_array($payload)) $payload = [];
        $nonce = (string)($payload['nonce'] ?? '');

        try {
            $result = $this->service->commit($nonce);
        } catch (\InvalidArgumentException $e) {
            return JsonResponder::error($response, $e->getMessage(), 400);
        } catch (\RuntimeException $e) {
            return JsonResponder::error($response, $e->getMessage(), 500);
        }
        return JsonResponder::ok($response, $result);
    }
}

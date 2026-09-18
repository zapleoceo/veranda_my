<?php

declare(strict_types=1);

namespace App\Payday3\Http\Actions;

use App\Payday3\Contracts\BalanceSyncServiceInterface;
use App\Payday3\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use App\Payday3\Http\CurrentUser;

/**
 * POST /payday3/api/balances/sync/plan
 *
 * Body: { "diff_vnd": <signed int> }
 * Returns: { nonce, plan: {type, account_id, account_name, sum, comment, ...} }
 *
 * Step 1 of the UPLD flow — the operator sees the plan in a confirm
 * dialog client-side, then POSTs the nonce back to /commit.
 */
final class BalanceSyncPlanAction
{
    public function __construct(private readonly BalanceSyncServiceInterface $service) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body    = (string)$request->getBody();
        $payload = json_decode($body, true);
        if (!is_array($payload)) $payload = [];

        // diff_vnd from the browser is only a cross-check now — the
        // service recomputes Факт. − Poster itself.
        $diffVnd    = (int)($payload['diff_vnd'] ?? 0);
        $targetDate = isset($payload['target_date']) ? (string)$payload['target_date'] : null;

        try {
            $result = $this->service->plan($diffVnd, CurrentUser::email(), $targetDate);
        } catch (\Throwable $e) {
            return JsonResponder::fromException($response, $e);
        }
        return JsonResponder::ok($response, $result);
    }
}

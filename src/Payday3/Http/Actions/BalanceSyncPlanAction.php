<?php

declare(strict_types=1);

namespace App\Payday3\Http\Actions;

use App\Payday3\Contracts\BalanceSyncServiceInterface;
use App\Payday3\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

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

        $diffVnd = (int)($payload['diff_vnd'] ?? 0);
        // Operator label for the audit trail comment — same shape as
        // payday2 (" by <email>").
        $by = trim((string)($_SESSION['user_email'] ?? $_SESSION['user_name'] ?? ''));

        try {
            $result = $this->service->plan($diffVnd, $by);
        } catch (\InvalidArgumentException $e) {
            return JsonResponder::error($response, $e->getMessage(), 400);
        } catch (\RuntimeException $e) {
            return JsonResponder::error($response, $e->getMessage(), 500);
        }
        return JsonResponder::ok($response, $result);
    }
}

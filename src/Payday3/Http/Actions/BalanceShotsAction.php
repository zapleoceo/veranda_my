<?php

declare(strict_types=1);

namespace App\Payday3\Http\Actions;

use App\Payday3\Http\JsonResponder;
use App\Payday3\Services\BalanceShotStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /payday3/api/balances/shots
 *   → every saved balance screenshot, newest first, plus the latest one
 *     (the one the 08:00 daily send will post).
 */
final class BalanceShotsAction
{
    public function __construct(private readonly BalanceShotStore $store) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return JsonResponder::ok($response, [
            'items'  => $this->store->list(),
            'latest' => $this->store->latest()['name'] ?? null,
        ]);
    }
}

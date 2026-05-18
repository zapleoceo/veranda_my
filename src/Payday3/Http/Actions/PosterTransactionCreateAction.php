<?php

declare(strict_types=1);

namespace App\Payday3\Http\Actions;

use App\Payday3\Contracts\PosterTransactionCreateServiceInterface;
use App\Payday3\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /payday3/api/poster/finance/transactions
 *
 * Body (JSON): {type, amount, date, account_from?, account_to?,
 *               category_id?, comment?}
 *
 * Powers the "+" popup on OUT-mail rows. Validation + Poster API
 * call live in PosterTransactionCreateService — this action just
 * unpacks the body and maps exceptions to HTTP status codes.
 */
final class PosterTransactionCreateAction
{
    public function __construct(private readonly PosterTransactionCreateServiceInterface $service) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $payload = $request->getParsedBody();
        if (!is_array($payload)) {
            $raw     = (string)$request->getBody();
            $payload = json_decode($raw, true);
        }
        if (!is_array($payload)) {
            return JsonResponder::error($response, 'Invalid JSON', 400);
        }
        try {
            $result = $this->service->create($payload);
        } catch (\InvalidArgumentException $e) {
            return JsonResponder::error($response, $e->getMessage(), 400);
        } catch (\RuntimeException $e) {
            return JsonResponder::error($response, $e->getMessage(), 502);
        }
        return JsonResponder::ok($response, $result);
    }
}

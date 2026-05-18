<?php

declare(strict_types=1);

namespace App\Payday3\Http\Actions;

use App\Payday3\Contracts\FinanceTransferServiceInterface;
use App\Payday3\Domain\DateRange;
use App\Payday3\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /payday3/api/finance/transfers/create
 *
 * Body (JSON): { kind: "vietnam" | "tips", dateFrom, dateTo }
 *
 * Powers the "Создать" buttons in the Финансовые транзакции
 * card. Idempotent — service walks today's finance.getTransactions
 * for a matching row first and short-circuits with `already:true`
 * if found.
 */
final class FinanceTransferCreateAction
{
    public function __construct(private readonly FinanceTransferServiceInterface $service) {}

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
        $kind = (string)($payload['kind'] ?? '');
        try {
            $range = DateRange::fromQuery([
                'dateFrom' => (string)($payload['dateFrom'] ?? ''),
                'dateTo'   => (string)($payload['dateTo']   ?? ''),
            ]);
            $by = trim((string)($_SESSION['user_email'] ?? $_SESSION['user_name'] ?? ''));
            $result = $this->service->createTransfer($kind, $range, $by);
        } catch (\InvalidArgumentException $e) {
            return JsonResponder::error($response, $e->getMessage(), 400);
        } catch (\RuntimeException $e) {
            return JsonResponder::error($response, $e->getMessage(), 502);
        }
        return JsonResponder::ok($response, $result);
    }
}

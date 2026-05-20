<?php

declare(strict_types=1);

namespace App\Order\Http\Actions;

use App\Order\Contracts\OrdersServiceInterface;
use App\Order\Domain\CartLine;
use App\Order\Http\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /neworder/api/orders/append
 *
 * Appends cart items to an existing open transaction. Body shape:
 *   { spot_id, spot_tablet_id, transaction_id, comment, items: [...] }
 *
 * Items have the same shape as POST /orders. Guarded by CsrfMiddleware.
 */
final class OrderAppendAction
{
    public function __construct(private readonly OrdersServiceInterface $orders) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $b = $this->readJson($request);
        if ($b === null) return JsonResponse::error($response, 'Bad JSON', 400);

        $items = [];
        foreach (($b['items'] ?? []) as $row) {
            if (!is_array($row)) continue;
            $items[] = CartLine::fromInput($row);
        }
        if (!$items) return JsonResponse::error($response, 'Корзина пуста', 400);

        try {
            $r = $this->orders->appendToTransaction(
                spotId:        (int)($b['spot_id']        ?? 0),
                tabletId:      (int)($b['spot_tablet_id'] ?? 0),
                transactionId: (int)($b['transaction_id'] ?? 0),
                comment:       (string)($b['comment']     ?? ''),
                lines:         $items,
            );
        } catch (\InvalidArgumentException $e) {
            return JsonResponse::error($response, $e->getMessage(), 400);
        } catch (\Throwable $e) {
            return JsonResponse::error($response, $e->getMessage(), 502);
        }

        return JsonResponse::ok($response, $r);
    }

    private function readJson(ServerRequestInterface $request): ?array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed) && $parsed) return $parsed;
        $raw = (string)$request->getBody();
        if ($raw === '') return null;
        $j = json_decode($raw, true);
        return is_array($j) ? $j : null;
    }
}

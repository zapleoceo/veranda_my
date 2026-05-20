<?php

declare(strict_types=1);

namespace App\Order\Http\Actions;

use App\Order\Contracts\OpenChecksProviderInterface;
use App\Order\Domain\OpenCheck;
use App\Order\Http\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /neworder/api/open-checks?spot_id=&table_id=
 * Lists open checks (status=1, service_mode=1) on the selected table.
 * Triggered from the cart UI whenever the operator changes the table
 * dropdown so we can offer "add to existing check" inline.
 */
final class OpenChecksAction
{
    public function __construct(private readonly OpenChecksProviderInterface $provider) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $q       = $request->getQueryParams();
        $spotId  = (int)($q['spot_id']  ?? 0);
        $tableId = (int)($q['table_id'] ?? 0);
        if ($spotId <= 0 || $tableId <= 0) {
            return JsonResponse::error($response, 'spot_id и table_id обязательны', 400);
        }

        try {
            $checks = $this->provider->fetchForTable($spotId, $tableId);
        } catch (\Throwable $e) {
            return JsonResponse::error($response, 'Poster: ' . $e->getMessage(), 502);
        }

        return JsonResponse::ok($response, [
            'checks' => array_map(fn(OpenCheck $c) => $c->toJson(), $checks),
        ]);
    }
}

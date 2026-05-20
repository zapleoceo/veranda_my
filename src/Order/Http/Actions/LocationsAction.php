<?php

declare(strict_types=1);

namespace App\Order\Http\Actions;

use App\Order\Contracts\PosterLocationProviderInterface;
use App\Order\Domain\Hall;
use App\Order\Domain\Spot;
use App\Order\Domain\TableDef;
use App\Order\Http\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /neworder/api/locations — full spot → hall → table tree.
 *
 * Fetched once on page load; cached client-side. Tables almost never
 * change at runtime, so re-fetching only happens on a manual refresh.
 */
final class LocationsAction
{
    public function __construct(private readonly PosterLocationProviderInterface $locations) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $tree = $this->locations->fetchAll();
        } catch (\Throwable $e) {
            return JsonResponse::error($response, 'Poster: ' . $e->getMessage(), 502);
        }

        return JsonResponse::ok($response, [
            'spots'  => array_map(fn(Spot     $s) => $s->toJson(), $tree['spots']),
            'halls'  => array_map(fn(Hall     $h) => $h->toJson(), $tree['halls']),
            'tables' => array_map(fn(TableDef $t) => $t->toJson(), $tree['tables']),
        ]);
    }
}

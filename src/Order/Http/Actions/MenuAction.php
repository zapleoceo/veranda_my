<?php

declare(strict_types=1);

namespace App\Order\Http\Actions;

use App\Order\Contracts\PosterMenuProviderInterface;
use App\Order\Domain\Category;
use App\Order\Domain\MenuItem;
use App\Order\Http\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /neworder/api/menu — live Poster menu snapshot.
 *
 * No DB cache. The page fetches once on load; the operator can hit
 * the refresh button to refetch. Returns categories + active products
 * with their modifier groups + add-on modifications.
 */
final class MenuAction
{
    public function __construct(private readonly PosterMenuProviderInterface $menu) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $categories = $this->menu->fetchCategories();
            $products   = $this->menu->fetchActiveProducts();
        } catch (\Throwable $e) {
            return JsonResponse::error($response, 'Poster: ' . $e->getMessage(), 502);
        }

        return JsonResponse::ok($response, [
            'categories' => array_map(fn(Category $c) => $c->toJson(), $categories),
            'products'   => array_map(fn(MenuItem $p) => $p->toJson(), $products),
        ]);
    }
}

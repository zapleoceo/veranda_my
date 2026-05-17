<?php

declare(strict_types=1);

namespace App\Payday3\Http\Actions;

use App\Payday3\Contracts\PosterLookupServiceInterface;
use App\Payday3\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /payday3/api/poster/finance/categories */
final class PosterFinanceCategoriesAction
{
    public function __construct(private readonly PosterLookupServiceInterface $lookup) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try { $rows = $this->lookup->financeCategories(); }
        catch (\Throwable $e) { return JsonResponder::error($response, $e->getMessage(), 500); }
        return JsonResponder::ok($response, ['categories' => $rows]);
    }
}

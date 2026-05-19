<?php

declare(strict_types=1);

namespace App\Payday3\Http\Actions;

use App\Payday3\Contracts\FinanceServiceInterface;
use App\Payday3\Domain\DateRange;
use App\Payday3\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /payday3/api/out/finance?dateFrom=&dateTo=
 *
 * Poster finance.getTransactions for the Andrey/Tips accounts only —
 * split out of /out/data so the JS can dispatch it concurrently with
 * /out/mail and /out/links.
 */
final class OutFinanceAction
{
    public function __construct(private readonly FinanceServiceInterface $finance) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $range = DateRange::fromQuery($request->getQueryParams());
            $rows  = $this->finance->fetch($range);
        } catch (\InvalidArgumentException $e) {
            return JsonResponder::error($response, $e->getMessage(), 400);
        } catch (\RuntimeException $e) {
            return JsonResponder::error($response, $e->getMessage(), 500);
        }
        return JsonResponder::ok($response, [
            'range'   => $range->asArray(),
            'finance' => array_map(static fn($f) => $f->toJsonShape(), $rows),
        ]);
    }
}

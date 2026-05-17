<?php

declare(strict_types=1);

namespace App\Payday3\Http\Actions;

use App\Payday3\Contracts\ActualBalanceRepositoryInterface;
use App\Payday3\Domain\ActualBalances;
use App\Payday3\Domain\Money;
use App\Payday3\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET  /payday3/api/balances?date=YYYY-MM-DD
 *      → latest snapshot at or before that date, or empty record.
 * POST /payday3/api/balances
 *      Body { target_date, bal_andrey?, bal_vietnam?, bal_cash?, bal_total? }
 *      → inserts a new snapshot.
 *
 * Both verbs handled by one action — keeps the single-action-per-file
 * rule for read+write of the same resource.
 */
final class ActualBalanceAction
{
    public function __construct(private readonly ActualBalanceRepositoryInterface $repo) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($request->getMethod() === 'POST') return $this->save($request, $response);
        return $this->load($request, $response);
    }

    private function load(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $date = (string)($request->getQueryParams()['date'] ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return JsonResponder::error($response, 'Invalid date.', 400);
        }
        $bal = $this->repo->latestFor($date);
        return JsonResponder::ok($response, $bal?->toJsonShape() ?? [
            'target_date' => $date,
            'bal_andrey'  => null, 'bal_vietnam' => null,
            'bal_cash'    => null, 'bal_total'   => null,
        ]);
    }

    private function save(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array)$request->getParsedBody();
        $date = (string)($body['target_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return JsonResponder::error($response, 'target_date is required (YYYY-MM-DD).', 400);
        }
        $bal = new ActualBalances(
            targetDate: $date,
            andrey:     self::optionalMoney($body['bal_andrey']  ?? null),
            vietnam:    self::optionalMoney($body['bal_vietnam'] ?? null),
            cash:       self::optionalMoney($body['bal_cash']    ?? null),
            total:      self::optionalMoney($body['bal_total']   ?? null),
        );
        $id = $this->repo->save($bal);
        return JsonResponder::ok($response, ['id' => $id, 'balances' => $bal->toJsonShape()]);
    }

    private static function optionalMoney(mixed $v): ?Money
    {
        if ($v === null || $v === '' || $v === false) return null;
        return Money::parse($v);
    }
}

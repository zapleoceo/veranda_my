<?php

declare(strict_types=1);

namespace App\Payday3\Http\Actions;

use App\Payday3\Contracts\IncomeFinanceLinkRepositoryInterface;
use App\Payday3\Contracts\IncomeFinanceReconciliationServiceInterface;
use App\Payday3\Domain\DateRange;
use App\Payday3\Http\JsonResponder;
use App\Payday3\Services\ManualLinker;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * /payday3/api/income-links — incoming bank row (SePay) ↔ Poster finance
 * income. Same verbs and response shape as the /out/links endpoints:
 * every mutation answers with the fresh link set of the range.
 *
 *   GET    /income-links?dateFrom&dateTo            → {links}
 *   POST   /income-links/auto                       → {added, total, links}
 *   POST   /income-links/manual {sepayIds, financeIds} → {added, links}
 *   DELETE /income-links/{sepayId}/{financeId}      → {links}
 *   POST   /income-links/clear                      → {removed, links}
 */
final class IncomeLinksController
{
    public function __construct(
        private readonly IncomeFinanceReconciliationServiceInterface $service,
        private readonly IncomeFinanceLinkRepositoryInterface        $links,
        private readonly ManualLinker                                $linker,
    ) {}

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->respond($request, $response, static fn() => []);
    }

    public function auto(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->respond($request, $response, fn(DateRange $r) => $this->service->autoLink($r));
    }

    public function manual(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body       = (array)$request->getParsedBody();
        $sepayIds   = ManualLinker::ids($body['sepayIds']   ?? []);
        $financeIds = ManualLinker::ids($body['financeIds'] ?? []);
        if ($sepayIds === [] || $financeIds === []) {
            return JsonResponder::error($response, 'Select at least one bank row and one Poster transaction.', 400);
        }
        return $this->respond($request, $response, fn(DateRange $r) => $this->linker->link(
            $sepayIds, $financeIds,
            fn(int $sid, int $fid) => $this->service->manualLink($sid, $fid, $r->to),
        ));
    }

    /** @param array{sepayId:string, financeId:string} $args */
    public function unlink(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->respond($request, $response, function () use ($args) {
            $this->service->unlink((int)$args['sepayId'], (int)$args['financeId']);
            return [];
        });
    }

    public function clear(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->respond($request, $response,
            fn(DateRange $r) => ['removed' => $this->service->clearLinks($r)],
            DateRange::MAX_DESTRUCTIVE_DAYS);
    }

    /**
     * Shared envelope: parse the range, run the operation, answer with
     * its extra fields + the current link set of the range.
     *
     * @param callable(DateRange): array<string,mixed> $op
     */
    private function respond(ServerRequestInterface $request, ResponseInterface $response, callable $op, int $maxDays = DateRange::MAX_READ_DAYS): ResponseInterface
    {
        try {
            $range = DateRange::fromQuery($request->getQueryParams())->limitedTo($maxDays);
            $extra = $op($range);
            $links = JsonResponder::shapes($this->links->listInRange($range));
        } catch (\Throwable $e) {
            return JsonResponder::fromException($response, $e);
        }
        return JsonResponder::ok($response, $extra + ['links' => $links]);
    }
}

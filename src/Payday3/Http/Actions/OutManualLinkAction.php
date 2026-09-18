<?php

declare(strict_types=1);

namespace App\Payday3\Http\Actions;

use App\Payday3\Contracts\OutLinkRepositoryInterface;
use App\Payday3\Contracts\OutReconciliationServiceInterface;
use App\Payday3\Domain\DateRange;
use App\Payday3\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use App\Payday3\Services\ManualLinker;

/**
 * POST /payday3/api/out/links/manual
 * Body: { mailUids: int[], financeIds: int[] }
 */
final class OutManualLinkAction
{
    public function __construct(
        private readonly OutReconciliationServiceInterface $service,
        private readonly OutLinkRepositoryInterface        $links,
        private readonly ManualLinker                      $linker,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body       = (array)$request->getParsedBody();
        $mailUids   = ManualLinker::ids($body['mailUids']   ?? []);
        $financeIds = ManualLinker::ids($body['financeIds'] ?? []);
        if ($mailUids === [] || $financeIds === []) {
            return JsonResponder::error($response, 'Select at least one mail and one finance row.', 400);
        }
        try {
            $range  = DateRange::forRead($request->getQueryParams());
            $result = $this->linker->link($mailUids, $financeIds,
                fn(int $uid, int $fid) => $this->service->manualLink($uid, $fid, $range->to));
            $links  = JsonResponder::shapes($this->links->listInRange($range));
        } catch (\Throwable $e) {
            return JsonResponder::fromException($response, $e);
        }
        return JsonResponder::ok($response, [
            'added'  => $result['added'],
            'errors' => $result['errors'],
            'links'  => $links,
        ]);
    }
}

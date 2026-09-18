<?php

declare(strict_types=1);

namespace App\Payday3\Http\Actions;

use App\Payday3\Contracts\OutLinkRepositoryInterface;
use App\Payday3\Contracts\OutReconciliationServiceInterface;
use App\Payday3\Domain\DateRange;
use App\Payday3\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** DELETE /payday3/api/out/links/{mailUid}/{financeId} */
final class OutUnlinkAction
{
    public function __construct(
        private readonly OutReconciliationServiceInterface $service,
        private readonly OutLinkRepositoryInterface        $links,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $mailUid   = (int)($args['mailUid']   ?? 0);
        $financeId = (int)($args['financeId'] ?? 0);
        if ($mailUid <= 0 || $financeId <= 0) {
            return JsonResponder::error($response, 'Invalid ids.', 400);
        }
        try {
            $this->service->unlink($mailUid, $financeId);
            $range = DateRange::forRead($request->getQueryParams());
            $links = JsonResponder::shapes($this->links->listInRange($range));
        } catch (\Throwable $e) {
            return JsonResponder::fromException($response, $e);
        }
        return JsonResponder::ok($response, ['links' => $links]);
    }
}

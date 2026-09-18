<?php

declare(strict_types=1);

namespace App\Payday3\Http\Actions;

use App\Payday3\Contracts\LinkRepositoryInterface;
use App\Payday3\Contracts\ReconciliationServiceInterface;
use App\Payday3\Domain\DateRange;
use App\Payday3\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * DELETE /payday3/api/links/{sepayId}/{posterId}
 *
 * Bound to the "×" close-button on each rendered SVG connector.
 */
final class UnlinkAction
{
    public function __construct(
        private readonly ReconciliationServiceInterface $service,
        private readonly LinkRepositoryInterface        $links,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $sid = (int)($args['sepayId']  ?? 0);
        $pid = (int)($args['posterId'] ?? 0);
        if ($sid <= 0 || $pid <= 0) {
            return JsonResponder::error($response, 'Invalid ids.', 400);
        }
        try {
            $this->service->unlink($sid, $pid);
            $range = DateRange::forRead($request->getQueryParams());
            $links = JsonResponder::shapes($this->links->listInRange($range));
        } catch (\Throwable $e) {
            return JsonResponder::fromException($response, $e);
        }
        return JsonResponder::ok($response, ['links' => $links]);
    }
}

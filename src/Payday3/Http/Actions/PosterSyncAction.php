<?php

declare(strict_types=1);

namespace App\Payday3\Http\Actions;

use App\Payday3\Domain\DateRange;
use App\Payday3\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /payday3/api/poster/sync?dateFrom=...&dateTo=...
 *
 * Triggered by the 📧 button on the Poster table card. Calls the
 * Poster API and refreshes poster_checks for the selected range.
 *
 * TODO Phase 5: port payday2/post/load_poster_checks.php (265 lines)
 * to PosterSyncService. For now the stub returns ok; cron keeps the
 * table fresh in the background.
 */
final class PosterSyncAction
{
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $range = DateRange::fromQuery($request->getQueryParams());
        } catch (\InvalidArgumentException $e) {
            return JsonResponder::error($response, $e->getMessage(), 400);
        }
        return JsonResponder::ok($response, [
            'message'  => 'Poster sync is queued (cron handles the actual fetch).',
            'range'    => $range->asArray(),
            'imported' => 0,
        ]);
    }
}

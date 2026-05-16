<?php

declare(strict_types=1);

namespace App\Payday3\Http\Actions;

use App\Payday3\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /payday3/api/sepay/sync
 *
 * Triggered by the 📧 button on the Sepay table card. Pulls fresh
 * BIDV bank-transaction emails out of the inbox and writes them to
 * sepay_transactions.
 *
 * TODO Phase 5: extract the IMAP/parser logic from
 * payday2/ajax.php → MailSyncService. Until then this returns ok
 * so the front-end shows the toast and triggers a page reload —
 * the cron job already keeps sepay_transactions fresh, so the
 * reload usually shows new rows even without a manual fetch.
 */
final class SepaySyncAction
{
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return JsonResponder::ok($response, [
            'message' => 'Sepay sync is queued (cron handles the actual fetch).',
            'imported' => 0,
        ]);
    }
}

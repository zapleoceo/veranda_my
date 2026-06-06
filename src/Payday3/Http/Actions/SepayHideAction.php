<?php

declare(strict_types=1);

namespace App\Payday3\Http\Actions;

use App\Payday3\Contracts\SepayRepositoryInterface;
use App\Payday3\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /payday3/api/sepay/hide
 * Body: { sepayId: int, hidden?: bool, comment?: string }
 *
 * Toggle endpoint — when `hidden` is true (default) the sepay row is
 * INSERT-IGNOREd into sepay_hidden; when false the row is removed
 * (restore). The single-row hide button on the IN-mode SePay table
 * calls this; the listHiddenInRange query then surfaces the row again
 * via the eye-toggle. Direct port of payday2's ?ajax=sepay_hide.
 */
final class SepayHideAction
{
    public function __construct(private readonly SepayRepositoryInterface $sepay) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body    = (array)$request->getParsedBody();
        $sepayId = (int)($body['sepayId'] ?? 0);
        $hidden  = array_key_exists('hidden', $body) ? (bool)$body['hidden'] : true;
        $comment = trim((string)($body['comment'] ?? ''));
        if ($sepayId <= 0) {
            return JsonResponder::error($response, 'sepayId required.', 400);
        }
        try {
            if ($hidden) {
                $this->sepay->hide($sepayId, $comment);
            } else {
                $this->sepay->unhide($sepayId);
            }
        } catch (\Throwable $e) {
            return JsonResponder::error($response, $e->getMessage(), 500);
        }
        return JsonResponder::ok($response, [
            'sepayId' => $sepayId,
            'hidden'  => $hidden,
        ]);
    }
}

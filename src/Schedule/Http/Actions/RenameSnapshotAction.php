<?php

declare(strict_types=1);

namespace App\Schedule\Http\Actions;

use App\Schedule\Http\JsonResponder;
use App\Schedule\Services\PageLockService;
use App\Schedule\Services\ScheduleStateService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /schedule?ajax=rename_snap
 *   body: { id: N, label: "new name" }
 *
 * Rename an existing named version. Refuses to rename the current draft.
 */
final class RenameSnapshotAction
{
    public function __construct(
        private readonly ScheduleStateService $service,
        private readonly PageLockService      $lock,
        private readonly JsonResponder        $json,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($request->getMethod() !== 'POST') {
            return $this->json->fail($response, 'POST required', 405);
        }
        $actor     = (string) ($_SESSION['user_email'] ?? '');
        $actorName = (string) ($_SESSION['user_name']  ?? $actor);
        $blocker = $this->lock->claimForWrite($actor, $actorName);
        if ($blocker !== null) {
            return $this->json->locked($response, $blocker);
        }
        $body  = json_decode((string) $request->getBody(), true);
        $id    = (int) ($body['id'] ?? 0);
        $label = trim((string) ($body['label'] ?? ''));
        if ($id <= 0)     return $this->json->fail($response, 'id required', 400);
        if ($label === '') return $this->json->fail($response, 'label required', 400);

        $ok = $this->service->renameSnapshot($id, $label);
        return $this->json->write($response, [
            'ok'        => $ok,
            'snapshots' => $this->service->listSnapshots(),
            'error'     => $ok ? null : 'cannot rename (not found or current draft)',
        ], $ok ? 200 : 409);
    }
}

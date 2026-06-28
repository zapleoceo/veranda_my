<?php

declare(strict_types=1);

namespace App\Schedule\Http\Actions;

use App\Schedule\Http\JsonResponder;
use App\Schedule\Services\PageLockService;
use App\Schedule\Services\ScheduleStateService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** POST /schedule?ajax=del_zone — soft-delete a custom zone. */
final class DeleteZoneAction
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
        $body = json_decode((string) $request->getBody(), true);
        $id   = (int) ($body['id'] ?? 0);
        if ($id <= 0) return $this->json->fail($response, 'id required', 400);

        $this->service->deleteZone($id);
        return $this->json->ok($response, ['zones' => $this->service->listZones()]);
    }
}

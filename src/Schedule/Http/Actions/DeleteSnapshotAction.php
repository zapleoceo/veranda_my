<?php

declare(strict_types=1);

namespace App\Schedule\Http\Actions;

use App\Schedule\Http\JsonResponder;
use App\Schedule\Services\ScheduleStateService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** POST /schedule?ajax=del_snap — remove old snapshot (current is protected). */
final class DeleteSnapshotAction
{
    public function __construct(
        private readonly ScheduleStateService $service,
        private readonly JsonResponder        $json,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($request->getMethod() !== 'POST') {
            return $this->json->fail($response, 'POST required', 405);
        }
        $body = json_decode((string) $request->getBody(), true);
        $id   = (int) ($body['id'] ?? 0);
        if ($id <= 0) return $this->json->fail($response, 'id required', 400);

        $ok = $this->service->deleteSnapshot($id);
        return $this->json->write($response, [
            'ok'        => $ok,
            'snapshots' => $this->service->listSnapshots(),
            'error'     => $ok ? null : 'cannot delete current snapshot',
        ], $ok ? 200 : 409);
    }
}

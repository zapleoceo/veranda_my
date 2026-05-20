<?php

declare(strict_types=1);

namespace App\Schedule\Http\Actions;

use App\Schedule\Http\JsonResponder;
use App\Schedule\Services\ScheduleStateService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /schedule?ajax=snapshot&id=N */
final class LoadSnapshotAction
{
    public function __construct(
        private readonly ScheduleStateService $service,
        private readonly JsonResponder        $json,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $id = (int) ($request->getQueryParams()['id'] ?? 0);
        if ($id <= 0) return $this->json->fail($response, 'id required', 400);

        $state = $this->service->loadSnapshot($id);
        if ($state === null) return $this->json->fail($response, 'snapshot not found', 404);

        return $this->json->ok($response, ['state' => $state]);
    }
}

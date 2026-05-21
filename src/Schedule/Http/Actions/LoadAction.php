<?php

declare(strict_types=1);

namespace App\Schedule\Http\Actions;

use App\Schedule\Http\JsonResponder;
use App\Schedule\Services\ScheduleStateService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /schedule?ajax=load — full bootstrap payload for the JS app. */
final class LoadAction
{
    public function __construct(
        private readonly ScheduleStateService $service,
        private readonly JsonResponder        $json,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $loaded = $this->service->loadCurrent();
        return $this->json->ok($response, [
            'state'      => $loaded['state'],
            'state_ver'  => (int) ($loaded['version'] ?? 0),
            'employees'  => $this->service->fetchEmployees(),
            'halls'      => $this->service->fetchHalls(),
            'zones'      => $this->service->listZones(),
            'snapshots'  => $this->service->listSnapshots(),
        ]);
    }
}

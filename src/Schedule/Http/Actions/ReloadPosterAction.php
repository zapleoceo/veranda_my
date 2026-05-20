<?php

declare(strict_types=1);

namespace App\Schedule\Http\Actions;

use App\Schedule\Http\JsonResponder;
use App\Schedule\Services\ScheduleStateService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /schedule?ajax=reload_poster — drop Poster caches and refetch. */
final class ReloadPosterAction
{
    public function __construct(
        private readonly ScheduleStateService $service,
        private readonly JsonResponder        $json,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->service->purgePosterCache();
        return $this->json->ok($response, [
            'employees' => $this->service->fetchEmployees(),
            'halls'     => $this->service->fetchHalls(),
        ]);
    }
}

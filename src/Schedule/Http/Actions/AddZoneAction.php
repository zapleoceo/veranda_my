<?php

declare(strict_types=1);

namespace App\Schedule\Http\Actions;

use App\Schedule\Http\JsonResponder;
use App\Schedule\Services\PageLockService;
use App\Schedule\Services\ScheduleStateService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** POST /schedule?ajax=add_zone — create a custom zone (Беседка, Терраса …). */
final class AddZoneAction
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
        if (!$this->lock->isOwner((string) ($_SESSION['user_email'] ?? ''))) {
            return $this->json->locked($response, $this->lock->current());
        }
        $body = json_decode((string) $request->getBody(), true);
        $name = trim((string) ($body['name'] ?? ''));
        $icon = trim((string) ($body['icon'] ?? '🌿')) ?: '🌿';
        if ($name === '') return $this->json->fail($response, 'name required', 400);

        $id = $this->service->addZone($name, $icon);
        return $this->json->ok($response, [
            'id'    => $id,
            'zones' => $this->service->listZones(),
        ]);
    }
}

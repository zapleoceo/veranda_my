<?php

declare(strict_types=1);

namespace App\Schedule\Http\Actions;

use App\Schedule\Http\JsonResponder;
use App\Schedule\Services\PageLockService;
use App\Schedule\Services\ScheduleStateService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /schedule?ajax=save_version
 *   body: { state: {...}, label: "..." }
 *
 * Creates a NEW named version row. The current draft is untouched —
 * versions live alongside it and the user can return to one later.
 * Empty label is rejected (use plain `ajax=save` for drafts).
 */
final class SaveVersionAction
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
        if (!is_array($body) || !isset($body['state']) || !is_array($body['state'])) {
            return $this->json->fail($response, 'Bad payload: state required', 400);
        }
        $label = trim((string) ($body['label'] ?? ''));
        if ($label === '') {
            return $this->json->fail($response, 'Имя версии обязательно', 400);
        }
        try {
            $id = $this->service->saveNamedVersion(
                $body['state'],
                $label,
                (string) ($_SESSION['user_email'] ?? ''),
            );
            return $this->json->ok($response, [
                'id'        => $id,
                'snapshots' => $this->service->listSnapshots(),
            ]);
        } catch (\Throwable $e) {
            return $this->json->fail($response, $e->getMessage(), 500);
        }
    }
}

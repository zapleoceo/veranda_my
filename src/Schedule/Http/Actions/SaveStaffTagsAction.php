<?php

declare(strict_types=1);

namespace App\Schedule\Http\Actions;

use App\Schedule\Http\JsonResponder;
use App\Schedule\Services\PageLockService;
use App\Schedule\Services\ScheduleStateService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** POST /schedule?ajax=save_staff_tags — batch upsert of staff tags. */
final class SaveStaffTagsAction
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
        $tags = is_array($body['tags'] ?? null) ? $body['tags'] : null;
        if (!$tags) return $this->json->fail($response, 'tags array required', 400);

        // Read session here — the HTTP-layer (Action) owns auth context,
        // not the service. Pass the actor down as a value.
        $actor = (string) ($_SESSION['user_email'] ?? $_SESSION['user_name'] ?? '');
        foreach ($tags as $t) {
            $uid = (int) ($t['user_id'] ?? 0);
            if ($uid <= 0) continue;
            $this->service->saveStaffTag($uid, [
                'in_schedule'    => (bool) ($t['in_schedule']    ?? true),
                'can_be_senior'  => (bool) ($t['can_be_senior']  ?? false),
                'only_in_blocks' => (string) ($t['only_in_blocks'] ?? ''),
                'custom_tag'     => (string) ($t['custom_tag']     ?? ''),
                'rate_per_hour'  => (int)    ($t['rate_per_hour']  ?? 0),
            ], $actor);
        }
        return $this->json->ok($response, ['employees' => $this->service->fetchEmployees()]);
    }
}

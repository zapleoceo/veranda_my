<?php

declare(strict_types=1);

namespace App\Schedule\Http\Actions;

use App\Schedule\Http\JsonResponder;
use App\Schedule\Services\PageLockService;
use App\Schedule\Services\ScheduleStateService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** POST /schedule?ajax=save — overwrite the current draft (no new version). */
final class SaveAction
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
        // Free lock → reclaim & proceed; only blocked if someone else is live.
        $blocker = $this->lock->claimForWrite($actor, $actorName);
        if ($blocker !== null) {
            return $this->json->locked($response, $blocker);
        }
        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body) || !isset($body['state']) || !is_array($body['state'])) {
            return $this->json->fail($response, 'Bad payload: state required', 400);
        }
        try {
            // Optional client-supplied version. When present, the repo
            // rejects with conflict if the stored row has been bumped
            // by someone else in the meantime — prevents silent
            // overwriting of another operator's edits.
            $expectedVersion = array_key_exists('version', $body)
                ? (int) $body['version']
                : null;

            $result = $this->service->saveCurrent(
                $body['state'],
                (string) ($_SESSION['user_email'] ?? ''),
                $expectedVersion,
            );

            if (!empty($result['conflict'])) {
                // 409: client must reload (or merge). Includes the live
                // state + new version so the client can re-sync without
                // a second round trip.
                return $this->json->write($response, [
                    'ok'       => false,
                    'conflict' => true,
                    'error'    => 'Кто-то другой сохранил график. Обновите страницу, чтобы увидеть актуальную версию.',
                    'version'  => $result['version'],
                    'state'    => $result['state'],
                ], 409);
            }

            // No `snapshots` in the response — draft-save doesn't touch
            // the named-version list. The frontend already keeps its
            // local App.snapshots in sync; this saves one SELECT + a
            // JSON serialization per autosave (fires after every cell
            // edit). Only save_version / rename_snap / del_snap need
            // to return the refreshed list.
            return $this->json->ok($response, [
                'id'      => $result['id'],
                'version' => $result['version'],
            ]);
        } catch (\Throwable $e) {
            return $this->json->fail($response, $e->getMessage(), 500);
        }
    }
}

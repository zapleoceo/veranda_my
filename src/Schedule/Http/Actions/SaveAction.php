<?php

declare(strict_types=1);

namespace App\Schedule\Http\Actions;

use App\Schedule\Http\JsonResponder;
use App\Schedule\Services\ScheduleStateService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** POST /schedule?ajax=save — overwrite the current draft (no new version). */
final class SaveAction
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
        if (!is_array($body) || !isset($body['state']) || !is_array($body['state'])) {
            return $this->json->fail($response, 'Bad payload: state required', 400);
        }
        try {
            $id = $this->service->saveCurrent(
                $body['state'],
                (string) ($_SESSION['user_email'] ?? ''),
            );
            // No `snapshots` in the response — draft-save doesn't touch
            // the named-version list. The frontend already keeps its
            // local App.snapshots in sync; this saves one SELECT + a
            // JSON serialization per autosave (fires after every cell
            // edit). Only save_version / rename_snap / del_snap need
            // to return the refreshed list.
            return $this->json->ok($response, ['id' => $id]);
        } catch (\Throwable $e) {
            return $this->json->fail($response, $e->getMessage(), 500);
        }
    }
}

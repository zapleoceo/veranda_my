<?php

declare(strict_types=1);

namespace App\Schedule\Http\Actions;

use App\Schedule\Http\JsonResponder;
use App\Schedule\Services\PageLockService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /schedule?ajax=lock_heartbeat
 *   Refresh the lock's updated_at so it doesn't time out (60s TTL).
 *   Same response shape as lock_acquire — if another operator has
 *   stolen the lock between heartbeats (because mine expired), the
 *   client will see owned:false and downgrade to read-only.
 */
final class LockHeartbeatAction
{
    public function __construct(
        private readonly PageLockService $lock,
        private readonly JsonResponder   $json,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($request->getMethod() !== 'POST') {
            return $this->json->fail($response, 'POST required', 405);
        }
        $email = (string) ($_SESSION['user_email'] ?? '');
        $name  = (string) ($_SESSION['user_name']  ?? $email);
        if ($email === '') {
            return $this->json->fail($response, 'Auth required', 401);
        }
        $r = $this->lock->heartbeat($email, $name);
        return $this->json->ok($response, [
            'owned' => $r['owned'],
            'lock'  => $r['lock'],
            'ttl'   => PageLockService::TTL_SECONDS,
        ]);
    }
}

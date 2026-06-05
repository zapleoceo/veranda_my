<?php

declare(strict_types=1);

namespace App\Schedule\Http\Actions;

use App\Schedule\Http\JsonResponder;
use App\Schedule\Services\PageLockService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /schedule?ajax=lock_acquire
 *   Claim (or re-claim if mine) the page-edit lock. Returns:
 *     { ok:true, owned:bool, lock:{email,name,acquired_at}|null, ttl:int }
 *   `owned:false` means another operator holds the active lock — JS
 *   should switch to read-only and show the banner.
 */
final class LockAcquireAction
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
        // body { force: 1 } → "Перехватить": take over a lock held by a
        // forgotten/open tab. Otherwise the normal claim-if-free-or-mine.
        $body  = json_decode((string) $request->getBody(), true);
        $force = is_array($body) && !empty($body['force']);
        $r = $force
            ? $this->lock->steal($email, $name)
            : $this->lock->acquire($email, $name);
        return $this->json->ok($response, [
            'owned' => $r['owned'],
            'lock'  => $r['lock'],
            'ttl'   => PageLockService::TTL_SECONDS,
        ]);
    }
}

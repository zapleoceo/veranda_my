<?php

declare(strict_types=1);

namespace App\Schedule\Http\Actions;

use App\Schedule\Http\JsonResponder;
use App\Schedule\Services\PageLockService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /schedule?ajax=lock_release
 *   Drop the lock if I hold it. Called from `beforeunload` via
 *   navigator.sendBeacon — best-effort; if it doesn't arrive, the
 *   60-second heartbeat TTL takes over anyway.
 */
final class LockReleaseAction
{
    public function __construct(
        private readonly PageLockService $lock,
        private readonly JsonResponder   $json,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // Allow GET so sendBeacon (which only sends POST with text/plain)
        // and image-pings both work in a pinch. Mutation is idempotent.
        $email = (string) ($_SESSION['user_email'] ?? '');
        if ($email !== '') $this->lock->release($email);
        return $this->json->ok($response, []);
    }
}

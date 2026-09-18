<?php

declare(strict_types=1);

namespace App\Payday3\Http;

use App\Infrastructure\Session;
use App\Payday3\Contracts\SessionStoreInterface;

/**
 * $_SESSION-backed store that holds the session file lock only for the
 * duration of a single get/set/remove.
 *
 * AuthMiddleware releases the lock for /payday3 so parallel XHRs don't
 * queue; a service that re-opened the session and then called Poster
 * (2–10 s) used to block every other request of the same operator for
 * that long. Here each operation reopens, touches one key and — unless
 * the session was already open when we got here — closes again.
 */
final class PhpSessionStore implements SessionStoreInterface
{
    public function get(string $key): mixed
    {
        return $this->locked(static fn() => $_SESSION[$key] ?? null);
    }

    public function set(string $key, mixed $value): void
    {
        $this->locked(static function () use ($key, $value): void { $_SESSION[$key] = $value; });
    }

    public function remove(string $key): void
    {
        $this->locked(static function () use ($key): void { unset($_SESSION[$key]); });
    }

    private function locked(callable $fn): mixed
    {
        $wasActive = session_status() === PHP_SESSION_ACTIVE;
        Session::start();
        try {
            return $fn();
        } finally {
            if (!$wasActive) Session::close();
        }
    }
}

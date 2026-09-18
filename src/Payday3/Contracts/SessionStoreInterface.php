<?php

declare(strict_types=1);

namespace App\Payday3\Contracts;

/**
 * Minimal key/value view of the user's session, so services (balance-sync
 * nonce, Poster lookup caches) don't touch $_SESSION directly and can be
 * unit-tested with an in-memory fake.
 */
interface SessionStoreInterface
{
    public function get(string $key): mixed;

    public function set(string $key, mixed $value): void;

    public function remove(string $key): void;
}

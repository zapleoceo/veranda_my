<?php

declare(strict_types=1);

namespace App\Payday3\Domain;

/**
 * Who performs a mutating payday3 operation — used for audit rows and
 * for per-session idempotency fingerprints. Built once in the HTTP layer
 * (CurrentUser::actor()) so services never read $_SESSION themselves.
 */
final class Actor
{
    public function __construct(
        public readonly string $email,
        /** Opaque, non-reversible session identifier (hash of session_id). */
        public readonly string $sessionKey = '',
    ) {}

    public function label(): string
    {
        return $this->email !== '' ? $this->email : '—';
    }
}

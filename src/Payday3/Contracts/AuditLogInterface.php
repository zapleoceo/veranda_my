<?php

declare(strict_types=1);

namespace App\Payday3\Contracts;

/**
 * Server-side audit trail for money-moving / destructive payday3
 * operations (table payday_audit_log). Unlike the Telegram note, its
 * destination can't be changed from the Settings modal.
 */
interface AuditLogInterface
{
    /**
     * @param array<string,mixed> $payload  JSON-encoded as-is
     * @param string|null         $fingerprint  idempotency key (sha256 hex) or null
     */
    public function record(string $userEmail, string $action, array $payload, ?string $fingerprint = null): void;

    /** True when a row with this fingerprint was written in the last $seconds. */
    public function existsRecent(string $fingerprint, int $seconds): bool;
}

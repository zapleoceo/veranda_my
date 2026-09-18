<?php

declare(strict_types=1);

namespace Tests\Unit\Payday3\Fakes;

use App\Payday3\Contracts\AuditLogInterface;

final class InMemoryAuditLog implements AuditLogInterface
{
    /** @var list<array{email:string, action:string, payload:array, fingerprint:?string}> */
    public array $rows = [];

    public function record(string $userEmail, string $action, array $payload, ?string $fingerprint = null): void
    {
        $this->rows[] = ['email' => $userEmail, 'action' => $action, 'payload' => $payload, 'fingerprint' => $fingerprint];
    }

    public function existsRecent(string $fingerprint, int $seconds): bool
    {
        foreach ($this->rows as $r) {
            if ($r['fingerprint'] === $fingerprint) return true;
        }
        return false;
    }
}

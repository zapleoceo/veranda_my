<?php

declare(strict_types=1);

namespace App\PosterApp\Domain;

/**
 * One row in `neworder_employee_pin`. PIN is stored as a bcrypt hash
 * so even with DB access an attacker can't trivially extract the
 * plain-text PINs that Poster's POS widget exposed to us via the
 * userLogin event (`user.posPass`).
 *
 * displayName + isAdmin are cached from the same event payload so
 * the admin timesheet UI can render employee rows without an extra
 * round-trip to access.getEmployees.
 */
final class EmployeePin
{
    public function __construct(
        public readonly int      $posterUserId,
        public readonly string   $pinHash,
        public readonly string   $displayName,
        public readonly bool     $isAdmin,
        public readonly ?string  $lastSeenAt,   // 'Y-m-d H:i:s' or null
    ) {}

    public static function fromRow(array $r): self
    {
        return new self(
            posterUserId: (int)($r['poster_user_id']  ?? 0),
            pinHash:      (string)($r['pin_hash']     ?? ''),
            displayName:  (string)($r['display_name'] ?? ''),
            isAdmin:      (int)($r['is_admin']        ?? 0) === 1,
            lastSeenAt:   isset($r['last_seen_at']) && $r['last_seen_at'] !== null
                ? (string)$r['last_seen_at']
                : null,
        );
    }

    public function verifyPin(string $candidate): bool
    {
        if ($this->pinHash === '' || $candidate === '') return false;
        return password_verify($candidate, $this->pinHash);
    }
}

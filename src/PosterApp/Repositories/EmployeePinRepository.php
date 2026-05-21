<?php

declare(strict_types=1);

namespace App\PosterApp\Repositories;

use App\Infrastructure\Database;
use App\PosterApp\Contracts\EmployeePinRepositoryInterface;
use App\PosterApp\Domain\EmployeePin;
use App\PosterApp\Infrastructure\Schema;

final class EmployeePinRepository implements EmployeePinRepositoryInterface
{
    public function __construct(
        private readonly Database $db,
        private readonly Schema   $schema,
    ) {
        // Auto-ensure DDL on first use — same pattern as Schedule repos.
        $this->schema->ensure();
    }

    public function learn(int $posterUserId, string $pinPlain, string $displayName, bool $isAdmin): void
    {
        if ($posterUserId <= 0 || $pinPlain === '') return;
        $hash = password_hash($pinPlain, PASSWORD_BCRYPT, ['cost' => 10]);
        if ($hash === false) return;

        $t = $this->db->t('neworder_employee_pin');
        $this->db->query(
            "INSERT INTO {$t} (poster_user_id, pin_hash, display_name, is_admin, last_seen_at)
             VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                pin_hash     = VALUES(pin_hash),
                display_name = VALUES(display_name),
                is_admin     = VALUES(is_admin),
                last_seen_at = VALUES(last_seen_at)",
            [$posterUserId, $hash, $displayName, $isAdmin ? 1 : 0],
        );
    }

    public function find(int $posterUserId): ?EmployeePin
    {
        if ($posterUserId <= 0) return null;
        $t = $this->db->t('neworder_employee_pin');
        $row = $this->db->query(
            "SELECT poster_user_id, pin_hash, display_name, is_admin, last_seen_at
             FROM {$t} WHERE poster_user_id = ? LIMIT 1",
            [$posterUserId],
        )->fetch();
        return $row ? EmployeePin::fromRow($row) : null;
    }

    /**
     * Linear scan with bcrypt verify per row. With a typical staff size
     * of ~20 employees and bcrypt cost=10 (~100ms) the worst-case latency
     * is ~2s — fine for an interactive PIN entry. The caller MUST
     * rate-limit attempts to make brute-force impractical.
     */
    public function findByPin(string $pinPlain): ?EmployeePin
    {
        if ($pinPlain === '') return null;
        $t = $this->db->t('neworder_employee_pin');
        $rows = $this->db->query(
            "SELECT poster_user_id, pin_hash, display_name, is_admin, last_seen_at
             FROM {$t}",
        )->fetchAll();
        foreach ($rows ?: [] as $r) {
            $emp = EmployeePin::fromRow($r);
            if ($emp->verifyPin($pinPlain)) return $emp;
        }
        return null;
    }

    public function touchLastSeen(int $posterUserId): void
    {
        if ($posterUserId <= 0) return;
        $t = $this->db->t('neworder_employee_pin');
        $this->db->query(
            "UPDATE {$t} SET last_seen_at = NOW() WHERE poster_user_id = ?",
            [$posterUserId],
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Schedule\Repositories;

use App\Infrastructure\Database;
use App\Schedule\Contracts\EmployeeRateRepositoryInterface;
use App\Schedule\Infrastructure\SchemaManager;

/**
 * Reads & writes the `employee_rates` table — same table the /employees/
 * page (employees/Model.php::saveRate) uses. Owning a copy here keeps the
 * Schedule module free of cross-namespace dependencies while sharing the
 * canonical store.
 *
 * Schema is created by whichever module boots first.
 */
final class EmployeeRateRepository implements EmployeeRateRepositoryInterface
{
    private const TABLE = 'employee_rates';

    public function __construct(
        private readonly Database $db,
        SchemaManager $schema,
    ) {
        $schema->ensure();
    }

    public function all(): array
    {
        $t   = $this->db->t(self::TABLE);
        $rows = $this->db->query("SELECT user_id, rate FROM {$t}")->fetchAll();
        $out  = [];
        foreach ($rows ?: [] as $r) {
            $uid = (int) ($r['user_id'] ?? 0);
            if ($uid <= 0) continue;
            $out[$uid] = (int) ($r['rate'] ?? 0);
        }
        return $out;
    }

    public function save(int $userId, int $rateVndPerHour, ?string $by = null): void
    {
        if ($userId <= 0) return;
        $t = $this->db->t(self::TABLE);
        $this->db->query(
            "INSERT INTO {$t} (user_id, rate, updated_by) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE rate = VALUES(rate), updated_by = VALUES(updated_by)",
            [$userId, max(0, $rateVndPerHour), ($by !== null && $by !== '') ? $by : null]
        );
    }

}

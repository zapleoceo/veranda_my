<?php

declare(strict_types=1);

namespace App\Schedule\Repositories;

use App\Infrastructure\Database;
use App\Schedule\Contracts\StaffTagRepositoryInterface;
use App\Schedule\Infrastructure\SchemaManager;

final class StaffTagRepository implements StaffTagRepositoryInterface
{
    private const TABLE = 'schedule_staff_tags';

    public function __construct(
        private readonly Database $db,
        SchemaManager $schema,
    ) {
        $schema->ensure();
    }

    public function all(): array
    {
        $t = $this->db->t(self::TABLE);
        $rows = $this->db->query(
            "SELECT user_id, in_schedule, can_be_senior, only_in_blocks, custom_tag FROM {$t}"
        )->fetchAll();
        $out = [];
        foreach ($rows ?: [] as $r) {
            $out[(int) $r['user_id']] = [
                'in_schedule'    => (bool) (int) ($r['in_schedule'] ?? 1),
                'can_be_senior'  => (bool) (int) ($r['can_be_senior'] ?? 0),
                'only_in_blocks' => (string) ($r['only_in_blocks'] ?? ''),
                'custom_tag'     => (string) ($r['custom_tag'] ?? ''),
            ];
        }
        return $out;
    }

    public function save(int $userId, array $tag): void
    {
        $t = $this->db->t(self::TABLE);
        $this->db->query(
            "INSERT INTO {$t} (user_id, in_schedule, can_be_senior, only_in_blocks, custom_tag)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                in_schedule    = VALUES(in_schedule),
                can_be_senior  = VALUES(can_be_senior),
                only_in_blocks = VALUES(only_in_blocks),
                custom_tag     = VALUES(custom_tag),
                updated_at     = CURRENT_TIMESTAMP",
            [
                $userId,
                (int) (bool) ($tag['in_schedule']    ?? 1),
                (int) (bool) ($tag['can_be_senior']  ?? 0),
                mb_substr((string) ($tag['only_in_blocks'] ?? ''), 0, 255, 'UTF-8'),
                mb_substr((string) ($tag['custom_tag']     ?? ''), 0, 50,  'UTF-8'),
            ]
        );
    }

}

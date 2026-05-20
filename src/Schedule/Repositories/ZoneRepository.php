<?php

declare(strict_types=1);

namespace App\Schedule\Repositories;

use App\Infrastructure\Database;
use App\Schedule\Contracts\ZoneRepositoryInterface;
use App\Schedule\Infrastructure\SchemaManager;

final class ZoneRepository implements ZoneRepositoryInterface
{
    private const TABLE = 'schedule_zones';

    public function __construct(
        private readonly Database $db,
        SchemaManager $schema,
    ) {
        $schema->ensure();
    }

    public function listActive(): array
    {
        $t = $this->db->t(self::TABLE);
        $rows = $this->db->query(
            "SELECT id, name, icon, sort_order, is_active
             FROM {$t} WHERE is_active = 1 ORDER BY sort_order, id"
        )->fetchAll();
        return array_map(static fn($r) => [
            'id'         => (int) $r['id'],
            'name'       => (string) $r['name'],
            'icon'       => (string) ($r['icon'] ?? '🌿'),
            'sort_order' => (int) ($r['sort_order'] ?? 0),
        ], $rows ?: []);
    }

    public function add(string $name, string $icon = '🌿'): int
    {
        $t = $this->db->t(self::TABLE);
        $this->db->query(
            "INSERT INTO {$t} (name, icon, is_active) VALUES (?, ?, 1)",
            [mb_substr($name, 0, 100, 'UTF-8'), mb_substr($icon, 0, 20, 'UTF-8')]
        );
        return (int) $this->db->lastInsertId();
    }

    public function softDelete(int $id): void
    {
        $t = $this->db->t(self::TABLE);
        $this->db->query("UPDATE {$t} SET is_active = 0 WHERE id = ?", [$id]);
    }
}

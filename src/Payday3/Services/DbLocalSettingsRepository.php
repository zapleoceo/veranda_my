<?php

declare(strict_types=1);

namespace App\Payday3\Services;

use App\Infrastructure\Database;
use App\Payday3\Contracts\LocalSettingsRepositoryInterface;
use App\Payday3\Domain\LocalSettings;

/**
 * Database-backed config store for payday3. Single row in the
 * `payday3_settings` table (id=1) holds the whole settings blob
 * as JSON — same canonical shape as the legacy local_config.json.
 *
 * On the first load() against an empty row we transparently import
 * whatever the JSON repository can find on disk and persist it,
 * so existing deployments don't have to reconfigure anything.
 *
 * Schema is auto-created on the first call to load() — that keeps
 * the migration zero-touch for operators. ON DUPLICATE KEY UPDATE
 * makes save() idempotent.
 */
final class DbLocalSettingsRepository implements LocalSettingsRepositoryInterface
{
    private ?LocalSettings $cache  = null;
    private bool           $schema = false;

    public function __construct(
        private readonly Database                   $db,
        private readonly JsonLocalSettingsRepository $jsonFallback,
    ) {}

    public function load(): LocalSettings
    {
        if ($this->cache !== null) return $this->cache;
        $raw = $this->readBlob();
        if ($raw === null) {
            // First boot — try to grab whatever the JSON repository
            // sees on disk so the live deployment doesn't reset.
            $migrated = $this->jsonFallback->readRaw();
            if (is_array($migrated)) {
                $this->writeBlob(LocalSettingsCodec::toCanonicalArray($migrated));
                $raw = $migrated;
            }
        }
        return $this->cache = $raw === null
            ? LocalSettings::defaults()
            : LocalSettingsCodec::fromArray($raw);
    }

    public function save(array $payload): array
    {
        $err = LocalSettingsCodec::validate($payload);
        if ($err !== null) return ['ok' => false, 'error' => $err];

        $canonical = LocalSettingsCodec::toCanonicalArray($payload);
        try {
            $this->writeBlob($canonical);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'DB write failed: ' . $e->getMessage()];
        }
        $this->cache = null;
        return ['ok' => true];
    }

    // ─── internals ──────────────────────────────────────────────

    private function ensureSchema(): void
    {
        if ($this->schema) return;
        $t = $this->db->t('payday3_settings');
        $this->db->getPdo()->exec(
            "CREATE TABLE IF NOT EXISTS {$t} (
                id          TINYINT UNSIGNED NOT NULL DEFAULT 1,
                config_json LONGTEXT NOT NULL,
                updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                  ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $this->schema = true;
    }

    /** @return array<string,mixed>|null  null when the row is missing. */
    private function readBlob(): ?array
    {
        $this->ensureSchema();
        $t = $this->db->t('payday3_settings');
        $raw = $this->db->query("SELECT config_json FROM {$t} WHERE id = 1 LIMIT 1")->fetchColumn();
        if (!is_string($raw) || $raw === '') return null;
        $j = json_decode($raw, true);
        return is_array($j) ? $j : null;
    }

    /** @param array<string,mixed> $canonical */
    private function writeBlob(array $canonical): void
    {
        $this->ensureSchema();
        $t    = $this->db->t('payday3_settings');
        $json = json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('json_encode failed');
        }
        $this->db->query(
            "INSERT INTO {$t} (id, config_json) VALUES (1, ?)
             ON DUPLICATE KEY UPDATE config_json = VALUES(config_json)",
            [$json]
        );
    }
}

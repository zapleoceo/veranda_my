<?php

declare(strict_types=1);

namespace App\Payday3\Services;

use App\Payday3\Contracts\LocalSettingsRepositoryInterface;
use App\Payday3\Domain\LocalSettings;

/**
 * Filesystem-backed config store for payday3.
 *
 *   primary path: payday3/local_config.json (writable by web user)
 *
 * Kept around for compatibility / read-side fallbacks; the active
 * repository is DbLocalSettingsRepository (settings now live in a
 * single row of the `payday3_settings` table).
 */
final class JsonLocalSettingsRepository implements LocalSettingsRepositoryInterface
{
    private ?LocalSettings $cache = null;

    public function __construct(
        private readonly string $primaryPath,   // payday3/local_config.json
    ) {}

    public function load(): LocalSettings
    {
        if ($this->cache !== null) return $this->cache;

        $raw = $this->readJson($this->primaryPath);
        if (!is_array($raw)) {
            return $this->cache = LocalSettings::defaults();
        }
        return $this->cache = LocalSettingsCodec::fromArray($raw);
    }

    public function save(array $payload): array
    {
        $err = LocalSettingsCodec::validate($payload);
        if ($err !== null) return ['ok' => false, 'error' => $err];

        $out = LocalSettingsCodec::toCanonicalArray($payload);
        $json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) return ['ok' => false, 'error' => 'json_encode failed'];

        if (!is_dir(dirname($this->primaryPath))) {
            @mkdir(dirname($this->primaryPath), 0755, true);
        }
        $tmp = $this->primaryPath . '.tmp.' . bin2hex(random_bytes(6));
        if (@file_put_contents($tmp, $json . "\n") === false) {
            return ['ok' => false, 'error' => 'cannot write tmp file (check perms on ' . dirname($this->primaryPath) . ')'];
        }
        if (!@rename($tmp, $this->primaryPath)) {
            @unlink($tmp);
            return ['ok' => false, 'error' => 'cannot rename to ' . basename($this->primaryPath)];
        }
        $this->cache = null;
        return ['ok' => true];
    }

    /** Read raw JSON shape from disk (or null on missing/invalid) — used by the DB repository for one-time migration. */
    public function readRaw(): ?array
    {
        return $this->readJson($this->primaryPath) ?? $this->readJson($this->fallbackPath);
    }

    private function readJson(string $path): ?array
    {
        $s = @file_get_contents($path);
        if (!is_string($s) || $s === '') return null;
        $j = json_decode($s, true);
        return is_array($j) ? $j : null;
    }
}

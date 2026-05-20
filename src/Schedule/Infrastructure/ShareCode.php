<?php

declare(strict_types=1);

namespace App\Schedule\Infrastructure;

use App\Infrastructure\Database;

/**
 * URL-safe random share-code generator + uniqueness check.
 * Used by SnapshotRepository::saveNamedVersion (when minting a new
 * code) and by SchemaManager (when backfilling legacy rows).
 */
final class ShareCode
{
    public static function generateUnique(Database $db, string $tableFq): string
    {
        for ($i = 0; $i < 8; $i++) {
            $code   = rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '=');
            $exists = $db->query("SELECT 1 FROM {$tableFq} WHERE share_code = ? LIMIT 1", [$code])->fetch();
            if (!$exists) return $code;
        }
        throw new \RuntimeException('Could not generate unique share code');
    }
}

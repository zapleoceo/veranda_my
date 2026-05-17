<?php
declare(strict_types=1);

namespace App\Classes;

require_once __DIR__ . '/PosterAPI.php';
require_once __DIR__ . '/MetaRepository.php';

class PosterSpotHallsService {
    /**
     * Accept either the legacy App\Classes\Database (used by tr3/api_booking.php
     * and PosterReservationsService) or the new App\Infrastructure\Database
     * (used by Slim WebhookController actions like Vposter/Vdecline/...).
     * Previously the strict App\Classes\Database hint blew up every reservation
     * button press from the manager chat with a TypeError visible as a popup.
     */
    public static function getHallName(Database|\App\Infrastructure\Database $db, string $posterToken, int $spotId, int $hallId): string {
        if ($spotId <= 0 || $hallId <= 0) return '';
        $halls = self::getHallsMap($db, $posterToken, $spotId);
        return array_key_exists($hallId, $halls) ? (string)$halls[$hallId] : '';
    }

    public static function getHallsMap(Database|\App\Infrastructure\Database $db, string $posterToken, int $spotId, int $ttlSec = 43200): array {
        if ($spotId <= 0) return [];
        $ttlSec = $ttlSec > 0 ? $ttlSec : 43200;
        $key = 'poster_spot_halls_' . (string)$spotId;
        // Pick the MetaRepository that matches the concrete Database type
        // (legacy MetaRepository takes App\Classes\Database; new one takes
        // App\Infrastructure\Database — they have incompatible constructors).
        $meta = $db instanceof \App\Infrastructure\Database
            ? new \App\Repositories\MetaRepository($db)
            : new MetaRepository($db);
        $vals = $meta->getMany([$key]);
        $raw = array_key_exists($key, $vals) ? (string)$vals[$key] : '';
        $now = time();

        $cached = null;
        if ($raw !== '') {
            $cached = json_decode($raw, true);
            if (!is_array($cached)) $cached = null;
        }
        if ($cached && isset($cached['fetched_at'], $cached['halls']) && is_array($cached['halls'])) {
            $fetchedAt = (int)$cached['fetched_at'];
            if ($fetchedAt > 0 && ($now - $fetchedAt) < $ttlSec) {
                $out = [];
                foreach ($cached['halls'] as $k => $v) {
                    $id = (int)$k;
                    $name = trim((string)$v);
                    if ($id > 0 && $name !== '') $out[$id] = $name;
                }
                if ($out) return $out;
            }
        }

        if ($posterToken === '') return [];

        $api = new PosterAPI($posterToken);
        $rows = null;
        try {
            $rows = $api->request('spots.getSpotTablesHalls', ['spot_id' => $spotId], 'GET');
        } catch (\Throwable $e) {
            try {
                $rows = $api->request('spots.getSpotTablesHalls', ['spot_id' => $spotId], 'POST');
            } catch (\Throwable $e2) {
                $rows = null;
            }
        }
        $rows = is_array($rows) ? $rows : [];
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $id = (int)($r['hall_id'] ?? $r['id'] ?? 0);
            $name = trim((string)($r['hall_name'] ?? $r['name'] ?? ''));
            if ($id <= 0 || $name === '') continue;
            $out[$id] = $name;
        }
        if ($out) {
            $meta->set($key, json_encode(['fetched_at' => $now, 'halls' => $out], JSON_UNESCAPED_UNICODE));
        }
        return $out;
    }
}


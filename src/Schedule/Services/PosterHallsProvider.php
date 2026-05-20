<?php

declare(strict_types=1);

namespace App\Schedule\Services;

use App\Classes\PosterAPI;
use App\Schedule\Contracts\HallsProviderInterface;
use App\Schedule\Repositories\MetaCache;

final class PosterHallsProvider implements HallsProviderInterface
{
    private const CACHE_KEY     = 'schedule_poster_halls_v1';
    private const CACHE_SECONDS = 43200;        // 12 часов
    private const SCAN_SPOTS    = [1, 2, 3, 4, 5];

    public function __construct(
        private readonly MetaCache $cache,
        private readonly string $posterToken,
    ) {}

    public function fetch(): array
    {
        $cached = $this->cache->get(self::CACHE_KEY, self::CACHE_SECONDS);
        if (is_array($cached) && !empty($cached)) return $cached;

        if ($this->posterToken !== '') {
            try {
                $halls = $this->fetchFromPoster();
                if (!empty($halls)) {
                    $this->cache->set(self::CACHE_KEY, $halls);
                    return $halls;
                }
            } catch (\Throwable) {
                // fallback below
            }
        }
        return $this->fallback();
    }

    public function purgeCache(): void
    {
        $this->cache->purge([self::CACHE_KEY]);
    }

    private function fetchFromPoster(): array
    {
        $api = new PosterAPI($this->posterToken);
        $halls = [];
        foreach (self::SCAN_SPOTS as $spotId) {
            try {
                $rows = $api->request('spots.getSpotTablesHalls', ['spot_id' => $spotId], 'GET');
            } catch (\Throwable) {
                continue;
            }
            if (!is_array($rows)) continue;
            foreach ($rows as $r) {
                if (!is_array($r)) continue;
                $id   = (int) ($r['hall_id'] ?? $r['id'] ?? 0);
                $name = trim((string) ($r['hall_name'] ?? $r['name'] ?? ''));
                if ($id <= 0 || $name === '' || isset($halls[$id])) continue;
                $halls[$id] = [
                    'id'   => $id,
                    'name' => $name,
                    'icon' => self::guessIcon($name),
                ];
            }
        }
        return array_values($halls);
    }

    private function fallback(): array
    {
        return [
            ['id' => 1, 'name' => 'Главный зал', 'icon' => '🏛'],
            ['id' => 2, 'name' => 'Баня',        'icon' => '♨'],
            ['id' => 3, 'name' => 'Roma',        'icon' => '🌿'],
            ['id' => 4, 'name' => 'Терраса',     'icon' => '🏖'],
            ['id' => 5, 'name' => 'VIP-зал',     'icon' => '🍷'],
        ];
    }

    private static function guessIcon(string $name): string
    {
        $n = mb_strtolower($name, 'UTF-8');
        return match (true) {
            str_contains($n, 'баня') || str_contains($n, 'sauna') => '♨',
            str_contains($n, 'тер') || str_contains($n, 'веран')  => '🏖',
            str_contains($n, 'vip')                                => '🍷',
            str_contains($n, 'беседка') || str_contains($n, 'roma') => '🌿',
            default                                                 => '🏛',
        };
    }
}

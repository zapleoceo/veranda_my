<?php

declare(strict_types=1);

namespace App\Order\Services;

use App\Order\Contracts\PosterLocationProviderInterface;
use App\Order\Domain\Hall;
use App\Order\Domain\Spot;
use App\Order\Domain\TableDef;
use App\Payday3\Contracts\PosterApiProviderInterface;

/**
 * One-shot loader for the whole spot → hall → table tree. We hit
 * spots.getSpotTablesHalls (combined view) and spots.getSpot per
 * unique spot id discovered there. Tables come from a single
 * spots.getTableHallTables per spot (cheaper than per-hall).
 */
final class PosterLocationProvider implements PosterLocationProviderInterface
{
    public function __construct(private readonly PosterApiProviderInterface $poster) {}

    public function fetchAll(): array
    {
        $api = $this->poster->client();
        $rawHalls = $api->request('spots.getSpotTablesHalls', [], 'GET');
        if (!is_array($rawHalls)) $rawHalls = [];

        /** @var array<int, Hall> $halls */
        $halls   = [];
        $spotIds = [];
        foreach ($rawHalls as $h) {
            if (!is_array($h)) continue;
            if ((string)($h['delete'] ?? '0') === '1') continue;
            $hid = (int)($h['hall_id'] ?? 0);
            $sid = (int)($h['spot_id'] ?? 0);
            if ($hid <= 0 || $sid <= 0) continue;
            $halls[]            = new Hall(
                id:     $hid,
                spotId: $sid,
                name:   trim((string)($h['hall_name'] ?? '')),
                sort:   (int)($h['hall_order'] ?? 0),
            );
            $spotIds[$sid] = true;
        }

        // If the spot list is empty (single-spot shops sometimes return
        // an empty halls array), fall back to the configured default.
        if (!$spotIds) {
            $env = (int)($_ENV['POSTER_SPOT_ID'] ?? 1);
            $spotIds[$env > 0 ? $env : 1] = true;
        }

        $spots  = [];
        $tables = [];
        foreach (array_keys($spotIds) as $sid) {
            $spotRaw = $api->request('spots.getSpot', ['spot_id' => $sid], 'GET');
            if (is_array($spotRaw) && isset($spotRaw[0]) && is_array($spotRaw[0])) $spotRaw = $spotRaw[0];
            if (!is_array($spotRaw)) $spotRaw = [];
            $spots[] = new Spot(
                id:       $sid,
                name:     trim((string)($spotRaw['name'] ?? ('Spot ' . $sid))),
                tabletId: (int)($spotRaw['spot_tablet_id'] ?? $spotRaw['tablet_id'] ?? 0),
            );

            $tblRaw = $api->request('spots.getTableHallTables', [
                'spot_id'         => $sid,
                'without_deleted' => 1,
            ], 'GET');
            if (!is_array($tblRaw)) continue;
            foreach ($tblRaw as $t) {
                if (!is_array($t)) continue;
                $tid = (int)($t['table_id'] ?? 0);
                $hid = (int)($t['hall_id']  ?? 0);
                if ($tid <= 0 || $hid <= 0) continue;
                $tables[] = new TableDef(
                    id:     $tid,
                    hallId: $hid,
                    name:   trim((string)($t['table_title'] ?? $t['table_num'] ?? ('Стол ' . $tid))),
                    sort:   (int)($t['table_order'] ?? 0),
                );
            }
        }
        usort($spots,  static fn(Spot $a, Spot $b) => $a->id <=> $b->id);
        usort($halls,  static fn(Hall $a, Hall $b) => ($a->sort <=> $b->sort) ?: ($a->id <=> $b->id));
        usort($tables, static fn(TableDef $a, TableDef $b) => ($a->sort <=> $b->sort) ?: strcmp($a->name, $b->name));

        return ['spots' => $spots, 'halls' => $halls, 'tables' => $tables];
    }
}

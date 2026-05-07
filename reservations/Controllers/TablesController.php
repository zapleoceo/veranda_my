<?php
declare(strict_types=1);

namespace Reservations\Controllers;

use App\Classes\MetaRepository;
use Reservations\Repositories\TableSettingsRepository;
use Reservations\Services\PosterTablesService;

class TablesController {
    private MetaRepository $meta;
    private TableSettingsRepository $repo;
    private PosterTablesService $poster;

    public function __construct(MetaRepository $meta, TableSettingsRepository $repo, PosterTablesService $poster) {
        $this->meta = $meta;
        $this->repo = $repo;
        $this->poster = $poster;
    }

    public function hallData(int $spotId, int $hallId): array {
        $allowedKey = 'reservations_allowed_scheme_nums_hall_' . $hallId;
        $capsKey = 'reservations_table_caps_hall_' . $hallId;
        $vals = $this->meta->getMany([$allowedKey, $capsKey]);

        $allowed = [];
        $rawAllowed = array_key_exists($allowedKey, $vals) ? trim((string)$vals[$allowedKey]) : '';
        if ($rawAllowed !== '') {
            $j = json_decode($rawAllowed, true);
            if (is_array($j)) {
                foreach ($j as $v) {
                    if (!is_numeric($v)) continue;
                    $n = (int)$v;
                    if ($n > 0) $allowed[] = $n;
                }
            }
        }

        $caps = [];
        $rawCaps = array_key_exists($capsKey, $vals) ? trim((string)$vals[$capsKey]) : '';
        if ($rawCaps !== '') {
            $j = json_decode($rawCaps, true);
            if (is_array($j)) {
                foreach ($j as $k => $v) {
                    $ks = trim((string)$k);
                    if ($ks === '' || !preg_match('/^\d+$/', $ks)) continue;
                    $caps[$ks] = is_numeric($v) ? (int)$v : 0;
                }
            }
        }

        $posterTables = $this->poster->getHallTables($spotId, $hallId);
        $this->repo->upsertFromPosterTables($spotId, $hallId, $posterTables, $caps, $allowed);

        $cfgByPosterId = [];
        foreach ($this->repo->getByHall($spotId, $hallId) as $r) {
            $pid = (int)($r['poster_table_id'] ?? 0);
            if ($pid > 0) $cfgByPosterId[$pid] = $r;
        }

        $out = [];
        foreach ($posterTables as $r) {
            if (!is_array($r)) continue;
            $pid = (int)($r['table_id'] ?? 0);
            if ($pid <= 0) continue;
            $cfg = array_key_exists($pid, $cfgByPosterId) ? $cfgByPosterId[$pid] : [];
            $out[] = [
                'table_id' => $pid,
                'table_title' => (string)($r['table_title'] ?? ''),
                'table_num' => (string)($r['table_num'] ?? ''),
                'table_shape' => (string)($r['table_shape'] ?? ''),
                'table_x' => $r['table_x'] ?? null,
                'table_y' => $r['table_y'] ?? null,
                'table_width' => $r['table_width'] ?? null,
                'table_height' => $r['table_height'] ?? null,
                'scheme_num' => array_key_exists('scheme_num', $cfg) ? $cfg['scheme_num'] : null,
                'display_name' => array_key_exists('display_name', $cfg) ? (string)$cfg['display_name'] : '',
                'show_on_canvas' => (int)($cfg['show_on_canvas'] ?? 1),
                'bookable' => (int)($cfg['bookable'] ?? 0),
                'capacity' => (int)($cfg['capacity'] ?? 0),
            ];
        }

        return $out;
    }

    public function updateTable(int $spotId, int $hallId, int $posterTableId, array $payload): void {
        $fields = [];
        if (array_key_exists('scheme_num', $payload)) {
            $v = $payload['scheme_num'];
            $n = ($v === '' || $v === null) ? null : (is_numeric($v) ? (int)$v : null);
            $fields['scheme_num'] = $n;
        }
        if (array_key_exists('display_name', $payload)) {
            $fields['display_name'] = trim((string)$payload['display_name']);
        }
        if (array_key_exists('show_on_canvas', $payload)) {
            $fields['show_on_canvas'] = (int)!!$payload['show_on_canvas'];
        }
        if (array_key_exists('bookable', $payload)) {
            $fields['bookable'] = (int)!!$payload['bookable'];
        }
        if (array_key_exists('capacity', $payload)) {
            $fields['capacity'] = max(0, (int)$payload['capacity']);
        }
        $this->repo->updateByPosterTableId($spotId, $hallId, $posterTableId, $fields);
    }
}

